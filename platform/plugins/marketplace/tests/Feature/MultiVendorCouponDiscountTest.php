<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Models\Discount;
use Botble\Ecommerce\Services\HandleApplyCouponService;
use Botble\Marketplace\Providers\OrderSupportServiceProvider;
use Illuminate\Support\Collection;
use ReflectionMethod;

class MultiVendorCouponDiscountTest extends BaseTestCase
{
    // Covers OrderSupportServiceProvider::capCartWideCouponDiscount() and its
    // distributeDiscountAmount() helper, which cap a cart-wide coupon so it is not
    // granted once per vendor sub-order.

    public function test_fixed_amount_coupon_is_not_multiplied_by_store_count(): void
    {
        // Three stores each granted the full $150 coupon = $450 before capping.
        $amounts = $this->distribute([1 => 150.0, 2 => 150.0, 3 => 150.0], 150.0);

        $this->assertEquals(150.0, array_sum($amounts));
        $this->assertEquals([1 => 50.0, 2 => 50.0, 3 => 50.0], $amounts);
    }

    public function test_shares_are_proportional_to_the_amount_each_store_was_granted(): void
    {
        // Subtotals $300 / $100 -> the $150 coupon granted $150 / $100 (the second store
        // is capped by its own subtotal), so the capped total splits in that same 60/40.
        $amounts = $this->distribute([1 => 150.0, 2 => 100.0], 150.0);

        $this->assertEquals(150.0, array_sum($amounts));
        $this->assertEquals(90.0, $amounts[1]);
        $this->assertEquals(60.0, $amounts[2]);
    }

    public function test_rounding_remainder_lands_on_the_last_store(): void
    {
        // 100 / 3 does not divide evenly; the shares must still total exactly 100.
        $amounts = $this->distribute([1 => 10.0, 2 => 10.0, 3 => 10.0], 100.0);

        $this->assertEquals(100.0, array_sum($amounts));
        $this->assertEquals(33.33, $amounts[1]);
        $this->assertEquals(33.33, $amounts[2]);
        $this->assertEquals(33.34, $amounts[3]);
    }

    public function test_single_store_cart_receives_the_whole_amount(): void
    {
        $amounts = $this->distribute([7 => 150.0], 150.0);

        $this->assertEquals([7 => 150.0], $amounts);
    }

    public function test_shares_are_unchanged_when_they_already_match_the_cart_wide_amount(): void
    {
        // $150 coupon on an $80 cart: each store was already capped by its own subtotal,
        // so distributing the $80 cart-wide amount must leave both shares as they were.
        $amounts = $this->distribute([1 => 50.0, 2 => 30.0], 80.0);

        $this->assertEquals(80.0, array_sum($amounts));
        $this->assertEquals(50.0, $amounts[1]);
        $this->assertEquals(30.0, $amounts[2]);
    }

    public function test_session_data_is_capped_and_untouched_keys_are_preserved(): void
    {
        // Three stores each granted the full $150 coupon; the cart-wide value is $150.
        $sessionData = $this->cap(
            [
                1 => ['coupon_discount_amount' => 150.0, 'applied_coupon_code' => 'SAVE150'],
                2 => ['coupon_discount_amount' => 150.0, 'applied_coupon_code' => 'SAVE150'],
                3 => ['coupon_discount_amount' => 150.0, 'applied_coupon_code' => 'SAVE150'],
            ],
            cartWideAmount: 150.0
        );

        $this->assertEquals(150.0, collect($sessionData)->sum('coupon_discount_amount'));
        $this->assertEquals(50.0, $sessionData[1]['coupon_discount_amount']);
        // Other session keys must survive the rewrite.
        $this->assertEquals('SAVE150', $sessionData[1]['applied_coupon_code']);
    }

    public function test_stores_the_coupon_did_not_apply_to_stay_at_zero(): void
    {
        $sessionData = $this->cap(
            [
                1 => ['coupon_discount_amount' => 150.0],
                2 => ['coupon_discount_amount' => 0, 'applied_coupon_code' => null],
                3 => ['coupon_discount_amount' => 150.0],
            ],
            cartWideAmount: 150.0
        );

        $this->assertEquals(150.0, collect($sessionData)->sum('coupon_discount_amount'));
        $this->assertEquals(75.0, $sessionData[1]['coupon_discount_amount']);
        $this->assertEquals(0, $sessionData[2]['coupon_discount_amount']);
        $this->assertEquals(75.0, $sessionData[3]['coupon_discount_amount']);
    }

    public function test_percentage_style_totals_pass_through_untouched(): void
    {
        // Percentage coupons already sum to the cart-wide value, so nothing is scaled.
        $sessionData = $this->cap(
            [
                1 => ['coupon_discount_amount' => 30.0],
                2 => ['coupon_discount_amount' => 20.0],
            ],
            cartWideAmount: 50.0
        );

        $this->assertEquals(30.0, $sessionData[1]['coupon_discount_amount']);
        $this->assertEquals(20.0, $sessionData[2]['coupon_discount_amount']);
    }

    public function test_free_shipping_coupon_with_no_discount_is_left_alone(): void
    {
        $sessionData = $this->cap(
            [
                1 => ['coupon_discount_amount' => 0, 'is_free_shipping' => true],
                2 => ['coupon_discount_amount' => 0, 'is_free_shipping' => true],
            ],
            cartWideAmount: 0.0
        );

        $this->assertEquals(0, collect($sessionData)->sum('coupon_discount_amount'));
        $this->assertTrue($sessionData[1]['is_free_shipping']);
    }

    protected function cap(array $sessionMarketplaceData, float $cartWideAmount): array
    {
        // Stub the coupon service so the cart-wide amount is fixed and no cart is needed.
        $this->app->instance(HandleApplyCouponService::class, new class ($cartWideAmount) extends HandleApplyCouponService {
            public function __construct(protected float $cartWideAmount)
            {
            }

            public function getCouponDiscountAmount($discount, array $cartData = [], array $sessionData = []): array
            {
                return ['discount_amount' => $this->cartWideAmount, 'valid_cart_item_ids' => collect()];
            }
        });

        $results = new Collection([
            1 => ['error' => false, 'data' => ['discount' => new Discount()]],
        ]);

        $provider = new OrderSupportServiceProvider($this->app);

        $method = new ReflectionMethod($provider, 'capCartWideCouponDiscount');
        $method->setAccessible(true);

        return $method->invoke($provider, $sessionMarketplaceData, $results);
    }

    protected function distribute(array $amounts, float $maxAmount): array
    {
        $provider = new OrderSupportServiceProvider($this->app);

        $method = new ReflectionMethod($provider, 'distributeDiscountAmount');
        $method->setAccessible(true);

        return $method->invoke($provider, new Collection($amounts), $maxAmount);
    }
}
