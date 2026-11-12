<?php

namespace Botble\CookieConsent\Tests\Feature;

use Botble\CookieConsent\Providers\CookieConsentServiceProvider;
use Tests\TestCase;

/**
 * The consent UI must be identical for every visitor.
 *
 * Public pages are shared between visitors by caches, so anything this plugin renders has
 * to be a pure function of the URL. It used to omit the banner server-side for visitors
 * who had already consented and inline their stored categories into the head, which made
 * every cookie-bearing request uncacheable. Both decisions now happen in the browser.
 *
 * These tests pin that down: if someone reintroduces a server-side read of the consent
 * cookie, the rendered output starts varying and these fail.
 */
class CookieConsentCacheabilityTest extends TestCase
{
    protected function provider(): CookieConsentServiceProvider
    {
        return new CookieConsentServiceProvider($this->app);
    }

    protected function cookieName(): string
    {
        return config('plugins.cookie-consent.general.cookie_name', 'cookie_for_consent');
    }

    public function test_banner_is_rendered_identically_with_and_without_a_consent_cookie(): void
    {
        $provider = $this->provider();

        $withoutCookie = $provider->registerCookieConsent('');

        $_COOKIE[$this->cookieName()] = '{"analytics":true,"marketing":true}';
        request()->cookies->set($this->cookieName(), '{"analytics":true,"marketing":true}');

        $withCookie = $provider->registerCookieConsent('');

        unset($_COOKIE[$this->cookieName()]);
        request()->cookies->remove($this->cookieName());

        $this->assertNotSame('', trim($withoutCookie), 'The banner must be rendered for a first-time visitor.');
        $this->assertSame(
            $withCookie,
            $withoutCookie,
            'The banner markup must not depend on the consent cookie, or the page cannot be shared.'
        );
    }

    public function test_banner_markup_is_present_so_a_cached_page_can_still_prompt(): void
    {
        $html = $this->provider()->registerCookieConsent('');

        // The concern the old blanket cookie-bypass rule guarded against: a visitor served
        // a cached page must still be able to see the prompt. The markup ships on every
        // page and JS reveals it when the cookie is absent.
        $this->assertStringContainsString('js-site-notice', $html);
    }

    public function test_head_scripts_are_rendered_identically_with_and_without_a_consent_cookie(): void
    {
        $provider = $this->provider();

        $withoutCookie = $provider->registerCookieConsentHead('');

        $_COOKIE[$this->cookieName()] = '{"analytics":true,"marketing":true}';
        request()->cookies->set($this->cookieName(), '{"analytics":true,"marketing":true}');

        $withCookie = $provider->registerCookieConsentHead('');

        unset($_COOKIE[$this->cookieName()]);
        request()->cookies->remove($this->cookieName());

        $this->assertSame(
            $withCookie,
            $withoutCookie,
            'Consent Mode state must be resolved in the browser, not baked into the head per visitor.'
        );
    }

    public function test_head_scripts_deny_every_storage_type_by_default(): void
    {
        $html = $this->provider()->registerCookieConsentHead('');

        // Whatever the client-side re-apply does later, the page must start denied so a
        // visitor who has not consented (or has JS disabled) is never tracked.
        $this->assertStringContainsString("gtag('consent', 'default'", $html);
        $this->assertStringContainsString("'ad_storage': 'denied'", $html);
        $this->assertStringContainsString("'analytics_storage': 'denied'", $html);
    }
}
