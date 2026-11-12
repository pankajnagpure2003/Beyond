<?php

namespace Botble\Language\Tests\Feature;

use Botble\Base\Supports\BaseTestCase;
use Botble\Language\Facades\Language;
use Botble\Language\Providers\HookServiceProvider;

/**
 * The language plugin maps ?ref_lang=<lang_code> to the locale the email template preview
 * iframe is rendered in (see the "email_template_preview_locale" filter).
 */
class EmailTemplatePreviewLocaleTest extends BaseTestCase
{
    protected HookServiceProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Language::setSupportedLocales([
            'en' => ['lang_name' => 'English', 'lang_locale' => 'en', 'lang_code' => 'en_US', 'lang_is_default' => true],
            'vi' => ['lang_name' => 'Tiếng Việt', 'lang_locale' => 'vi', 'lang_code' => 'vi', 'lang_is_default' => false],
            'pt_BR' => ['lang_name' => 'Português', 'lang_locale' => 'pt_BR', 'lang_code' => 'pt-br', 'lang_is_default' => false],
        ]);

        $this->provider = new HookServiceProvider($this->app);
    }

    protected function withRefLang(?string $refLang): void
    {
        request()->query->remove('ref_lang');

        if ($refLang !== null) {
            request()->query->set('ref_lang', $refLang);
        }
    }

    public function test_ref_lang_is_mapped_to_its_locale(): void
    {
        $this->withRefLang('vi');

        $this->assertSame('vi', $this->provider->emailTemplatePreviewLocale(null));
    }

    public function test_lang_code_different_from_locale_is_mapped(): void
    {
        $this->withRefLang('en_US');
        $this->assertSame('en', $this->provider->emailTemplatePreviewLocale(null));

        $this->withRefLang('pt-br');
        $this->assertSame('pt_BR', $this->provider->emailTemplatePreviewLocale(null));
    }

    public function test_unknown_ref_lang_keeps_given_locale(): void
    {
        $this->withRefLang('xx');

        $this->assertNull($this->provider->emailTemplatePreviewLocale(null));
        $this->assertSame('fr', $this->provider->emailTemplatePreviewLocale('fr'));
    }

    public function test_missing_or_empty_ref_lang_keeps_given_locale(): void
    {
        $this->withRefLang(null);
        $this->assertNull($this->provider->emailTemplatePreviewLocale(null));

        $this->withRefLang('');
        $this->assertSame('fr', $this->provider->emailTemplatePreviewLocale('fr'));
    }

    public function test_non_string_ref_lang_is_ignored(): void
    {
        request()->query->set('ref_lang', ['vi']);

        $this->assertNull($this->provider->emailTemplatePreviewLocale(null));
    }

    public function test_provider_registers_the_filter(): void
    {
        $this->withRefLang('vi');

        remove_filter('email_template_preview_locale');
        $this->provider->boot();

        $this->assertSame('vi', apply_filters('email_template_preview_locale', null));

        remove_filter('email_template_preview_locale');
    }
}
