<?php

namespace Botble\Setting\Tests\Feature;

use Botble\ACL\Models\User;
use Botble\ACL\Services\ActivateUserService;
use Botble\Base\Supports\BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The email template preview iframe renders in the locale returned by the
 * "email_template_preview_locale" filter (the language plugin maps ?ref_lang=... to it).
 */
class EmailTemplateIframeLocaleTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = new User();
        $admin->forceFill([
            'email' => 'admin@example.com',
            'username' => 'admin',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
            'super_user' => 1,
        ])->save();

        app(ActivateUserService::class)->activate($admin);

        $this->actingAs($admin);

        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        remove_filter('email_template_preview_locale');

        parent::tearDown();
    }

    protected function iframeUrl(): string
    {
        return route('settings.email.template.iframe', ['core', 'base', 'test']);
    }

    public function test_preview_page_shows_empty_state_for_template_without_variables(): void
    {
        $this->get(route('settings.email.template.preview', ['core', 'base', 'test']))
            ->assertOk()
            ->assertSee(trans('core/setting::setting.preview_no_variables'))
            ->assertSee('id="preview-copy-link"', false)
            ->assertDontSee('id="preview-clear"', false)
            ->assertDontSee(trans('core/setting::setting.preview_live_hint'));
    }

    public function test_iframe_renders_in_admin_locale_without_filter(): void
    {
        $this->get($this->iframeUrl())
            ->assertOk()
            ->assertSee('Email Configuration Test');

        $this->assertSame('en', app()->getLocale());
    }

    public function test_iframe_renders_in_locale_returned_by_filter(): void
    {
        add_filter('email_template_preview_locale', fn () => 'vi');

        $this->get($this->iframeUrl())
            ->assertOk()
            ->assertSee('Kiểm tra cấu hình email')
            ->assertDontSee('Email Configuration Test');

        $this->assertSame('vi', app()->getLocale());
    }

    public function test_empty_filter_result_keeps_admin_locale(): void
    {
        add_filter('email_template_preview_locale', fn () => null);

        $this->get($this->iframeUrl())
            ->assertOk()
            ->assertSee('Email Configuration Test');

        $this->assertSame('en', app()->getLocale());
    }
}
