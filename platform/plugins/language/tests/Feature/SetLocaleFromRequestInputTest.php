<?php

namespace Botble\Language\Tests\Feature;

use Botble\Base\Facades\BaseHelper;
use Botble\Base\Supports\BaseTestCase;
use Botble\Language\LanguageManager;
use Illuminate\Http\Request;

/**
 * Regression cover for the front-end `language` form field colliding with the admin
 * content-translation switcher.
 *
 * setLocale() feeds the prefix of the whole public route group
 * (LanguageServiceProvider::addLanguageMiddlewareToPublicRoute). When it honoured the
 * `language` request input on the front-end, any form posting a field named `language`
 * (the job-board account "add language" form) moved its own route under that locale
 * prefix, so the submitted URL matched nothing and answered 404. It only broke for
 * values that are installed site locales, which is exactly the set of languages a user
 * is most likely to pick.
 */
class SetLocaleFromRequestInputTest extends BaseTestCase
{
    protected function localesArray(): array
    {
        return [
            'ar' => [
                'lang_name' => 'Arabic',
                'lang_locale' => 'ar',
                'lang_code' => 'ar',
                'lang_is_default' => true,
                'lang_is_rtl' => true,
                'lang_flag' => 'sa',
            ],
            'en' => [
                'lang_name' => 'English',
                'lang_locale' => 'en',
                'lang_code' => 'en_US',
                'lang_is_default' => false,
                'lang_is_rtl' => false,
                'lang_flag' => 'us',
            ],
        ];
    }

    protected function managerFor(Request $request): LanguageManager
    {
        $this->app->instance('request', $request);
        $this->app->forgetInstance(LanguageManager::class);

        $manager = $this->app->make(LanguageManager::class);
        $manager->setSupportedLocales($this->localesArray());

        return $manager;
    }

    protected function frontRequest(array $input): Request
    {
        return Request::create('/account/languages', 'POST', $input);
    }

    protected function adminRequest(array $input): Request
    {
        return Request::create(BaseHelper::getAdminPrefix() . '/accounts/languages', 'POST', $input);
    }

    public function testFrontEndRequestIgnoresLanguageInputMatchingDefaultLocale(): void
    {
        $this->assertNull($this->managerFor($this->frontRequest(['language' => 'ar']))->setLocale());
    }

    public function testFrontEndRequestIgnoresLanguageInputMatchingNonDefaultLocale(): void
    {
        $this->assertNull($this->managerFor($this->frontRequest(['language' => 'en']))->setLocale());
    }

    public function testFrontEndRequestIgnoresLanguageInputThatIsNotASiteLocale(): void
    {
        $this->assertNull($this->managerFor($this->frontRequest(['language' => 'fr']))->setLocale());
    }

    public function testAdminRequestStillHonoursLanguageInput(): void
    {
        $this->assertSame('ar', $this->managerFor($this->adminRequest(['language' => 'ar']))->setLocale());
    }

    public function testFrontEndRequestStillResolvesLocaleFromUrlSegment(): void
    {
        $request = Request::create('/en/jobs', 'GET');

        $this->assertSame('en', $this->managerFor($request)->setLocale());
    }

    public function testFrontEndRequestStillHonoursLanguageHeader(): void
    {
        $request = Request::create('/api/v1/jobs', 'GET');
        $request->headers->set('X-LANGUAGE', 'en');

        $this->assertSame('en', $this->managerFor($request)->setLocale());
    }
}
