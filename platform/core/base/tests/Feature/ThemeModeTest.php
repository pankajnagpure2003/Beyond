<?php

namespace Botble\Base\Tests\Feature;

use Botble\ACL\Models\User;
use Botble\ACL\Services\ActivateUserService;
use Botble\Base\Facades\AdminHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ThemeModeTest extends TestCase
{
    use DatabaseTransactions;

    protected function createUser(): User
    {
        $user = new User();
        $user->forceFill([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'user-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'super_user' => 1,
        ]);
        $user->save();

        // The admin Authenticate middleware sends users without a completed activation to the login page.
        app(ActivateUserService::class)->activate($user);

        return $user->fresh();
    }

    public function test_system_theme_mode_is_saved_and_renders_light_until_the_os_preference_is_known(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)
            ->get(route('toggle-theme-mode', ['theme' => 'system']))
            ->assertRedirect();

        $this->assertSame('system', $user->fresh()->getMeta('theme_mode'));

        // A fresh model, as a new request would load: the one used above caches its metadata.
        $this->actingAs($user->fresh());

        $this->assertSame('system', AdminHelper::themeMode());
        $this->assertSame('light', AdminHelper::initialThemeMode());
    }

    public function test_dark_theme_mode_renders_dark(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)
            ->get(route('toggle-theme-mode', ['theme' => 'dark']));

        $this->actingAs($user->fresh());

        $this->assertSame('dark', AdminHelper::initialThemeMode());
    }

    public function test_unknown_theme_mode_is_rejected(): void
    {
        $user = $this->createUser();
        $user->setMeta('theme_mode', 'dark');

        $this->actingAs($user)
            ->get(route('toggle-theme-mode', ['theme' => 'sepia']))
            ->assertSessionHasErrors('theme');

        $this->assertSame('dark', $user->fresh()->getMeta('theme_mode'));
    }

    public function test_an_invalid_stored_theme_mode_falls_back_to_light(): void
    {
        $user = $this->createUser();
        $user->setMeta('theme_mode', 'sepia');

        $this->actingAs($user);

        $this->assertSame('light', AdminHelper::themeMode());
        $this->assertSame('light', AdminHelper::initialThemeMode());
    }
}
