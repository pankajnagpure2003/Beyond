<?php

namespace Botble\Base\Tests\Feature;

use Botble\ACL\Models\User;
use Botble\ACL\Services\ActivateUserService;
use Botble\Base\Http\Middleware\PublicCacheControl;
use Botble\Base\Supports\BaseTestCase;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Cookie;

class PublicCacheControlTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['core.base.general.enable_public_cache_control' => false]);
        config(['core.base.general.public_cache_max_age' => 600]);
    }

    protected function invokeHandler(Request $request, Response $response): void
    {
        $handler = new PublicCacheControl();
        $handler->handleRequestHandled(new RequestHandled($request, $response));
    }

    protected function attachSession(Request $request, array $data = []): Request
    {
        $session = new Store('botble_session', new ArraySessionHandler(120));
        $session->start();

        foreach ($data as $key => $value) {
            $session->put($key, $value);
        }

        $request->setLaravelSession($session);

        return $request;
    }

    protected function makeUser(string $email = 'test@example.com', string $username = 'testuser'): User
    {
        Schema::disableForeignKeyConstraints();
        User::query()->truncate();

        $user = new User();
        $user->forceFill([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $email,
            'username' => $username,
            'password' => bcrypt('password'),
        ]);
        $user->save();

        app(ActivateUserService::class)->activate($user);

        return $user;
    }

    public function test_disabled_by_default_does_not_apply_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => false]);

        $request = Request::create('/', 'GET');
        $response = new Response('Test content', 200, [
            'Cache-Control' => 'private, max-age=0',
        ]);

        $this->invokeHandler($request, $response);

        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=0', $response->headers->get('Cache-Control'));
    }

    public function test_enabled_anonymous_get_request_applies_public_cache(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        $request = Request::create('/', 'GET');
        $response = new Response('Test content');

        $this->invokeHandler($request, $response);

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=600', $cacheControl);
        $this->assertStringContainsString('s-maxage=600', $cacheControl);
    }

    public function test_custom_max_age_config_is_respected(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);
        config(['core.base.general.public_cache_max_age' => 3600]);

        $request = Request::create('/', 'GET');
        $response = new Response('Test content');

        $this->invokeHandler($request, $response);

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=3600', $cacheControl);
        $this->assertStringContainsString('s-maxage=3600', $cacheControl);
    }

    public function test_post_request_skips_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        $request = Request::create('/', 'POST');
        $response = new Response('Test content', 200, ['Cache-Control' => 'private']);

        $this->invokeHandler($request, $response);

        $this->assertEquals('private', $response->headers->get('Cache-Control'));
    }

    public function test_put_request_skips_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        $request = Request::create('/', 'PUT');
        $response = new Response('Test content', 200, ['Cache-Control' => 'private']);

        $this->invokeHandler($request, $response);

        $this->assertEquals('private', $response->headers->get('Cache-Control'));
    }

    public function test_delete_request_skips_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        $request = Request::create('/', 'DELETE');
        $response = new Response('Test content', 200, ['Cache-Control' => 'private']);

        $this->invokeHandler($request, $response);

        $this->assertEquals('private', $response->headers->get('Cache-Control'));
    }

    /**
     * A HEAD response has already had its body cleared by Response::prepare() by the time
     * RequestHandled fires, so the flash-message and CSRF-token gates cannot see anything
     * and would pass a page the equivalent GET refuses to cache. HEAD must never be
     * advertised as publicly cacheable, or the same URL is public or private depending on
     * the request method - and a shared cache may reuse that HEAD to refresh the stored
     * GET entry.
     */
    public function test_head_request_does_not_apply_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => true, 'app.debug' => true]);

        $request = Request::create('/', 'HEAD');
        $response = new Response('');

        $this->invokeHandler($request, $response);

        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertEquals('non-get-method', $response->headers->get('X-Public-Cache-Skip'));
    }

    public function test_head_request_keeps_session_cookies(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        $request = Request::create('/', 'HEAD');
        $response = new Response('');
        $response->headers->setCookie(cookie('laravel_session', 'value'));

        $this->invokeHandler($request, $response);

        $this->assertNotEmpty($response->headers->getCookies());
    }

    public function test_authenticated_user_skips_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        Schema::disableForeignKeyConstraints();
        User::query()->truncate();

        $user = new User();
        $user->forceFill([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'username' => 'testuser',
            'password' => bcrypt('password'),
        ]);
        $user->save();

        app(ActivateUserService::class)->activate($user);
        Auth::login($user);

        $request = Request::create('/', 'GET');
        $response = new Response('Test content', 200, ['Cache-Control' => 'private']);

        $this->invokeHandler($request, $response);

        $this->assertEquals('private', $response->headers->get('Cache-Control'));

        Auth::logout();
    }

    public function test_json_request_skips_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response = new Response(json_encode(['data' => 'test']), 200, [
            'Cache-Control' => 'private',
            'Content-Type' => 'application/json',
        ]);

        $this->invokeHandler($request, $response);

        $this->assertEquals('private', $response->headers->get('Cache-Control'));
    }

    public function test_pragma_header_is_removed_when_cache_applied(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        $request = Request::create('/', 'GET');
        $response = new Response('Test content', 200, ['Pragma' => 'no-cache']);

        $this->invokeHandler($request, $response);

        $this->assertNull($response->headers->get('Pragma'));
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
    }

    public function test_pragma_header_preserved_when_cache_not_applied(): void
    {
        config(['core.base.general.enable_public_cache_control' => false]);

        $request = Request::create('/', 'GET');
        $response = new Response('Test content', 200, ['Pragma' => 'no-cache']);

        $this->invokeHandler($request, $response);

        $this->assertEquals('no-cache', $response->headers->get('Pragma'));
    }

    public function test_session_cookies_removed_when_cache_applied(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);
        config(['session.cookie' => 'botble_session']);

        $request = Request::create('/', 'GET');
        $response = new Response('Test content');
        $response->headers->setCookie(new Cookie('XSRF-TOKEN', 'token123'));
        $response->headers->setCookie(new Cookie('botble_session', 'session123'));

        $this->invokeHandler($request, $response);

        $cookieNames = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());
        $this->assertNotContains('XSRF-TOKEN', $cookieNames);
        $this->assertNotContains('botble_session', $cookieNames);
    }

    public function test_all_cookies_removed_when_cache_applied(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);
        config(['session.cookie' => 'botble_session']);

        $request = Request::create('/', 'GET');
        $response = new Response('Test content');
        $response->headers->setCookie(new Cookie('XSRF-TOKEN', 'token123'));
        $response->headers->setCookie(new Cookie('botble_session', 'session123'));
        $response->headers->setCookie(new Cookie('cookie_consent', 'accepted'));

        $this->invokeHandler($request, $response);

        // A publicly cacheable response must carry NO per-visitor Set-Cookie at
        // all (session, XSRF, consent, footprints) — otherwise a shared cache
        // would replay one visitor's cookies to everyone.
        $this->assertEmpty($response->headers->getCookies());
    }

    public function test_session_cookies_preserved_when_cache_not_applied(): void
    {
        config(['core.base.general.enable_public_cache_control' => false]);
        config(['session.cookie' => 'botble_session']);

        $request = Request::create('/', 'GET');
        $response = new Response('Test content');
        $response->headers->setCookie(new Cookie('XSRF-TOKEN', 'token123'));
        $response->headers->setCookie(new Cookie('botble_session', 'session123'));

        $this->invokeHandler($request, $response);

        $cookieNames = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());
        $this->assertContains('XSRF-TOKEN', $cookieNames);
        $this->assertContains('botble_session', $cookieNames);
    }

    public function test_error_responses_skip_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        $request = Request::create('/', 'GET');

        foreach ([404, 500, 403, 503] as $statusCode) {
            $response = new Response('Error', $statusCode, ['Cache-Control' => 'no-cache, private']);

            $this->invokeHandler($request, $response);

            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'), "Status $statusCode should not get public cache");
        }
    }

    public function test_redirect_responses_skip_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        $request = Request::create('/', 'GET');
        $response = new Response('', 302, ['Cache-Control' => 'no-cache, private', 'Location' => '/login']);

        $this->invokeHandler($request, $response);

        $this->assertStringNotContainsString('public', $response->headers->get('Cache-Control'));
    }

    public function test_zero_max_age_is_allowed(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);
        config(['core.base.general.public_cache_max_age' => 0]);

        $request = Request::create('/', 'GET');
        $response = new Response('Test content');

        $this->invokeHandler($request, $response);

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $this->assertStringContainsString('s-maxage=0', $cacheControl);
    }

    public function test_string_max_age_config_converted_to_int(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);
        config(['core.base.general.public_cache_max_age' => '1800']);

        $request = Request::create('/', 'GET');
        $response = new Response('Test content');

        $this->invokeHandler($request, $response);

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=1800', $cacheControl);
        $this->assertStringContainsString('s-maxage=1800', $cacheControl);
    }

    public function test_csrf_form_response_is_not_marked_public_and_keeps_cookies(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);
        config(['session.cookie' => 'botble_session']);

        $html = '<html><body><form><input type="hidden" name="_token" value="abc123"></form></body></html>';
        $request = Request::create('/', 'GET');
        $response = new Response($html);
        $response->headers->setCookie(new Cookie('XSRF-TOKEN', 'token123'));
        $response->headers->setCookie(new Cookie('botble_session', 'session123'));

        $this->invokeHandler($request, $response);

        // A page carrying a per-session CSRF token must stay dynamic: never
        // advertised as public and its session cookies left intact.
        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));

        $cookieNames = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());
        $this->assertContains('XSRF-TOKEN', $cookieNames);
        $this->assertContains('botble_session', $cookieNames);
    }

    public function test_csrf_meta_tag_response_is_not_marked_public_and_keeps_cookies(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);
        config(['session.cookie' => 'botble_session']);

        // Standard Botble AJAX-token pattern that the previous detection missed.
        $html = '<html><head><meta name="csrf-token" content="abc123"></head><body>Shop</body></html>';
        $request = Request::create('/', 'GET');
        $response = new Response($html);
        $response->headers->setCookie(new Cookie('XSRF-TOKEN', 'token123'));
        $response->headers->setCookie(new Cookie('botble_session', 'session123'));

        $this->invokeHandler($request, $response);

        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));

        $cookieNames = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());
        $this->assertContains('XSRF-TOKEN', $cookieNames);
        $this->assertContains('botble_session', $cookieNames);
    }

    public function test_session_cookies_preserved_when_response_contains_csrf_ajax(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);
        config(['session.cookie' => 'botble_session']);

        $html = '<html><body><script>fetch(url, {headers: {"X-CSRF-TOKEN": token}})</script></body></html>';
        $request = Request::create('/', 'GET');
        $response = new Response($html);
        $response->headers->setCookie(new Cookie('XSRF-TOKEN', 'token123'));
        $response->headers->setCookie(new Cookie('botble_session', 'session123'));

        $this->invokeHandler($request, $response);

        $cookieNames = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());
        $this->assertContains('XSRF-TOKEN', $cookieNames);
        $this->assertContains('botble_session', $cookieNames);
    }

    public function test_session_cookies_removed_when_no_csrf_in_response(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);
        config(['session.cookie' => 'botble_session']);

        $html = '<html><body><h1>Static content page</h1></body></html>';
        $request = Request::create('/', 'GET');
        $response = new Response($html);
        $response->headers->setCookie(new Cookie('XSRF-TOKEN', 'token123'));
        $response->headers->setCookie(new Cookie('botble_session', 'session123'));

        $this->invokeHandler($request, $response);

        $cookieNames = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());
        $this->assertNotContains('XSRF-TOKEN', $cookieNames);
        $this->assertNotContains('botble_session', $cookieNames);
    }

    // --- Cookies on the REQUEST -------------------------------------------------
    //
    // Characterization + the behaviour change. Before this suite existed, nothing
    // covered the request-cookie gate at all, so narrowing it turned no test red.

    public function test_analytics_and_consent_cookies_do_not_block_public_cache(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        $request = Request::create('/', 'GET');
        $request->cookies->set('_ga', 'GA1.1.123.456');
        $request->cookies->set('cookie_for_consent', '{"analytics":true}');

        $response = new Response('Test content', 200, ['Cache-Control' => 'private']);

        $this->invokeHandler($request, $response);

        // The cookie-consent plugin now decides banner + consent state client-side, so
        // these cookies change nothing in the body and must not cost cacheability.
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
    }

    public function test_session_cookie_alone_does_not_block_public_cache(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);
        config(['session.cookie' => 'botble_session']);

        $request = Request::create('/', 'GET');
        $request->cookies->set('botble_session', 'abc123');
        $this->attachSession($request);

        $response = new Response('Test content', 200, ['Cache-Control' => 'private']);

        $this->invokeHandler($request, $response);

        // An anonymous visitor who merely HAS a session still gets a URL-pure page.
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
    }

    // --- Session STATE, which is what actually personalises a response -----------

    public function test_flash_message_in_session_skips_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        $request = Request::create('/', 'GET');
        $this->attachSession($request, ['success_msg' => 'Your message has been sent']);

        $response = new Response('Test content', 200, ['Cache-Control' => 'private']);

        $this->invokeHandler($request, $response);

        // packages/theme::fronts.toast-notification prints session('success_msg') into
        // the page; sharing that would broadcast one visitor's flash to everyone.
        $this->assertEquals('private', $response->headers->get('Cache-Control'));
    }

    public function test_validation_errors_in_session_skip_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        $request = Request::create('/', 'GET');
        $this->attachSession($request, ['errors' => ['email' => ['Invalid']]]);

        $response = new Response('Test content', 200, ['Cache-Control' => 'private']);

        $this->invokeHandler($request, $response);

        $this->assertEquals('private', $response->headers->get('Cache-Control'));
    }

    public function test_flash_bag_payload_skips_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        $request = Request::create('/', 'GET');
        $this->attachSession($request);
        $request->session()->put('_flash.old', ['success_msg']);

        $response = new Response('Test content', 200, ['Cache-Control' => 'private']);

        $this->invokeHandler($request, $response);

        $this->assertEquals('private', $response->headers->get('Cache-Control'));
    }

    public function test_language_session_keys_remain_inert(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        $request = Request::create('/', 'GET');
        $this->attachSession($request, ['language' => 'en', 'previous_language' => 'vi']);

        $response = new Response('Test content', 200, ['Cache-Control' => 'private']);

        $this->invokeHandler($request, $response);

        // The language plugin writes these on every request; a non-default locale lives
        // at its own prefixed URL, so they do not make the body vary per visitor.
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
    }

    public function test_declared_personalising_cookie_skips_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);
        config(['app.debug' => true]);

        add_filter('cms_public_cache_personalising_cookies', fn ($cookies) => [...$cookies, 'currency']);

        $request = Request::create('/', 'GET');
        $request->cookies->set('currency', 'EUR');

        $response = new Response('Prices in EUR', 200, ['Cache-Control' => 'private']);

        $this->invokeHandler($request, $response);

        // Escape hatch for themes that render straight from a raw cookie; the old blanket
        // cookie bail covered these by accident.
        $this->assertEquals('private', $response->headers->get('Cache-Control'));
        $this->assertEquals('request-cookie:currency', $response->headers->get('X-Public-Cache-Skip'));
    }

    public function test_undeclared_cookie_does_not_skip_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        $request = Request::create('/', 'GET');
        $request->cookies->set('some_unrelated_cookie', 'value');

        $response = new Response('Test content', 200, ['Cache-Control' => 'private']);

        $this->invokeHandler($request, $response);

        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
    }

    public function test_broken_inert_session_keys_filter_does_not_disable_caching(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        // A listener that forgets to return would hand back null; falling through to an
        // empty inert list would silently switch public caching off for every session.
        add_filter('cms_public_cache_inert_session_keys', fn () => null);

        $request = Request::create('/', 'GET');
        $this->attachSession($request);

        $response = new Response('Test content', 200, ['Cache-Control' => 'private']);

        $this->invokeHandler($request, $response);

        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
    }

    // --- Authentication across ALL guards, not just the default one -------------

    public function test_member_guard_authentication_skips_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);
        config(['auth.guards.member' => ['driver' => 'session', 'provider' => 'users']]);
        config(['app.debug' => true]);

        $user = $this->makeUser('member@example.com', 'memberuser');
        Auth::guard('member')->login($user);

        $this->assertTrue(Auth::guard('member')->check(), 'member guard login did not take effect');
        $this->assertFalse(Auth::guard()->check(), 'default guard must stay anonymous or this test proves nothing');

        $request = Request::create('/', 'GET');
        $response = new Response('Hello Test User', 200, ['Cache-Control' => 'private']);

        $this->invokeHandler($request, $response);

        // Regression guard: Auth::guard() checks only the DEFAULT guard, so a logged-in
        // member used to be invisible here while the theme rendered their name+avatar.
        $this->assertEquals('private', $response->headers->get('Cache-Control'));
        // Assert WHY it was skipped - otherwise this passes for any unrelated bail.
        $this->assertEquals('authenticated', $response->headers->get('X-Public-Cache-Skip'));

        Auth::guard('member')->logout();
    }

    public function test_anonymous_on_all_guards_still_caches(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);
        config(['auth.guards.member' => ['driver' => 'session', 'provider' => 'users']]);

        $request = Request::create('/', 'GET');
        $response = new Response('Test content', 200, ['Cache-Control' => 'private']);

        $this->invokeHandler($request, $response);

        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
    }

    // --- Flash messages, detected from the rendered body ------------------------
    //
    // These cannot be detected from the session: StartSession ages the flash bag and
    // saves the session before the response is returned, so a flash consumed while
    // rendering is already forgotten by the time RequestHandled fires. Verified against
    // a real flash through the kernel: the session arrives clean, while the body carries
    // Theme.showSuccess(...) - which is what the old blanket cookie rule used to catch.

    public function test_rendered_success_toast_skips_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);
        config(['app.debug' => true]);

        $request = Request::create('/', 'GET');
        $response = new Response(
            '<html><body><script>Theme.showSuccess("Your message has been sent");</script></body></html>',
            200,
            ['Cache-Control' => 'private']
        );

        $this->invokeHandler($request, $response);

        $this->assertEquals('private', $response->headers->get('Cache-Control'));
        $this->assertEquals('flash-message', $response->headers->get('X-Public-Cache-Skip'));
    }

    public function test_rendered_error_toast_skips_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        $request = Request::create('/', 'GET');
        $response = new Response(
            '<html><body><script>Theme.showError("Something went wrong");</script></body></html>',
            200,
            ['Cache-Control' => 'private']
        );

        $this->invokeHandler($request, $response);

        $this->assertEquals('private', $response->headers->get('Cache-Control'));
    }

    public function test_page_without_a_toast_is_still_publicly_cached(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);

        $request = Request::create('/', 'GET');
        $response = new Response(
            '<html><body><script src="/toast.js"></script><p>No flash here.</p></body></html>',
            200,
            ['Cache-Control' => 'private']
        );

        $this->invokeHandler($request, $response);

        // Loading toast.js is not evidence of a flash; only the show* calls are.
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
    }

    // --- Locale must be addressable from the URL --------------------------------

    public function test_hidden_default_locale_disabled_skips_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);
        setting()->set('language_hide_default', false)->save();

        $request = Request::create('/', 'GET');
        $response = new Response('Test content', 200, ['Cache-Control' => 'private']);

        $this->invokeHandler($request, $response);

        // With this off, a session locale renders at the locale-less URL, so the same
        // URL can serve different languages.
        $this->assertEquals('private', $response->headers->get('Cache-Control'));

        setting()->set('language_hide_default', true)->save();
    }

    public function test_accept_language_negotiation_skips_cache_control(): void
    {
        config(['core.base.general.enable_public_cache_control' => true]);
        setting()->set('language_auto_detect_user_language', true)->save();

        $request = Request::create('/', 'GET');
        $response = new Response('Test content', 200, ['Cache-Control' => 'private']);

        $this->invokeHandler($request, $response);

        // Locale negotiated from a header no shared cache varies on here.
        $this->assertEquals('private', $response->headers->get('Cache-Control'));

        setting()->set('language_auto_detect_user_language', false)->save();
    }
}
