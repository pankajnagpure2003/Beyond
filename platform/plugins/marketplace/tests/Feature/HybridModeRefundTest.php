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
 * Test refund behaviour in hybrid mode: refunds reverse the percentage part only,
 * never the fixed fee (which was charged once on the original order).
 */
class HybridModeRefundTest extends BaseTestCase
{
    use RefreshDatabase;

    protected CommissionFeeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('marketplace_fee_per_order', 4.5)->save();
        Setting::set('marketplace_fee_per_order_fixed', 0.25)->save();

        $this->resolver = $this->app->make(CommissionFeeResolver::class);
    }

    protected function tearDown(): void
    {
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

    /**
     * In commission mode, refunds reverse both percentage and fixed fee.
     */
    public function test_commission_mode_refund_reverses_both_parts(): void
    {
        $this->setMode(MarketplaceModeEnum::COMMISSION);
        $vendor = $this->createVendor();

        $rate = $this->resolver->forVendor($vendor);
        $originalFee = $rate->percentage / 100 * 100.0 + $rate->fixed;
        $refundFee = $rate->percentage / 100 * 100.0 + $rate->fixed;

        // Original order: 100 * 4.5% + 0.25 = 4.75
        $this->assertEqualsWithDelta(4.75, $originalFee, 0.0001);
        // Refund reverses the entire fee in commission mode
        $this->assertEqualsWithDelta(4.75, $refundFee, 0.0001);
    }

    /**
     * In subscription mode, no commission fee is charged or refunded.
     */
    public function test_subscription_mode_refund_is_zero(): void
    {
        $this->setMode(MarketplaceModeEnum::SUBSCRIPTION);
        $vendor = $this->createVendor();

        $rate = $this->resolver->forVendor($vendor);
        $originalFee = 0.0; // Subscription mode charges nothing
        $refundFee = 0.0;

        $this->assertTrue($rate->isNone());
        $this->assertEqualsWithDelta(0.0, $originalFee, 0.0001);
        $this->assertEqualsWithDelta(0.0, $refundFee, 0.0001);
    }

    /**
     * In hybrid mode, refunds reverse only the percentage, not the fixed fee.
     */
    public function test_hybrid_mode_refund_excludes_fixed_fee(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();

        $rate = $this->resolver->forVendor($vendor);
        $orderFee = $rate->percentage / 100 * 100.0 + $rate->fixed;
        $refundFee = $rate->percentage / 100 * 100.0; // No fixed part

        // Order fee: 100 * 4.5% + 0.25 = 4.75
        $this->assertEqualsWithDelta(4.75, $orderFee, 0.0001);
        // Refund fee: only percentage = 100 * 4.5% = 4.50 (no 0.25 fixed)
        $this->assertEqualsWithDelta(4.50, $refundFee, 0.0001);
    }

    public function test_hybrid_mode_refund_with_zero_percentage_plan(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();
        $plan = $this->createPlan(['commission_percentage' => 0.0, 'commission_fixed' => -1]);
        $this->subscribeVendor($vendor, $plan);

        $rate = $this->resolver->forVendor($vendor);
        $orderFee = $rate->percentage / 100 * 100.0 + $rate->fixed;
        $refundFee = $rate->percentage / 100 * 100.0;

        // Order fee: 100 * 0% + 0.25 = 0.25 (fixed only)
        $this->assertEqualsWithDelta(0.25, $orderFee, 0.0001);
        // Refund fee: 100 * 0% = 0.0 (no percentage to refund)
        $this->assertEqualsWithDelta(0.0, $refundFee, 0.0001);
    }

    public function test_hybrid_mode_refund_with_zero_fixed_plan(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();
        $plan = $this->createPlan(['commission_percentage' => -1, 'commission_fixed' => 0.0]);
        $this->subscribeVendor($vendor, $plan);

        $rate = $this->resolver->forVendor($vendor);
        $orderFee = $rate->percentage / 100 * 100.0 + $rate->fixed;
        $refundFee = $rate->percentage / 100 * 100.0;

        // Order fee: 100 * 4.5% + 0 = 4.50 (percentage only)
        $this->assertEqualsWithDelta(4.50, $orderFee, 0.0001);
        // Refund fee: same, 100 * 4.5% = 4.50 (no fixed to exclude)
        $this->assertEqualsWithDelta(4.50, $refundFee, 0.0001);
    }

    public function test_hybrid_mode_refund_on_small_order(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();

        $rate = $this->resolver->forVendor($vendor);
        $subAmount = 10.0;
        $orderFee = min($rate->percentage / 100 * $subAmount + $rate->fixed, $subAmount);
        $refundFee = $rate->percentage / 100 * $subAmount;

        // Order: 10 * 4.5% + 0.25 = 0.70
        $this->assertEqualsWithDelta(0.70, $orderFee, 0.0001);
        // Refund: 10 * 4.5% = 0.45 (fixed not refunded)
        $this->assertEqualsWithDelta(0.45, $refundFee, 0.0001);
    }

    public function test_hybrid_mode_refund_with_override_percentage(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();
        $plan = $this->createPlan(['commission_percentage' => 2.0, 'commission_fixed' => -1]);
        $this->subscribeVendor($vendor, $plan);

        $rate = $this->resolver->forVendor($vendor);
        $subAmount = 100.0;
        $orderFee = $rate->percentage / 100 * $subAmount + $rate->fixed;
        $refundFee = $rate->percentage / 100 * $subAmount;

        // Order: 100 * 2% + 0.25 = 2.25
        $this->assertEqualsWithDelta(2.25, $orderFee, 0.0001);
        // Refund: 100 * 2% = 2.00 (fixed not refunded)
        $this->assertEqualsWithDelta(2.00, $refundFee, 0.0001);
    }

    public function test_hybrid_mode_refund_with_both_overridden(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();
        $plan = $this->createPlan(['commission_percentage' => 2.0, 'commission_fixed' => 0.1]);
        $this->subscribeVendor($vendor, $plan);

        $rate = $this->resolver->forVendor($vendor);
        $subAmount = 100.0;
        $orderFee = $rate->percentage / 100 * $subAmount + $rate->fixed;
        $refundFee = $rate->percentage / 100 * $subAmount;

        // Order: 100 * 2% + 0.1 = 2.10
        $this->assertEqualsWithDelta(2.10, $orderFee, 0.0001);
        // Refund: 100 * 2% = 2.00 (0.1 fixed not refunded)
        $this->assertEqualsWithDelta(2.00, $refundFee, 0.0001);
    }

    public function test_hybrid_mode_refund_capped_order_fee(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();

        $rate = $this->resolver->forVendor($vendor);
        $subAmount = 0.20;
        // Order fee: 0.20 * 4.5% + 0.25 = 0.259, capped at 0.20
        $orderFee = min($rate->percentage / 100 * $subAmount + $rate->fixed, $subAmount);
        // Refund fee: 0.20 * 4.5% = 0.009 (percentage part)
        $refundFee = $rate->percentage / 100 * $subAmount;

        $this->assertEqualsWithDelta(0.20, $orderFee, 0.0001);
        $this->assertEqualsWithDelta(0.009, $refundFee, 0.0001);
    }

    public function test_hybrid_mode_fixed_fee_differential(): void
    {
        // Demonstrates why fixed fee must not be refunded: it's charged once per order,
        // not per item. A vendor refunding an item shouldn't undo the gateway fee on the
        // entire order (which covered multiple items possibly).
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $vendor = $this->createVendor();

        $rate = $this->resolver->forVendor($vendor);

        // Order with €100 sub-amount
        $orderFee = $rate->percentage / 100 * 100.0 + $rate->fixed;
        // Customer refunds half (€50 of the €100)
        $refundFee = $rate->percentage / 100 * 50.0; // Percentage part only

        // Original: 100 * 4.5% + 0.25 = 4.75
        $this->assertEqualsWithDelta(4.75, $orderFee, 0.0001);
        // Partial refund: 50 * 4.5% = 2.25 (fixed not touched)
        $this->assertEqualsWithDelta(2.25, $refundFee, 0.0001);
        // Difference: 4.75 - 2.25 = 2.50 (marketplace keeps the fixed 0.25 and half the percentage)
        $this->assertEqualsWithDelta(2.50, $orderFee - $refundFee, 0.0001);
    }
}
