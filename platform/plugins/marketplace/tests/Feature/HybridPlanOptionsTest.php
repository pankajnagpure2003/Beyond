<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Supports\BaseTestCase;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Ecommerce\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Test that commission options round-trip through request → controller → model → plan_data
 * snapshot without truncation, and that legacy plans (created before commission options
 * existed) resolve both keys to -1 via the defaults merge.
 */
class HybridPlanOptionsTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function createVendor(string $name = 'vendor'): Customer
    {
        return Customer::query()->create([
            'name' => $name,
            'email' => $name . '-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_float_commission_percentage_survives_round_trip(): void
    {
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Float Test',
            'price' => 29.0,
            'duration_value' => 1,
            'duration_unit' => 'month',
        ]);

        $plan->fillOptions(['commission_percentage' => '4.5']);
        $plan->save();

        $reloaded = SubscriptionPlan::query()->find($plan->id);
        $percentage = $reloaded->getOption('commission_percentage');

        $this->assertIsFloat($percentage);
        $this->assertEqualsWithDelta(4.5, $percentage, 0.0001);
    }

    public function test_float_commission_fixed_survives_round_trip(): void
    {
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Fixed Test',
            'price' => 29.0,
            'duration_value' => 1,
            'duration_unit' => 'month',
        ]);

        $plan->fillOptions(['commission_fixed' => '0.25']);
        $plan->save();

        $reloaded = SubscriptionPlan::query()->find($plan->id);
        $fixed = $reloaded->getOption('commission_fixed');

        $this->assertIsFloat($fixed);
        $this->assertEqualsWithDelta(0.25, $fixed, 0.0001);
    }

    public function test_both_commission_options_preserve_precision(): void
    {
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Both Test',
            'price' => 29.0,
            'duration_value' => 1,
            'duration_unit' => 'month',
        ]);

        $plan->fillOptions([
            'commission_percentage' => '4.567',
            'commission_fixed' => '0.129',
        ]);
        $plan->save();

        $reloaded = SubscriptionPlan::query()->find($plan->id);

        $this->assertEqualsWithDelta(4.567, $reloaded->getOption('commission_percentage'), 0.00001);
        $this->assertEqualsWithDelta(0.129, $reloaded->getOption('commission_fixed'), 0.00001);
    }

    public function test_product_limit_stays_integer(): void
    {
        // Regression: widening commission fields to float must not affect existing integer options.
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Quota Test',
            'price' => 29.0,
            'duration_value' => 1,
            'duration_unit' => 'month',
        ]);

        $plan->fillOptions(['product_limit' => '7']);
        $plan->save();

        $reloaded = SubscriptionPlan::query()->find($plan->id);
        $limit = $reloaded->getOption('product_limit');

        // Must stay as an integer, not 7.0
        $this->assertIsInt($limit);
        $this->assertSame(7, $limit);
    }

    public function test_legacy_plan_without_commission_keys_resolves_to_minus_one(): void
    {
        // A plan created before commission options existed will have neither key.
        // defaultOptions() merge should provide -1 for both.
        $plan = new SubscriptionPlan();
        $plan->name = 'Legacy Plan';
        $plan->price = 19.0;
        $plan->duration_value = 1;
        $plan->duration_unit = 'month';
        // Simulate an old plan: no commission keys in the options
        $plan->options = ['product_limit' => 10];
        $plan->save();

        $reloaded = SubscriptionPlan::query()->find($plan->id);

        // Legacy plans should inherit the defaults: -1 = inherit global
        $this->assertEqualsWithDelta(-1.0, $reloaded->getOption('commission_percentage'), 0.0001);
        $this->assertEqualsWithDelta(-1.0, $reloaded->getOption('commission_fixed'), 0.0001);
    }

    public function test_partial_legacy_plan_merges_defaults(): void
    {
        // A plan with some keys but not commission keys
        $plan = new SubscriptionPlan();
        $plan->name = 'Partial Legacy';
        $plan->price = 19.0;
        $plan->duration_value = 1;
        $plan->duration_unit = 'month';
        $plan->options = ['product_limit' => 5, 'featured_product_limit' => 2];
        $plan->save();

        $reloaded = SubscriptionPlan::query()->find($plan->id);

        // Existing keys should round-trip
        $this->assertSame(5, $reloaded->getOption('product_limit'));
        $this->assertSame(2, $reloaded->getOption('featured_product_limit'));
        // Missing commission keys should resolve to defaults
        $this->assertEqualsWithDelta(-1.0, $reloaded->getOption('commission_percentage'), 0.0001);
        $this->assertEqualsWithDelta(-1.0, $reloaded->getOption('commission_fixed'), 0.0001);
    }

    public function test_snapshot_captures_commission_options(): void
    {
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Snapshot Test',
            'price' => 29.0,
            'duration_value' => 1,
            'duration_unit' => 'month',
        ]);

        $plan->fillOptions([
            'commission_percentage' => '2.5',
            'commission_fixed' => '0.1',
            'product_limit' => 15,
        ]);
        $plan->save();

        $snapshot = $plan->toSnapshot();

        $this->assertArrayHasKey('options', $snapshot);
        $this->assertEqualsWithDelta(2.5, $snapshot['options']['commission_percentage'], 0.0001);
        $this->assertEqualsWithDelta(0.1, $snapshot['options']['commission_fixed'], 0.0001);
        $this->assertSame(15, $snapshot['options']['product_limit']);
    }

    public function test_vendor_subscription_option_reads_plan_data_snapshot(): void
    {
        $vendor = $this->createVendor();

        $plan = SubscriptionPlan::query()->create([
            'name' => 'Snapshot Vendor Test',
            'price' => 29.0,
            'duration_value' => 1,
            'duration_unit' => 'month',
        ]);

        $plan->fillOptions([
            'commission_percentage' => '3.75',
            'commission_fixed' => '0.15',
        ]);
        $plan->save();

        // Subscribe vendor to this plan (captures snapshot)
        $subscription = VendorSubscription::query()->create([
            'customer_id' => $vendor->id,
            'plan_id' => $plan->id,
            'plan_data' => $plan->toSnapshot(),
            'status' => 'active',
            'started_at' => now(),
            'expired_at' => now()->addMonth(),
        ]);

        // Read back via the subscription (from plan_data snapshot, not live plan)
        $this->assertEqualsWithDelta(3.75, $subscription->option('commission_percentage'), 0.0001);
        $this->assertEqualsWithDelta(0.15, $subscription->option('commission_fixed'), 0.0001);
    }

    public function test_vendor_subscription_inherits_when_keys_missing(): void
    {
        // If a vendor_subscriptions.plan_data snapshot was taken before commission
        // options existed, reading the keys should return -1 (inherit).
        $vendor = $this->createVendor();

        $plan = SubscriptionPlan::query()->create([
            'name' => 'Old Plan',
            'price' => 19.0,
            'duration_value' => 1,
            'duration_unit' => 'month',
        ]);
        $plan->save();

        // Simulate an old snapshot without commission keys
        $oldSnapshot = [
            'name' => 'Old Plan',
            'price' => 19.0,
            'duration_value' => 1,
            'duration_unit' => 'month',
            'options' => ['product_limit' => 10], // No commission keys
        ];

        $subscription = VendorSubscription::query()->create([
            'customer_id' => $vendor->id,
            'plan_id' => $plan->id,
            'plan_data' => $oldSnapshot,
            'status' => 'active',
            'started_at' => now(),
            'expired_at' => now()->addMonth(),
        ]);

        // Missing commission keys should still resolve to -1
        $this->assertEqualsWithDelta(-1.0, $subscription->option('commission_percentage'), 0.0001);
        $this->assertEqualsWithDelta(-1.0, $subscription->option('commission_fixed'), 0.0001);
    }

    public function test_zero_values_round_trip_correctly(): void
    {
        // A plan with zero commission is different from -1 (inherit).
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Zero Commission',
            'price' => 49.0,
            'duration_value' => 1,
            'duration_unit' => 'month',
        ]);

        $plan->fillOptions([
            'commission_percentage' => '0.0',
            'commission_fixed' => '0.0',
        ]);
        $plan->save();

        $reloaded = SubscriptionPlan::query()->find($plan->id);

        // Zero must survive, not become -1
        $this->assertEqualsWithDelta(0.0, $reloaded->getOption('commission_percentage'), 0.0001);
        $this->assertEqualsWithDelta(0.0, $reloaded->getOption('commission_fixed'), 0.0001);
    }

    public function test_max_percentage_value(): void
    {
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Max Percentage',
            'price' => 29.0,
            'duration_value' => 1,
            'duration_unit' => 'month',
        ]);

        $plan->fillOptions(['commission_percentage' => '100.0']);
        $plan->save();

        $reloaded = SubscriptionPlan::query()->find($plan->id);

        $this->assertEqualsWithDelta(100.0, $reloaded->getOption('commission_percentage'), 0.0001);
    }

    public function test_empty_options_dict_uses_all_defaults(): void
    {
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Empty Options',
            'price' => 29.0,
            'duration_value' => 1,
            'duration_unit' => 'month',
        ]);

        // Save with completely empty options
        $plan->options = [];
        $plan->save();

        $reloaded = SubscriptionPlan::query()->find($plan->id);

        // All keys should resolve to defaults
        $this->assertEqualsWithDelta(-1.0, $reloaded->getOption('commission_percentage'), 0.0001);
        $this->assertEqualsWithDelta(-1.0, $reloaded->getOption('commission_fixed'), 0.0001);
        $this->assertSame(-1, $reloaded->getOption('product_limit'));
    }
}
