<?php

namespace Botble\Marketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Hybrid mode commission arithmetic: both percentage and fixed apply to orders,
 * but refunds only reverse the percentage (not the fixed fee, which is charged once).
 */
class HybridCommissionFeeCalculationTest extends TestCase
{
    public function test_order_fee_percentage_only(): void
    {
        [$orderFee, $refundFee] = $this->calculateHybridCommission(
            subAmount: 100,
            percentage: 4.5,
            fixedFee: 0.0
        );

        $this->assertEqualsWithDelta(4.5, $orderFee, 0.0001);
        $this->assertEqualsWithDelta(4.5, $refundFee, 0.0001);
    }

    public function test_order_fee_fixed_only(): void
    {
        [$orderFee, $refundFee] = $this->calculateHybridCommission(
            subAmount: 100,
            percentage: 0.0,
            fixedFee: 0.25
        );

        $this->assertEqualsWithDelta(0.25, $orderFee, 0.0001);
        $this->assertEqualsWithDelta(0.0, $refundFee, 0.0001);
    }

    public function test_order_fee_percentage_plus_fixed(): void
    {
        [$orderFee, $refundFee] = $this->calculateHybridCommission(
            subAmount: 10.0,
            percentage: 4.5,
            fixedFee: 0.25
        );

        // Order: 10 * 4.5% + 0.25 = 0.70
        $this->assertEqualsWithDelta(0.70, $orderFee, 0.0001);
        // Refund: only the percentage part = 0.45
        $this->assertEqualsWithDelta(0.45, $refundFee, 0.0001);
    }

    public function test_refund_excludes_fixed_fee(): void
    {
        [$orderFee, $refundFee] = $this->calculateHybridCommission(
            subAmount: 50.0,
            percentage: 5.0,
            fixedFee: 1.0
        );

        // Order: 50 * 5% + 1.0 = 3.50
        $this->assertEqualsWithDelta(3.50, $orderFee, 0.0001);
        // Refund: only percentage = 50 * 5% = 2.50 (fixed not refunded)
        $this->assertEqualsWithDelta(2.50, $refundFee, 0.0001);
    }

    public function test_order_fee_capped_at_sub_amount(): void
    {
        [$orderFee, $refundFee] = $this->calculateHybridCommission(
            subAmount: 0.20,
            percentage: 4.5,
            fixedFee: 0.25
        );

        // Order: raw = 0.009 + 0.25 = 0.259, capped at 0.20
        $this->assertEqualsWithDelta(0.20, $orderFee, 0.0001);
        // Refund: 0.20 * 4.5% = 0.009 (just the percentage part of the capped fee)
        $this->assertEqualsWithDelta(0.009, $refundFee, 0.0001);
    }

    public function test_refund_preserves_percentage_precision(): void
    {
        [$orderFee, $refundFee] = $this->calculateHybridCommission(
            subAmount: 9.99,
            percentage: 4.5,
            fixedFee: 0.25
        );

        // Order: 9.99 * 4.5% = 0.44955, + 0.25 = 0.69955
        $this->assertEqualsWithDelta(0.69955, $orderFee, 0.0001);
        // Refund: only percentage 9.99 * 4.5% = 0.44955
        $this->assertEqualsWithDelta(0.44955, $refundFee, 0.0001);
    }

    public function test_zero_percentage_with_fixed_fee(): void
    {
        [$orderFee, $refundFee] = $this->calculateHybridCommission(
            subAmount: 100.0,
            percentage: 0.0,
            fixedFee: 0.50
        );

        // Order: 0% + 0.50 fixed = 0.50
        $this->assertEqualsWithDelta(0.50, $orderFee, 0.0001);
        // Refund: 0% (no percentage to refund)
        $this->assertEqualsWithDelta(0.0, $refundFee, 0.0001);
    }

    public function test_both_zero_charges_nothing(): void
    {
        [$orderFee, $refundFee] = $this->calculateHybridCommission(
            subAmount: 100.0,
            percentage: 0.0,
            fixedFee: 0.0
        );

        $this->assertEqualsWithDelta(0.0, $orderFee, 0.0001);
        $this->assertEqualsWithDelta(0.0, $refundFee, 0.0001);
    }

    public function test_large_fixed_fee_capped_at_sub_amount(): void
    {
        [$orderFee, $refundFee] = $this->calculateHybridCommission(
            subAmount: 5.0,
            percentage: 10.0,
            fixedFee: 100.0
        );

        // Order: 5 * 10% + 100 = 100.50, capped at 5.0
        $this->assertEqualsWithDelta(5.0, $orderFee, 0.0001);
        // Refund: 5 * 10% = 0.50 (percentage part of the capped amount)
        $this->assertEqualsWithDelta(0.50, $refundFee, 0.0001);
    }

    public function test_zero_sub_amount_charges_nothing(): void
    {
        [$orderFee, $refundFee] = $this->calculateHybridCommission(
            subAmount: 0.0,
            percentage: 4.5,
            fixedFee: 0.25
        );

        $this->assertEqualsWithDelta(0.0, $orderFee, 0.0001);
        $this->assertEqualsWithDelta(0.0, $refundFee, 0.0001);
    }

    public function test_refund_amount_matches_percentage_of_capped_fee(): void
    {
        // When order fee is capped, the refund should be capped too.
        [$orderFee, $refundFee] = $this->calculateHybridCommission(
            subAmount: 3.0,
            percentage: 20.0,
            fixedFee: 5.0
        );

        // Order: 3 * 20% + 5 = 5.60, capped at 3.0
        $this->assertEqualsWithDelta(3.0, $orderFee, 0.0001);
        // Refund: The refund should be the percentage part of capped fee (3.0 * 20% = 0.60)
        $this->assertEqualsWithDelta(0.60, $refundFee, 0.0001);
    }

    public function test_very_small_percentage(): void
    {
        [$orderFee, $refundFee] = $this->calculateHybridCommission(
            subAmount: 100.0,
            percentage: 0.1,
            fixedFee: 0.25
        );

        // Order: 100 * 0.1% + 0.25 = 0.35
        $this->assertEqualsWithDelta(0.35, $orderFee, 0.0001);
        // Refund: 100 * 0.1% = 0.10
        $this->assertEqualsWithDelta(0.10, $refundFee, 0.0001);
    }

    public function test_refund_on_10_dollar_order_with_global_rates(): void
    {
        // Reported scenario: 4.5% + €0.25 on a €10 cart
        [$orderFee, $refundFee] = $this->calculateHybridCommission(
            subAmount: 10.0,
            percentage: 4.5,
            fixedFee: 0.25
        );

        // Order fee: 10 * 4.5% + 0.25 = 0.70
        $this->assertEqualsWithDelta(0.70, $orderFee, 0.0001);
        // Refund fee: only percentage 10 * 4.5% = 0.45
        $this->assertEqualsWithDelta(0.45, $refundFee, 0.0001);
    }

    /**
     * Hybrid commission arithmetic:
     * - Order fee: (subAmount * percentage / 100) + fixedFee, capped at subAmount
     * - Refund fee: (subAmount * percentage / 100), never includes the fixed fee
     *
     * @return array{float, float} [$orderFee, $refundFee]
     */
    protected function calculateHybridCommission(float $subAmount, float $percentage, float $fixedFee): array
    {
        $percentagePart = $subAmount * ($percentage / 100);
        $orderFee = $percentagePart + $fixedFee;
        $orderFee = min($orderFee, $subAmount);

        // Refund only reverses the percentage part
        $refundFee = $subAmount * ($percentage / 100);

        return [$orderFee, $refundFee];
    }
}
