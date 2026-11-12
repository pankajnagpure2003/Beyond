<?php

namespace Botble\Base\Http\Middleware;

use Botble\Base\Facades\AdminHelper;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class PublicCacheControl
{
    /**
     * Session keys that do not change the rendered body, so their presence alone
     * does not make a response unsafe to share.
     *
     * `_token`, `_previous` and `_flash` are framework bookkeeping. `language` and
     * `previous_language` are written by the language plugin on every request; they
     * are inert only because a non-default locale lives at its own prefixed URL
     * (see localeIsUrlAddressable()), which is asserted separately.
     *
     * Anything else - `success_msg`, `error_msg`, `errors`, plugin state - is assumed
     * to be able to reach the body and blocks public caching.
     */
    public const INERT_SESSION_KEYS = ['_token', '_previous', '_flash', 'language', 'previous_language'];

    /**
     * Markers proving a per-visitor flash message was rendered into the page.
     *
     * `packages/theme::fronts.toast-notification` emits these calls only inside its
     * `session()->has('success_msg') || session()->has('error_msg') || $errors->count()`
     * branch, so their presence means this response carries somebody's flash.
     */
    public const FLASH_OUTPUT_MARKERS = ['Theme.showSuccess(', 'Theme.showError('];

    public function handleRequestHandled(RequestHandled $event): void
    {
        $request = $event->request;
        $response = $event->response;

        if ($reason = $this->publicCacheSkipReason($request, $response)) {
            // Debug-only diagnostics. Skipping is otherwise completely invisible, which
            // has already hidden a real regression: an inlined CSRF token in the shortcode
            // lazy-loading script silently made every page with a lazy block uncacheable.
            if (config('app.debug')) {
                $response->headers->set('X-Public-Cache-Skip', $reason);
            }

            return;
        }

        $maxAge = (int) $this->publicCacheMaxAge();

        $response->headers->set('Cache-Control', sprintf('public, max-age=%d, s-maxage=%d', $maxAge, $maxAge));
        $response->headers->remove('Pragma');

        // Safe now: no CSRF token in the body, so dropping per-visitor cookies
        // lets shared caches store the response without leaking a session.
        $this->removeSessionCookies($response);
    }

    protected function shouldApplyPublicCache(Request $request, mixed $response): bool
    {
        return $this->publicCacheSkipReason($request, $response) === null;
    }

    /**
     * Whether public cache headers are switched on.
     *
     * The admin toggle (Settings -> Cache) wins when present; the config/env value is the
     * default, so existing deployments that set CMS_PUBLIC_CACHE_CONTROL_ENABLED keep
     * working untouched.
     */
    protected function publicCacheEnabled(): bool
    {
        return (bool) setting(
            'enable_public_cache_control',
            config('core.base.general.enable_public_cache_control', false)
        );
    }

    protected function publicCacheMaxAge(): int
    {
        $maxAge = setting(
            'public_cache_max_age',
            config('core.base.general.public_cache_max_age', 600)
        );

        // 0 is a deliberate, supported choice ("cacheable but always revalidate"), so only
        // fall back for values that are absent, empty or negative.
        return is_numeric($maxAge) && (int) $maxAge >= 0 ? (int) $maxAge : 600;
    }

    /**
     * Why this response is not publicly cacheable, or null when it is.
     *
     * Returned as a short slug so it can be surfaced as a debug header - "it silently did
     * nothing" is the hardest kind of caching bug to find.
     */
    protected function publicCacheSkipReason(Request $request, mixed $response): ?string
    {
        if (! $this->publicCacheEnabled()) {
            return 'disabled';
        }

        if (! $request->isMethodSafe()) {
            return 'unsafe-method';
        }

        // Only GET can be judged here. Symfony's Response::prepare() clears the body of a
        // HEAD response (RFC 9110) inside the routing pipeline, long before RequestHandled
        // fires, so responseContainsFlashMessages() and responseContainsCsrfTokens() below
        // would both inspect an empty string and wave the response through: the very same
        // URL would come back "private" as GET and "public" as HEAD, with its session
        // cookies stripped. An intermediary may use that HEAD to refresh the metadata of
        // the stored GET entry, so the cheapest correct answer is to never advertise a
        // non-GET response as publicly cacheable.
        if (! $request->isMethod('GET')) {
            return 'non-get-method';
        }

        if (! $response->isSuccessful()) {
            return 'unsuccessful-status';
        }

        if (AdminHelper::isInAdmin()) {
            return 'admin';
        }

        if ($reason = $this->authenticationSkipReason()) {
            return $reason;
        }

        if ($request->expectsJson()) {
            return 'json';
        }

        // Cookies only matter when the body actually depends on them. Bailing on *any*
        // cookie used to be necessary because the cookie-consent plugin rendered two
        // different pages (banner shown / banner omitted, consent categories inlined in
        // the head); that is now decided client-side, so those cookies no longer change
        // a byte. Analytics cookies (`_ga` and friends) never did.
        //
        // What still personalises a response is session STATE, not the session cookie:
        // flash messages and the validation error bag are printed straight into the page
        // by the frontend toast partial. So gate on what the session holds, not on
        // whether a cookie was sent.
        if ($this->sessionCarriesPersonalisedState($request)) {
            return 'session-state';
        }

        // Flash messages CANNOT be detected from the session here. StartSession ages the
        // flash bag and saves the session before the response is returned, so by the time
        // RequestHandled fires a flash that was consumed while rendering has already been
        // forgotten - the session gate above sees a clean session and waves the response
        // through, while the page itself is displaying one visitor's "your message has
        // been sent". The rendered body is the only surviving evidence, so check that,
        // exactly as responseContainsCsrfTokens() does.
        if ($this->responseContainsFlashMessages($response)) {
            return 'flash-message';
        }

        // Escape hatch for the case the session gate cannot see: a theme or plugin that
        // renders straight from a raw cookie (a currency switcher, an A/B bucket). The
        // old blanket cookie bail covered those by accident; this keeps a way to opt
        // back out without making every cookie cost cacheability again.
        if ($cookie = $this->personalisingRequestCookie($request)) {
            return 'request-cookie:' . $cookie;
        }

        // Locale must be addressable from the URL alone, otherwise the same URL can
        // render different languages for different visitors.
        if (! $this->localeIsUrlAddressable()) {
            return 'locale-not-url-addressable';
        }

        // A response is only safe for a shared/public cache when it carries no
        // per-visitor CSRF token. Botble bakes the session CSRF token into HTML
        // (hidden `_token` fields, `<meta name="csrf-token">`, or inline JS
        // headers); caching such a page would serve one visitor's token to
        // everyone and trigger 419 "page expired" errors on their next form or
        // AJAX submit. In that case leave the response fully dynamic instead of
        // advertising `public` and relying on Set-Cookie to deter caching — a
        // proxy told to ignore Set-Cookie would happily cache (and leak) it.
        if ($this->responseContainsCsrfTokens($response)) {
            return 'csrf-token';
        }

        return null;
    }

    /**
     * Why the visitor's authentication state blocks public caching, or null when it does not.
     *
     * `Auth::guard()` consults only the DEFAULT guard (`web` = admin users), but plugins
     * register their own guards at runtime - the member plugin adds `member` - and themes
     * render the logged-in member's name and avatar straight into the page. Checking the
     * default guard alone would therefore mark a personalised page publicly cacheable and
     * publish one member's identity to every visitor.
     */
    protected function authenticationSkipReason(): ?string
    {
        foreach (array_keys((array) config('auth.guards', [])) as $guard) {
            try {
                if (Auth::guard($guard)->check()) {
                    return 'authenticated';
                }
            } catch (Throwable) {
                // A guard can be unusable here (misconfigured, or its driver is not booted
                // for this request). We cannot prove the visitor is anonymous, so assume
                // they are not: erring toward "do not cache" costs a cache miss, while
                // erring the other way can publish one visitor's page to everyone.
                return 'auth-guard-unavailable';
            }
        }

        return null;
    }

    /**
     * Whether the session holds anything that can reach the rendered body.
     *
     * Flash messages and the validation error bag are printed into the page by
     * `packages/theme::fronts.toast-notification`, so a response rendered with them in
     * scope must never be shared, even though the visitor is anonymous.
     */
    protected function sessionCarriesPersonalisedState(Request $request): bool
    {
        // Deliberately no isStarted() check: StartSession saves and closes the session
        // before the response is returned, which flips Store::$started to false. By the
        // time RequestHandled fires it is ALWAYS false, so testing it here silently
        // disabled this whole gate on every real request - while unit tests that build a
        // Store by hand and never save it kept passing. The attributes survive the save,
        // so all() below still sees exactly what the page was rendered with.
        if (! $request->hasSession()) {
            return false;
        }

        $session = $request->session();

        // A listener that forgets to return its value would otherwise hand back null and
        // silently switch public caching off site-wide, which is exactly the kind of
        // invisible failure the X-Public-Cache-Skip header exists to prevent.
        $inertKeys = apply_filters('cms_public_cache_inert_session_keys', self::INERT_SESSION_KEYS);
        $inertKeys = is_array($inertKeys) ? $inertKeys : self::INERT_SESSION_KEYS;

        if (array_diff(array_keys($session->all()), $inertKeys) !== []) {
            return true;
        }

        // Flash keys live under `_flash` (an inert key above) but their PAYLOAD is what
        // gets printed, so check the bag itself rather than trusting the key list.
        return ! empty($session->get('_flash.old', [])) || ! empty($session->get('_flash.new', []));
    }

    /**
     * Whether the response body shows a per-visitor flash message.
     *
     * Themes that render flash by some other means can register their own markers:
     *
     *     add_filter('cms_public_cache_flash_output_markers', fn ($m) => [...$m, 'my-toast']);
     */
    protected function responseContainsFlashMessages(mixed $response): bool
    {
        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return false;
        }

        $markers = apply_filters('cms_public_cache_flash_output_markers', self::FLASH_OUTPUT_MARKERS);
        $markers = is_array($markers) ? $markers : self::FLASH_OUTPUT_MARKERS;

        foreach ($markers as $marker) {
            if (is_string($marker) && $marker !== '' && str_contains($content, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first request cookie a theme or plugin has declared as personalising, if any.
     *
     * Empty by default: the cookies that used to matter (consent) are now handled
     * client-side, and session state is covered by its own gate. Extensions that render
     * from a raw cookie register it here:
     *
     *     add_filter('cms_public_cache_personalising_cookies', fn ($cookies) => [...$cookies, 'currency']);
     */
    protected function personalisingRequestCookie(Request $request): ?string
    {
        $cookies = apply_filters('cms_public_cache_personalising_cookies', []);

        if (! is_array($cookies)) {
            return null;
        }

        foreach ($cookies as $cookie) {
            if (is_string($cookie) && $cookie !== '' && $request->cookies->has($cookie)) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * Whether the active locale is fully determined by the URL.
     *
     * The language plugin keeps the current locale in the SESSION and calls
     * `App::setLocale()` from it. That is safe for shared caching only while a
     * non-default locale is redirected to its own prefixed URL, and while the locale is
     * not negotiated from `Accept-Language` (a header no shared cache varies on here).
     * Both are settings an operator can flip, so assert them rather than assume them.
     *
     * Defaults match a site without the language plugin installed: nothing to vary on.
     */
    protected function localeIsUrlAddressable(): bool
    {
        return (bool) setting('language_hide_default', true)
            && ! setting('language_auto_detect_user_language', false);
    }

    protected function responseContainsCsrfTokens(mixed $response): bool
    {
        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return false;
        }

        // Quote-insensitive on purpose. The optimize package's RemoveQuotes middleware
        // (Settings -> Optimize page speed) rewrites `name="_token"` to `name=_token`, so
        // matching the quoted literal missed the CSRF token on every form of every
        // minified page - which would have published one visitor's token to everyone and
        // broken their submits with 419. Matching too eagerly only costs cacheability;
        // matching too narrowly breaks forms, so this errs toward not caching.
        return preg_match('/\bname\s*=\s*["\']?_token\b/i', $content) === 1
            // <meta name="csrf-token">, X-CSRF-TOKEN headers, csrf_token() in inline JS.
            || preg_match('/csrf[-_]token/i', $content) === 1;
    }

    protected function removeSessionCookies(mixed $response): void
    {
        // A publicly cacheable response must not carry ANY per-visitor Set-Cookie
        // (session, XSRF, ecommerce footprints, cookie-consent, etc.); otherwise
        // reverse proxies / CDNs refuse to store it. This runs only for safe,
        // non-admin, non-authenticated responses with no CSRF tokens (see caller),
        // so dropping all cookies is safe and lets the response be cached.
        $response->headers->remove('Set-Cookie');

        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
        }
    }
}
