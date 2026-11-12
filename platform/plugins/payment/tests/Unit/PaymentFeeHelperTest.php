<?php

namespace Botble\Payment\Tests\Unit;

use PHPUnit\Framework\TestCase;

// Picked up by the "Plugins" testsuite in phpunit.xml, which globs platform/plugins/*/tests.
// Can also be run alone: `vendor/bin/phpunit platform/plugins/payment/tests/`.
//
// Botble plugins (unlike platform/core packages) are not registered on Composer's PSR-4 map —
// they are only autoloadable once the full Laravel app boots and the plugin manager registers
// them at runtime. A plain PHPUnit\Framework\TestCase run never boots the app, so the source
// files under test (and a stand-in for the get_payment_setting() global helper) are pulled in
// via a namespace-less bootstrap file — see that file for why it can't simply be inlined here.
require_once __DIR__ . '/payment-fee-helper-stub-bootstrap.php';

use Botble\Payment\Supports\PaymentFeeHelper;

class PaymentFeeHelperTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    public static array $settings = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::$settings = [];
    }

    private function seed(string $method, array $values): void
    {
        self::$settings[$method] = $values;
    }

    public function test_percentage_only_fee(): void
    {
        $this->seed('stripe', ['fee' => 2.9, 'fee_type' => 'percentage', 'fee_fixed' => 0]);

        $this->assertEqualsWithDelta(2.9, PaymentFeeHelper::calculateFee('stripe', 100), 0.0001);
    }

    public function test_fixed_only_fee(): void
    {
        $this->seed('cod', ['fee' => 5, 'fee_type' => 'fixed', 'fee_fixed' => 0]);

        $this->assertEqualsWithDelta(5.0, PaymentFeeHelper::calculateFee('cod', 100), 0.0001);
    }

    public function test_percentage_plus_fixed_fee_is_additive(): void
    {
        // Stripe-style pricing: 2.9% + $0.30 on a $100 order => 2.90 + 0.30 = 3.20
        $this->seed('stripe', ['fee' => 2.9, 'fee_type' => 'percentage', 'fee_fixed' => 0.30]);

        $this->assertEqualsWithDelta(3.20, PaymentFeeHelper::calculateFee('stripe', 100), 0.0001);
    }

    public function test_fee_fixed_absent_from_settings_is_backward_compatible(): void
    {
        // Pre-upgrade installs never wrote a `fee_fixed` key at all. get_payment_setting()
        // falls back to its $default of 0, so output stays byte-identical to before this change.
        self::$settings['bank_transfer'] = ['fee' => 15, 'fee_type' => 'fixed'];

        $this->assertEqualsWithDelta(15.0, PaymentFeeHelper::calculateFee('bank_transfer', 200), 0.0001);
    }

    public function test_zero_fee_and_zero_fee_fixed_is_a_no_op(): void
    {
        $this->seed('paypal', ['fee' => 0, 'fee_type' => 'percentage', 'fee_fixed' => 0]);

        $this->assertSame(0.0, PaymentFeeHelper::calculateFee('paypal', 500));
    }

    public function test_negative_percentage_fee_is_clamped_at_zero(): void
    {
        $this->seed('stripe', ['fee' => -10, 'fee_type' => 'percentage', 'fee_fixed' => 0]);

        $this->assertSame(0.0, PaymentFeeHelper::calculateFee('stripe', 100));
    }

    public function test_negative_fixed_fee_component_is_clamped_at_zero(): void
    {
        $this->seed('stripe', ['fee' => 5, 'fee_type' => 'fixed', 'fee_fixed' => -3]);

        // base = 5 (fixed), fee_fixed clamps to 0 => total 5, never reduced by a negative surcharge
        $this->assertSame(5.0, PaymentFeeHelper::calculateFee('stripe', 100));
    }

    public function test_negative_base_fee_does_not_offset_a_positive_fixed_fee(): void
    {
        $this->seed('stripe', ['fee' => -10, 'fee_type' => 'percentage', 'fee_fixed' => 2]);

        // base clamps to 0, fee_fixed stays 2 => total 2 (not -8, which a naive sum-then-clamp would produce)
        $this->assertSame(2.0, PaymentFeeHelper::calculateFee('stripe', 100));
    }

    public function test_fee_fixed_is_ignored_when_fee_type_is_fixed(): void
    {
        // The admin form hides fee_fixed for the Fixed type. A value stored earlier under
        // Percentage survives the switch (the settings save path is a passthrough), so honouring
        // it here would surcharge invisibly with no way to clear it from the UI.
        $this->seed('stripe', ['fee' => 5, 'fee_type' => 'fixed', 'fee_fixed' => 0.30]);

        $this->assertSame(5.0, PaymentFeeHelper::calculateFee('stripe', 100));
    }
}
