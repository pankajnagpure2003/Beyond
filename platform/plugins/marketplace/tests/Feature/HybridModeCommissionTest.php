<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Models\Customer;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Services\CommissionFeeResolver;
use Botble\Setting\Facades\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Test hybrid mode commission resolution: the matrix of all three modes ×
 * {no plan, plan with -1, plan with 2.0, plan with 0}.
 */
class HybridModeCommissionTest extends BaseTestCase
{
    use RefreshDatabase;

    protected CommissionFeeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        // Global settings: 4.5% + 0.25 fixed (the reported scenario)
        Setting::set('marketplace_fee_per_order', 4.5)->save();
        Setting::set('marketplace_fee_per_order_fixed', 0.25)->save();

        $this->resolver = $this->app->make(CommissionFeeResolver::class);
    }

    protected function tearDown(): void
    {
        // Reset every setting touched to avoid leakage into unrelated tests.
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

    protected function createVendor(string $name = 'vendor'): Customer
    {
        return Customer::query()->create([
            'name' => $name,
            'email' => $name . '-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    protected function createPlan(array $options = []): SubscriptionPlan
    {
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Test Plan',
            'price' => 29.0,
            'duration_value' => 1,
            'duration_unit' => 'month',
        ]);

        if ($options) {
            $plan->fillOptions($options);
            $plan->save();
        }

        return $plan;
    }

    protected function subscribeVendor(Customer $vendor, SubscriptionPlan $plan): VendorSubscription
    {
        return VendorSubscription::query()->create([
            'customer_id' => $vendor->id,
            'plan_id' => $plan->id,
            'plan_data' => $plan->toSnapshot(),
            'status' => 'active',
            'started_at' => now(),
            'expired_at' => now()->addMonth(),
        ]);
    }

    // Commission mode tests: always uses global rate, plan is ignored
    public function test_commission_mode_no_subscription_row(): void
    {
        $this->setMode(MarketplaceModeEnum::COMMISSION);
        $vendor = $this->createVendor();

        $rate = $this->resolver->forVendor($vendor);

        $this->assertEqualsWithDelta(4.5, $rate->percentage, 0.0001);
        $this->assertEqualsWithDelta(0.25, $rate->fixed, 0.0001);
    }

    public function test_commission_mode_ignores_plan_override(): void
    {
        $this->setMode(MarketplaceModeEnum::COMMISSION);
        $vendor = $this->createVendor();
        $plan = $this->createPlan(['commission_percentage' => 2.0, 'commission_fixed' => 0.1]);
        $this->subscribeVendor($vendor, $plan);

        $rate = $this->resolver->forVendor($vendor);

        // Plan override is ignored in commission mode
        $this->assertEqualsWithDelta(4.5, $rate->percentage, 0.0001);
        $this->assertEqualsWithDelta(0.25, $rate->fixed, 0.0001);
    }

    // Subscription mode tests: always returns zero, plan is ignored
    public function test_subscription_mode_no_commission(): void
    {
        $this->setMode(MarketplaceModeEnum::SUBSCRIPTION);
        $vendor = $this->createVendor();

        $rate = $this->resolver->forVendor($vendor);

        $this->assertTrue($rate->isNone());
        $this->assertEqualsWithDelta(0.0, $rate->percentage, 0.0001);
        $this->assertEqualsWithDelta(0.0, $rate->fixed, 0.0001);
    }

    public function test_subscription_mode_ignores_plan_override(): void
    {
        $this->setMode(MarketplaceModeEnum::SUBSCRIPTION);
        $vendor = $this->createVendor();
        $plan = $this->createPlan(['commission_percentage' => 2.0, 'commission_fixed' => 0.1]);
        $this->subscribeVendor($vendor, $plan);

        $rate = $this->resolver->forVendor($vendor);

        // Plan override is ignored in subscription mode
        $this->assertTrue($rate->isNone());
    }

    // Hybrid mode tests: uses plan override if present, else global rate
    public function test_hybrid_mode_no_subscription_row_uses_global_rate(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();

        $rate = $this->resolver->forVendor($vendor);

        // No plan, so global rate applies
        $this->assertEqualsWithDelta(4.5, $rate->percentage, 0.0001);
        $this->assertEqualsWithDelta(0.25, $rate->fixed, 0.0001);
    }

    public function test_hybrid_mode_plan_with_minus_one_uses_global_rate(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();
        // -1 means "inherit the global rate"
        $plan = $this->createPlan(['commission_percentage' => -1, 'commission_fixed' => -1]);
        $this->subscribeVendor($vendor, $plan);

        $rate = $this->resolver->forVendor($vendor);

        $this->assertEqualsWithDelta(4.5, $rate->percentage, 0.0001);
        $this->assertEqualsWithDelta(0.25, $rate->fixed, 0.0001);
    }

    public function test_hybrid_mode_plan_with_override_percentage(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();
        $plan = $this->createPlan(['commission_percentage' => 2.0, 'commission_fixed' => -1]);
        $this->subscribeVendor($vendor, $plan);

        $rate = $this->resolver->forVendor($vendor);

        // Percentage overridden, fixed inherited
        $this->assertEqualsWithDelta(2.0, $rate->percentage, 0.0001);
        $this->assertEqualsWithDelta(0.25, $rate->fixed, 0.0001);
    }

    public function test_hybrid_mode_plan_with_override_fixed(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();
        $plan = $this->createPlan(['commission_percentage' => -1, 'commission_fixed' => 0.1]);
        $this->subscribeVendor($vendor, $plan);

        $rate = $this->resolver->forVendor($vendor);

        // Percentage inherited, fixed overridden
        $this->assertEqualsWithDelta(4.5, $rate->percentage, 0.0001);
        $this->assertEqualsWithDelta(0.1, $rate->fixed, 0.0001);
    }

    public function test_hybrid_mode_plan_with_override_both(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();
        $plan = $this->createPlan(['commission_percentage' => 2.0, 'commission_fixed' => 0.1]);
        $this->subscribeVendor($vendor, $plan);

        $rate = $this->resolver->forVendor($vendor);

        // Both overridden
        $this->assertEqualsWithDelta(2.0, $rate->percentage, 0.0001);
        $this->assertEqualsWithDelta(0.1, $rate->fixed, 0.0001);
    }

    public function test_hybrid_mode_plan_with_zero_percentage(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();
        $plan = $this->createPlan(['commission_percentage' => 0.0, 'commission_fixed' => -1]);
        $this->subscribeVendor($vendor, $plan);

        $rate = $this->resolver->forVendor($vendor);

        // Zero percentage (no percentage commission), but fixed inherited
        $this->assertEqualsWithDelta(0.0, $rate->percentage, 0.0001);
        $this->assertEqualsWithDelta(0.25, $rate->fixed, 0.0001);
    }

    public function test_hybrid_mode_plan_with_zero_fixed(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();
        $plan = $this->createPlan(['commission_percentage' => -1, 'commission_fixed' => 0.0]);
        $this->subscribeVendor($vendor, $plan);

        $rate = $this->resolver->forVendor($vendor);

        // Percentage inherited, zero fixed (no fixed commission)
        $this->assertEqualsWithDelta(4.5, $rate->percentage, 0.0001);
        $this->assertEqualsWithDelta(0.0, $rate->fixed, 0.0001);
    }

    public function test_hybrid_mode_plan_with_both_zero(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();
        $plan = $this->createPlan(['commission_percentage' => 0.0, 'commission_fixed' => 0.0]);
        $this->subscribeVendor($vendor, $plan);

        $rate = $this->resolver->forVendor($vendor);

        // Premium tier: no commission at all
        $this->assertTrue($rate->isNone());
        $this->assertEqualsWithDelta(0.0, $rate->percentage, 0.0001);
        $this->assertEqualsWithDelta(0.0, $rate->fixed, 0.0001);
    }

    // Fee calculation on realistic order amounts
    public function test_hybrid_ten_dollar_order_no_plan(): void
    {
        // Reported scenario: 4.5% + €0.25 on a €10 sub-order
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();

        $rate = $this->resolver->forVendor($vendor);
        $fee = $rate->percentage / 100 * 10.0 + $rate->fixed;
        $fee = min($fee, 10.0);

        $this->assertEqualsWithDelta(0.70, $fee, 0.0001);
    }

    public function test_hybrid_ten_dollar_order_with_override(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();
        $plan = $this->createPlan(['commission_percentage' => 2.0, 'commission_fixed' => 0.0]);
        $this->subscribeVendor($vendor, $plan);

        $rate = $this->resolver->forVendor($vendor);
        $fee = $rate->percentage / 100 * 10.0 + $rate->fixed;
        $fee = min($fee, 10.0);

        // 10 * 2% + 0 = 0.20
        $this->assertEqualsWithDelta(0.20, $fee, 0.0001);
    }

    public function test_hybrid_ten_dollar_order_with_zero_commission_plan(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();
        $plan = $this->createPlan(['commission_percentage' => 0.0, 'commission_fixed' => 0.0]);
        $this->subscribeVendor($vendor, $plan);

        $rate = $this->resolver->forVendor($vendor);
        $fee = $rate->percentage / 100 * 10.0 + $rate->fixed;
        $fee = min($fee, 10.0);

        // Zero commission: no fee
        $this->assertEqualsWithDelta(0.0, $fee, 0.0001);
    }
}
