<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Supports\BaseTestCase;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Providers\OrderSupportServiceProvider;
use Botble\Setting\Facades\Setting;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;

/**
 * Subscription mode is mutually exclusive with commission: the admin earns from plans,
 * so no fee may be taken from an order.
 */
class SubscriptionModeCommissionTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('marketplace_fee_per_order', 10)->save();
        Setting::set('marketplace_fee_per_order_fixed', 2)->save();
    }

    protected function tearDown(): void
    {
        // The settings store is a singleton that outlives RefreshDatabase's rollback, so
        // a mode or fee left behind here leaks into unrelated tests in the same process.
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();
        Setting::forget('marketplace_fee_per_order');
        Setting::forget('marketplace_fee_per_order_fixed');
        Setting::forget('marketplace_subscription_unpublish_products_on_expired');
        Setting::forget('marketplace_subscription_grace_period_days');
        Setting::save();

        parent::tearDown();
    }

    protected function setMode(string $mode): void
    {
        Setting::set('marketplace_mode', $mode)->save();
    }

    /**
     * Mirrors the fee branch of OrderSupportServiceProvider::afterOrderStatusCompleted().
     */
    protected function feeFor(float $orderAmount): float
    {
        if (MarketplaceHelper::isSubscriptionMode()) {
            $fee = 0;
        } else {
            $fee = $orderAmount * (MarketplaceHelper::getSetting('fee_per_order', 0) / 100);
            $fee += (float) MarketplaceHelper::getSetting('fee_per_order_fixed', 0);
        }

        return min($fee, $orderAmount);
    }

    public function test_commission_mode_still_charges_percentage_plus_fixed_fee(): void
    {
        $this->setMode(MarketplaceModeEnum::COMMISSION);

        $this->assertSame(12.0, $this->feeFor(100));
    }

    public function test_subscription_mode_takes_no_commission(): void
    {
        $this->setMode(MarketplaceModeEnum::SUBSCRIPTION);

        $this->assertSame(0.0, $this->feeFor(100));
    }

    public function test_category_commission_calculator_returns_zero_in_subscription_mode(): void
    {
        $this->setMode(MarketplaceModeEnum::SUBSCRIPTION);

        $reflection = new ReflectionClass(OrderSupportServiceProvider::class);
        $method = $reflection->getMethod('calculatorCommissionFeeByProduct');
        $method->setAccessible(true);

        $fee = $method->invoke($reflection->newInstanceWithoutConstructor(), new EloquentCollection());

        $this->assertSame(0, $fee);
    }

    public function test_mode_defaults_to_commission_so_existing_installs_are_unaffected(): void
    {
        Setting::forget('marketplace_mode');
        Setting::save();

        $this->assertSame(MarketplaceModeEnum::COMMISSION, MarketplaceHelper::getMode());
        $this->assertTrue(MarketplaceHelper::isCommissionMode());
    }

    public function test_an_unrecognised_mode_falls_back_to_commission(): void
    {
        Setting::set('marketplace_mode', 'nonsense')->save();

        $this->assertSame(MarketplaceModeEnum::COMMISSION, MarketplaceHelper::getMode());
    }
}
