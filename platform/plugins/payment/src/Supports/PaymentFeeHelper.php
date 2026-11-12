<?php

namespace Botble\Payment\Supports;

use Botble\Payment\Enums\PaymentFeeTypeEnum;

class PaymentFeeHelper
{
    public static function calculateFee(string $paymentMethod, float $orderAmount): float
    {
        $feeValue = (float) get_payment_setting('fee', $paymentMethod, 0);
        $feeType = get_payment_setting('fee_type', $paymentMethod, PaymentFeeTypeEnum::FIXED);

        if ($feeType !== PaymentFeeTypeEnum::PERCENTAGE) {
            // `fee_fixed` is only offered alongside a percentage fee ("2.9% + $0.30") and the
            // admin form hides it for the Fixed type. Honouring a stored value here anyway would
            // surcharge invisibly: the settings save path is a passthrough, so a value written
            // under Percentage survives a switch to Fixed and could not be cleared from the UI.
            return max(0, $feeValue);
        }

        // Clamp each component at 0 independently: a negative `fee` (pre-existing, unvalidated
        // setting) must not reduce the order total, and a negative `fee_fixed` must not offset
        // a positive base fee.
        // BREAKING: installs relying on a negative `fee` as a discount now get 0. Discounts
        // belong in the coupon/discount system, not the gateway surcharge field.
        return max(0, $orderAmount * ($feeValue / 100)) + max(0, (float) get_payment_setting('fee_fixed', $paymentMethod, 0));
    }
}
