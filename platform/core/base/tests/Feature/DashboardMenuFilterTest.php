<?php

namespace Botble\Base\Tests\Feature;

use Botble\Base\Facades\DashboardMenu;
use Illuminate\Support\Collection;
use Tests\TestCase;

class DashboardMenuFilterTest extends TestCase
{
    protected function tearDown(): void
    {
        remove_filter('dashboard_menu');

        parent::tearDown();
    }

    public function test_menu_is_returned_when_no_filter_is_registered(): void
    {
        $this->assertInstanceOf(Collection::class, DashboardMenu::getAll());
    }

    public function test_menu_survives_a_filter_that_returns_null(): void
    {
        // A third-party plugin registering a listener without returning the menu
        // must not break the whole admin layout with a TypeError.
        add_filter('dashboard_menu', fn () => null, 1, 2);

        $this->assertInstanceOf(Collection::class, DashboardMenu::getAll());
    }

    public function test_menu_accepts_a_filter_that_returns_an_array(): void
    {
        add_filter('dashboard_menu', fn ($menu) => $menu instanceof Collection ? $menu->all() : $menu, 1, 2);

        $this->assertInstanceOf(Collection::class, DashboardMenu::getAll());
    }
}
