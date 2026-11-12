<?php

namespace Tests\Feature\Base;

use Botble\Base\Http\Middleware\PublicCacheControl;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * Carrying a cookie is not, by itself, a reason to refuse public caching.
 *
 * This file used to assert the opposite: any cookie at all bypassed the cache. That rule
 * existed because the cookie-consent plugin rendered two different pages - it omitted the
 * banner for visitors who had already consented, and wrote their stored consent categories
 * into the head. Caching either variant would have handed one visitor's consent state to
 * everybody else, or left a later visitor with no prompt at all.
 *
 * The plugin now resolves all of that in the browser from `document.cookie`, so the markup
 * is identical for every visitor and those cookies no longer change a byte. The blanket
 * rule was therefore costing every returning visitor a full origin round trip to protect
 * against a variance that no longer exists.
 *
 * What genuinely personalises a response is session STATE (flash messages, the validation
 * error bag - both printed into the page by the frontend toast partial), authentication on
 * any guard, and a CSRF token in the body. Those are gated individually and covered in
 * Botble\Base\Tests\Feature\PublicCacheControlTest.
 */
class PublicCacheCookieBypassTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['core.base.general.enable_public_cache_control' => true]);
        config(['core.base.general.public_cache_max_age' => 600]);
    }

    private function cacheControlFor(Request $request, ?Response $response = null): string
    {
        $response ??= new Response('<html><body><p>No token here.</p></body></html>');

        (new PublicCacheControl())->handleRequestHandled(new RequestHandled($request, $response));

        return (string) $response->headers->get('Cache-Control');
    }

    public function test_cookieless_request_is_publicly_cached(): void
    {
        $this->assertStringContainsString(
            'public',
            $this->cacheControlFor(Request::create('/', 'GET')),
            'A first-time visitor sending no cookies is exactly what should be cached.'
        );
    }

    public function test_consent_cookie_no_longer_blocks_public_caching(): void
    {
        $request = Request::create('/', 'GET', [], ['cookie_for_consent' => '{"analytics":true}']);

        // The consent-dependent variant this used to guard against no longer exists: the
        // banner markup is always rendered (hidden by CSS) and Consent Mode is updated
        // client-side, so a consented and a non-consented visitor receive the same bytes.
        $this->assertStringContainsString('public', $this->cacheControlFor($request));
    }

    public function test_session_cookie_alone_does_not_block_public_caching(): void
    {
        $request = Request::create('/', 'GET', [], ['botble_session' => 'abc123']);

        // Merely HAVING a session does not personalise a page. What the session CONTAINS
        // does, and that is gated separately - see PublicCacheControlTest.
        $this->assertStringContainsString('public', $this->cacheControlFor($request));
    }

    public function test_unrelated_cookie_does_not_block_public_caching(): void
    {
        $request = Request::create('/', 'GET', [], ['some_third_party_cookie' => '1']);

        // Analytics and other third-party cookies are written by scripts in the browser
        // and were never read while rendering the page.
        $this->assertStringContainsString('public', $this->cacheControlFor($request));
    }

    public function test_cookie_declared_as_personalising_still_bypasses_the_cache(): void
    {
        add_filter('cms_public_cache_personalising_cookies', fn ($cookies) => [...$cookies, 'currency']);

        $request = Request::create('/', 'GET', [], ['currency' => 'EUR']);

        // Extensions that really do render from a raw cookie keep a way to opt out; the
        // old blanket rule covered them by accident.
        $this->assertStringNotContainsString('public', $this->cacheControlFor($request));
    }

    public function test_response_that_is_not_publicly_cached_keeps_its_cookies(): void
    {
        $request = Request::create('/', 'GET', [], ['botble_session' => 'abc123']);

        // A CSRF token in the body keeps this response out of shared caches, so its
        // Set-Cookie headers must survive untouched.
        $response = new Response('<html><body><form><input name="_token" value="x"></form></body></html>');
        $response->headers->setCookie(new Cookie('botble_session', 'abc123'));

        (new PublicCacheControl())->handleRequestHandled(new RequestHandled($request, $response));

        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertNotEmpty(
            $response->headers->getCookies(),
            'Cookies must survive on responses that are not being publicly cached.'
        );
    }

    public function test_publicly_cached_response_never_carries_cookies(): void
    {
        $request = Request::create('/', 'GET', [], ['botble_session' => 'abc123']);

        $response = new Response('<html><body>ok</body></html>');
        $response->headers->setCookie(new Cookie('botble_session', 'abc123'));

        $this->assertStringContainsString('public', $this->cacheControlFor($request, $response));
        $this->assertEmpty(
            $response->headers->getCookies(),
            'A shared cache would replay these cookies to every subsequent visitor.'
        );
    }
}
