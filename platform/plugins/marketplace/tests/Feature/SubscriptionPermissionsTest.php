<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Supports\BaseTestCase;
use Illuminate\Support\Facades\Route;

/**
 * A route whose permission flag is not declared in config/permissions.php can never be
 * granted to a non-super admin, so the screen is silently unreachable for them. The
 * feature tests all run as a super user, which bypasses this check entirely.
 */
class SubscriptionPermissionsTest extends BaseTestCase
{
    /**
     * Mirrors Botble\ACL\Http\Middleware\Authenticate: the flag is the explicit
     * 'permission' action, else the route name, with store/update folded onto
     * create/edit.
     */
    protected function flagFor(\Illuminate\Routing\Route $route): ?string
    {
        $flag = $route->getAction('permission') ?: $route->getName();

        if (! $flag) {
            return null;
        }

        $flag = preg_replace('/.store$/', '.create', $flag);

        return preg_replace('/.update$/', '.edit', $flag);
    }

    protected function declaredFlags(): array
    {
        return array_column(config('plugins.marketplace.permissions', []), 'flag');
    }

    public function test_every_subscription_admin_route_has_a_declared_permission(): void
    {
        $declared = $this->declaredFlags();
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if (! $name || ! preg_match('/^marketplace\.(subscription-plans|vendor-subscriptions)\./', $name)) {
                continue;
            }

            $flag = $this->flagFor($route);

            if ($flag && ! in_array($flag, $declared, true)) {
                $missing[] = $name . ' => ' . $flag;
            }
        }

        $this->assertSame([], $missing, 'Undeclared permission flags: ' . implode(', ', $missing));
    }

    public function test_the_subscription_routes_are_actually_registered(): void
    {
        $names = collect(Route::getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->values()
            ->all();

        foreach ([
            'marketplace.subscription-plans.index',
            'marketplace.subscription-plans.create',
            'marketplace.subscription-plans.edit',
            'marketplace.vendor-subscriptions.index',
            'marketplace.vendor-subscriptions.approve',
            'marketplace.vendor-subscriptions.reject',
            'marketplace.vendor-subscriptions.extend',
            'marketplace.vendor-subscriptions.cancel',
            'marketplace.vendor.subscriptions.index',
            'marketplace.vendor.subscriptions.plans',
            'marketplace.vendor.subscriptions.checkout',
            'marketplace.vendor.subscriptions.callback',
            'marketplace.vendor.subscriptions.cancel-payment',
        ] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function test_permission_flags_are_unique(): void
    {
        $flags = $this->declaredFlags();

        $this->assertSame(
            array_unique($flags),
            $flags,
            'Duplicate permission flags: ' . implode(', ', array_diff_assoc($flags, array_unique($flags)))
        );
    }

    public function test_every_permission_parent_flag_exists(): void
    {
        $permissions = config('plugins.marketplace.permissions', []);
        $flags = array_column($permissions, 'flag');
        $orphans = [];

        foreach ($permissions as $permission) {
            $parent = $permission['parent_flag'] ?? null;

            // ecommerce.settings is declared by the ecommerce plugin, not this one.
            if (! $parent || $parent === 'ecommerce.settings') {
                continue;
            }

            if (! in_array($parent, $flags, true)) {
                $orphans[] = $permission['flag'] . ' -> ' . $parent;
            }
        }

        $this->assertSame([], $orphans, 'Orphaned parent flags: ' . implode(', ', $orphans));
    }
}
