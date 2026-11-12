<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Enums\OrderStatusEnum;
use Botble\Ecommerce\Models\Currency;
use Botble\Ecommerce\Models\Customer;
use Botble\Ecommerce\Models\Order;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Enums\StoreStatusEnum;
use Botble\Marketplace\Enums\SubscriptionStatusEnum;
use Botble\Marketplace\Models\Revenue;
use Botble\Marketplace\Models\Store;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Providers\OrderSupportServiceProvider;
use Botble\Setting\Facades\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * End-to-end money path for hybrid mode.
 *
 * The other Hybrid* tests stop at CommissionFeeResolver, which proves the RATE is right but
 * not that the rate reaches the ledger. This drives the real
 * OrderSupportServiceProvider::afterOrderStatusCompleted() and asserts on the persisted
 * Revenue row, the same way FixedCommissionFeeTest does for commission mode.
 */
class HybridOrderRevenueTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // get_application_currency()->title is read while writing the Revenue row.
        Currency::query()->create([
            'title' => 'USD',
            'symbol' => '$',
            'is_prefix_symbol' => true,
            'exchange_rate' => 1,
            'is_default' => true,
            'order' => 0,
        ]);

        $this->setCommission(4.5, 0.25);
    }

    protected function tearDown(): void
    {
        // The settings store is a singleton that outlives RefreshDatabase's rollback.
        $this->setSetting('marketplace_mode', MarketplaceModeEnum::COMMISSION);
        Setting::forget('marketplace_fee_per_order');
        Setting::forget('marketplace_fee_per_order_fixed');
        Setting::forget('marketplace_enable_commission_fee_for_each_category');
        Setting::save();

        parent::tearDown();
    }

    protected function setSetting(string $key, mixed $value): void
    {
        Setting::load(true);
        Setting::set($key, $value);
        Setting::save();
        Setting::load(true);
    }

    protected function setCommission(float $percentage, float $fixed): void
    {
        $this->setSetting('marketplace_enable_commission_fee_for_each_category', 0);
        $this->setSetting('marketplace_fee_per_order', $percentage);
        $this->setSetting('marketplace_fee_per_order_fixed', $fixed);
    }

    protected function setMode(string $mode): void
    {
        $this->setSetting('marketplace_mode', $mode);

        // CommissionFeeResolver and VendorSubscriptionService are scoped(); a resolver built
        // under the previous mode would answer from a stale cache.
        $this->app->forgetScopedInstances();
    }

    protected function createVendorStore(): Store
    {
        $customer = Customer::query()->create([
            'name' => 'Hybrid Vendor',
            'email' => 'hybrid-vendor-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'is_vendor' => true,
        ]);

        return Store::query()->create([
            'name' => 'Hybrid Store',
            'email' => 'hybrid-store-' . uniqid() . '@example.com',
            'customer_id' => $customer->id,
            'status' => StoreStatusEnum::PUBLISHED,
        ]);
    }

    /**
     * Give the store's vendor an active subscription whose snapshot carries these overrides.
     * -1 on either option means "inherit the global rate".
     */
    protected function subscribe(Store $store, float $percentage = -1, float $fixed = -1): VendorSubscription
    {
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Hybrid Plan',
            'price' => 29.0,
            'duration_value' => 1,
            'duration_unit' => 'month',
            'status' => 'published',
        ]);

        $plan->fillOptions(['commission_percentage' => $percentage, 'commission_fixed' => $fixed]);
        $plan->save();

        $subscription = VendorSubscription::query()->create([
            'customer_id' => $store->customer_id,
            'subscription_plan_id' => $plan->getKey(),
            'status' => SubscriptionStatusEnum::ACTIVE,
            'amount' => $plan->price,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'plan_data' => $plan->toSnapshot(),
        ]);

        $this->app->forgetScopedInstances();

        return $subscription;
    }

    protected function createOrderForStore(Store $store, float $amount): Order
    {
        $order = Order::query()->create([
            'user_id' => 0,
            'amount' => $amount,
            'sub_total' => $amount,
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'shipping_tax_amount' => 0,
            'payment_fee' => 0,
            'discount_amount' => 0,
            'status' => OrderStatusEnum::COMPLETED,
            'is_finished' => true,
        ]);

        // store_id is a marketplace-added column and is not in Order's $fillable.
        $order->store_id = $store->id;
        $order->save();

        return $order;
    }

    protected function feeForCompletedOrder(Order $order): float
    {
        (new OrderSupportServiceProvider($this->app))->afterOrderStatusCompleted($order);

        return (float) Revenue::query()->where('order_id', $order->getKey())->value('fee');
    }

    // ---------------------------------------------------------------- order path

    public function test_hybrid_order_without_subscription_uses_global_rate(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $store = $this->createVendorStore();

        $fee = $this->feeForCompletedOrder($this->createOrderForStore($store, 10.00));

        // 10 * 4.5% + 0.25
        $this->assertEqualsWithDelta(0.70, $fee, 0.0001);
    }

    public function test_hybrid_order_with_inherit_sentinel_uses_global_rate(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $store = $this->createVendorStore();
        $this->subscribe($store, -1, -1);

        $this->assertEqualsWithDelta(0.70, $this->feeForCompletedOrder($this->createOrderForStore($store, 10.00)), 0.0001);
    }

    public function test_hybrid_order_with_plan_percentage_override(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $store = $this->createVendorStore();
        $this->subscribe($store, 2.0, -1);

        // 10 * 2% + 0.25 global fixed
        $this->assertEqualsWithDelta(0.45, $this->feeForCompletedOrder($this->createOrderForStore($store, 10.00)), 0.0001);
    }

    /**
     * The case that catches treating 0 as "inherit": a premium plan paying no percentage
     * must still be charged the fixed fee, and must NOT fall back to the global 4.5%.
     */
    public function test_hybrid_order_with_zero_percentage_plan_charges_fixed_only(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $store = $this->createVendorStore();
        $this->subscribe($store, 0.0, -1);

        $this->assertEqualsWithDelta(0.25, $this->feeForCompletedOrder($this->createOrderForStore($store, 10.00)), 0.0001);
    }

    public function test_hybrid_order_with_both_options_zero_charges_nothing(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $store = $this->createVendorStore();
        $this->subscribe($store, 0.0, 0.0);

        $this->assertEqualsWithDelta(0.0, $this->feeForCompletedOrder($this->createOrderForStore($store, 10.00)), 0.0001);
    }

    public function test_subscription_mode_writes_zero_fee_even_with_fixed_fee_configured(): void
    {
        $this->setMode(MarketplaceModeEnum::SUBSCRIPTION);
        $store = $this->createVendorStore();
        $this->subscribe($store, -1, -1);

        $this->assertEqualsWithDelta(0.0, $this->feeForCompletedOrder($this->createOrderForStore($store, 10.00)), 0.0001);
    }

    /**
     * Commission mode must be byte-identical to before hybrid existed, and must ignore any
     * plan the vendor happens to hold.
     */
    public function test_commission_mode_ignores_plan_override(): void
    {
        $this->setMode(MarketplaceModeEnum::COMMISSION);
        $store = $this->createVendorStore();
        $this->subscribe($store, 2.0, 0.0);

        $this->assertEqualsWithDelta(0.70, $this->feeForCompletedOrder($this->createOrderForStore($store, 10.00)), 0.0001);
    }

    /**
     * min($fee, $subAmount) must survive: a fixed fee larger than the order cannot push the
     * vendor's payout negative.
     */
    public function test_hybrid_fee_is_capped_at_the_order_sub_amount(): void
    {
        $this->setCommission(4.5, 50.00);
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $store = $this->createVendorStore();
        $this->subscribe($store, -1, -1);

        $order = $this->createOrderForStore($store, 10.00);

        $this->assertEqualsWithDelta(10.00, $this->feeForCompletedOrder($order), 0.0001);

        $revenue = Revenue::query()->where('order_id', $order->getKey())->first();
        $this->assertEqualsWithDelta(0.0, (float) $revenue->amount, 0.0001);
    }

    public function test_hybrid_revenue_row_records_sub_amount_and_vendor_earning(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $store = $this->createVendorStore();
        $this->subscribe($store, 2.0, -1);

        $order = $this->createOrderForStore($store, 100.00);
        (new OrderSupportServiceProvider($this->app))->afterOrderStatusCompleted($order);

        $revenue = Revenue::query()->where('order_id', $order->getKey())->first();

        $this->assertNotNull($revenue);
        $this->assertEqualsWithDelta(100.00, (float) $revenue->sub_amount, 0.0001);
        // 100 * 2% + 0.25
        $this->assertEqualsWithDelta(2.25, (float) $revenue->fee, 0.0001);
        $this->assertEqualsWithDelta(97.75, (float) $revenue->amount, 0.0001);
    }

    /**
     * Two vendors on different plans must be charged their own rate from the same global
     * settings - the whole point of the per-plan override.
     */
    public function test_two_vendors_on_different_plans_pay_different_rates(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);

        $cheap = $this->createVendorStore();
        $this->subscribe($cheap, 2.0, -1);
        $feeCheap = $this->feeForCompletedOrder($this->createOrderForStore($cheap, 100.00));

        $standard = $this->createVendorStore();
        $this->subscribe($standard, -1, -1);
        $feeStandard = $this->feeForCompletedOrder($this->createOrderForStore($standard, 100.00));

        $this->assertEqualsWithDelta(2.25, $feeCheap, 0.0001);
        $this->assertEqualsWithDelta(4.75, $feeStandard, 0.0001);
        $this->assertGreaterThan($feeCheap, $feeStandard);
    }
}
