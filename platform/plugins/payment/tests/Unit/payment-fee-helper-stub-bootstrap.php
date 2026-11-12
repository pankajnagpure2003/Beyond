<?php

// No namespace declared here on purpose: `get_payment_setting()` is a *global* function in
// production (platform/plugins/payment/helpers/helpers.php), and PaymentFeeHelper.php calls it
// unqualified, relying on PHP's global-function fallback for the `Botble\Payment\Supports`
// namespace. Declaring the stub inside a namespaced file (e.g. directly in the test class file)
// would define `Botble\Payment\Tests\Unit\get_payment_setting()` instead of the global one, and
// the fallback would never find it — hence this separate, namespace-less bootstrap file.

require_once __DIR__ . '/../../src/Enums/PaymentFeeTypeEnum.php';
require_once __DIR__ . '/../../src/Supports/PaymentFeeHelper.php';

if (! function_exists('get_payment_setting')) {
    /**
     * Stand-in for the real get_payment_setting() helper, which wraps the setting() facade
     * (DB-backed, requires a booted Laravel app). Tests seed values through
     * PaymentFeeHelperTest::$settings before calling PaymentFeeHelper::calculateFee().
     */
    function get_payment_setting(string $key, $type = null, $default = null)
    {
        return \Botble\Payment\Tests\Unit\PaymentFeeHelperTest::$settings[$type][$key] ?? $default;
    }
}
