<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Marketplace\Enums\SubscriptionDurationUnitEnum;
use Botble\Marketplace\Models\SubscriptionPlan;
use Carbon\Carbon;
use Botble\Base\Supports\BaseTestCase;

class SubscriptionDurationTest extends BaseTestCase
{
    public function test_each_unit_advances_the_end_date(): void
    {
        $from = Carbon::parse('2026-01-31 10:00:00');

        $this->assertSame(
            '2026-02-07',
            SubscriptionDurationUnitEnum::addTo(SubscriptionDurationUnitEnum::DAY, 7, $from)->toDateString()
        );
        $this->assertSame(
            '2026-02-28',
            SubscriptionDurationUnitEnum::addTo(SubscriptionDurationUnitEnum::MONTH, 1, $from)->toDateString()
        );
        $this->assertSame(
            '2027-01-31',
            SubscriptionDurationUnitEnum::addTo(SubscriptionDurationUnitEnum::YEAR, 1, $from)->toDateString()
        );
    }

    public function test_lifetime_has_no_end_date(): void
    {
        $this->assertNull(
            SubscriptionDurationUnitEnum::addTo(SubscriptionDurationUnitEnum::LIFETIME, 1, Carbon::now())
        );
    }

    public function test_plan_calculates_its_own_period_end(): void
    {
        $plan = new SubscriptionPlan(['duration_value' => 2, 'duration_unit' => 'month']);

        $this->assertSame(
            '2026-03-01',
            $plan->calculateEndsAt(Carbon::parse('2026-01-01'))->toDateString()
        );
    }

    public function test_lifetime_plan_never_expires(): void
    {
        $plan = new SubscriptionPlan(['duration_value' => 1, 'duration_unit' => 'lifetime']);

        $this->assertTrue($plan->isLifetime());
        $this->assertNull($plan->calculateEndsAt(Carbon::now()));
    }

    public function test_adding_to_a_date_does_not_mutate_the_original(): void
    {
        $from = Carbon::parse('2026-01-01');

        SubscriptionDurationUnitEnum::addTo(SubscriptionDurationUnitEnum::MONTH, 1, $from);

        $this->assertSame('2026-01-01', $from->toDateString());
    }
}
