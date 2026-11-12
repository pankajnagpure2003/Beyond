<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Base\Supports\BaseTestCase;

/**
 * Plan quotas live in one JSON column merged over defaultOptions(), so adding a new
 * limit back-fills every existing plan without a migration. -1 means unlimited.
 */
class SubscriptionPlanOptionsTest extends BaseTestCase
{
    protected function plan(array $options = []): SubscriptionPlan
    {
        $plan = new SubscriptionPlan();
        $plan->options = $options;

        return $plan;
    }

    public function test_missing_options_fall_back_to_defaults(): void
    {
        $plan = $this->plan(['product_limit' => 5]);

        $this->assertSame(5, $plan->getOption('product_limit'));
        $this->assertSame(
            SubscriptionPlan::defaultOptions()['featured_product_limit'],
            $plan->getOption('featured_product_limit')
        );
    }

    public function test_default_product_limit_is_unlimited(): void
    {
        $this->assertSame(-1, $this->plan()->getOption('product_limit'));
    }

    public function test_unknown_option_throws_instead_of_returning_null(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->plan()->getOption('not_a_real_option');
    }

    public function test_fill_options_drops_unknown_keys_and_casts_to_int(): void
    {
        $plan = $this->plan();
        $plan->fillOptions(['product_limit' => '7', 'bogus' => 1]);

        $this->assertSame(7, $plan->getOption('product_limit'));
        $this->assertArrayNotHasKey('bogus', $plan->getOptions());
    }

    public function test_free_plan_detection(): void
    {
        $free = new SubscriptionPlan(['price' => 0]);
        $paid = new SubscriptionPlan(['price' => 0.01]);

        $this->assertTrue($free->isFree());
        $this->assertFalse($paid->isFree());
    }

    public function test_snapshot_captures_price_duration_and_options(): void
    {
        $plan = new SubscriptionPlan([
            'name' => 'Starter',
            'price' => 19.0,
            'duration_value' => 3,
            'duration_unit' => 'month',
        ]);
        $plan->fillOptions(['product_limit' => 10]);

        $snapshot = $plan->toSnapshot();

        $this->assertSame('Starter', $snapshot['name']);
        $this->assertSame(19.0, $snapshot['price']);
        $this->assertSame(3, $snapshot['duration_value']);
        $this->assertSame('month', $snapshot['duration_unit']);
        $this->assertSame(10, $snapshot['options']['product_limit']);
    }
}
