<?php

namespace Botble\Marketplace\Tests\Unit;

use Botble\Marketplace\Supports\CommissionRate;
use Botble\Base\Supports\BaseTestCase;

/**
 * CommissionRate semantics: negative values clamp to zero, -1 is not special (that's
 * CommissionFeeResolver's job), and both parts resolve independently.
 */
/**
 * Extends BaseTestCase, not a bare PHPUnit TestCase: plugin classes are registered by
 * Botble's boot-time autoloader, not composer, so a bare TestCase cannot resolve
 * CommissionRate unless some other test happens to boot the framework first. (That is why
 * WithdrawalFeeCalculationTest cannot run standalone.) No database is touched here.
 */
class CommissionRateResolutionTest extends BaseTestCase
{
    public function test_negative_values_clamp_to_zero(): void
    {
        $rate = new CommissionRate(-5.0, -2.5);

        $this->assertEqualsWithDelta(0.0, $rate->percentage, 0.0001);
        $this->assertEqualsWithDelta(0.0, $rate->fixed, 0.0001);
    }

    public function test_zero_values_are_kept(): void
    {
        $rate = new CommissionRate(0.0, 0.0);

        $this->assertEqualsWithDelta(0.0, $rate->percentage, 0.0001);
        $this->assertEqualsWithDelta(0.0, $rate->fixed, 0.0001);
    }

    public function test_positive_percentage_is_preserved(): void
    {
        $rate = new CommissionRate(4.5, 0.0);

        $this->assertEqualsWithDelta(4.5, $rate->percentage, 0.0001);
    }

    public function test_positive_fixed_is_preserved(): void
    {
        $rate = new CommissionRate(0.0, 0.25);

        $this->assertEqualsWithDelta(0.25, $rate->fixed, 0.0001);
    }

    public function test_both_parts_resolve_independently(): void
    {
        $rate = new CommissionRate(4.5, 0.25);

        $this->assertEqualsWithDelta(4.5, $rate->percentage, 0.0001);
        $this->assertEqualsWithDelta(0.25, $rate->fixed, 0.0001);
    }

    public function test_none_method_creates_zero_rate(): void
    {
        $rate = CommissionRate::none();

        $this->assertEqualsWithDelta(0.0, $rate->percentage, 0.0001);
        $this->assertEqualsWithDelta(0.0, $rate->fixed, 0.0001);
    }

    public function test_is_none_true_when_both_zero(): void
    {
        $rate = new CommissionRate(0.0, 0.0);

        $this->assertTrue($rate->isNone());
    }

    public function test_is_none_true_when_both_negative_clamp_to_zero(): void
    {
        $rate = new CommissionRate(-1.0, -1.0);

        $this->assertTrue($rate->isNone());
    }

    public function test_is_none_false_with_percentage_only(): void
    {
        $rate = new CommissionRate(4.5, 0.0);

        $this->assertFalse($rate->isNone());
    }

    public function test_is_none_false_with_fixed_only(): void
    {
        $rate = new CommissionRate(0.0, 0.25);

        $this->assertFalse($rate->isNone());
    }

    public function test_is_none_false_with_both(): void
    {
        $rate = new CommissionRate(4.5, 0.25);

        $this->assertFalse($rate->isNone());
    }

    public function test_sentinel_minus_one_is_not_special(): void
    {
        // CommissionFeeResolver handles -1 semantics, not CommissionRate.
        // A rate with -1 is invalid input, but the constructor clamps it to 0.
        $rate = new CommissionRate(-1.0, -1.0);

        $this->assertEqualsWithDelta(0.0, $rate->percentage, 0.0001);
        $this->assertEqualsWithDelta(0.0, $rate->fixed, 0.0001);
    }

    public function test_large_percentage_values_are_preserved(): void
    {
        $rate = new CommissionRate(99.5, 0.0);

        $this->assertEqualsWithDelta(99.5, $rate->percentage, 0.0001);
    }

    public function test_large_fixed_values_are_preserved(): void
    {
        $rate = new CommissionRate(0.0, 50.0);

        $this->assertEqualsWithDelta(50.0, $rate->fixed, 0.0001);
    }

    public function test_decimal_precision_is_preserved(): void
    {
        $rate = new CommissionRate(4.567, 0.129);

        $this->assertEqualsWithDelta(4.567, $rate->percentage, 0.00001);
        $this->assertEqualsWithDelta(0.129, $rate->fixed, 0.00001);
    }
}
