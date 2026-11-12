<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Enums\DiscountTargetEnum;
use Botble\Ecommerce\Enums\DiscountTypeEnum;
use Botble\Ecommerce\Enums\DiscountTypeOptionEnum;
use Botble\Ecommerce\Facades\Cart;
use Botble\Ecommerce\Facades\OrderHelper;
use Botble\Ecommerce\Models\Customer;
use Botble\Ecommerce\Models\Discount;
use Botble\Ecommerce\Models\Product;
use Botble\Marketplace\Enums\StoreStatusEnum;
use Botble\Marketplace\Models\Store;
use Botble\Marketplace\Providers\OrderSupportServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * End to end coverage for the multi vendor coupon flow: a coupon is applied once per vendor
 * sub-order, so without a cart wide cap a fixed amount coupon is granted N times for a cart
 * spanning N stores.
 */
class MultiVendorCouponCheckoutTest extends BaseTestCase
{
    use RefreshDatabase;

    protected int $storeCounter = 0;

    public function test_fixed_amount_coupon_is_granted_once_for_a_three_store_cart(): void
    {
        // $500 + $400 + $300 = $1,200 cart spread over three vendors.
        $this->addStoreProductToCart(500);
        $this->addStoreProductToCart(400);
        $this->addStoreProductToCart(300);

        $this->createCoupon(['value' => 150]);

        // Before the cap this returned 450 (150 per store).
        $this->assertEquals(150, $this->applyCoupon());
    }

    public function test_fixed_amount_coupon_is_granted_once_for_a_nine_store_cart(): void
    {
        // The reported worst case: the demo site carries nine vendor stores.
        for ($i = 0; $i < 9; $i++) {
            $this->addStoreProductToCart(200);
        }

        $this->createCoupon(['value' => 150]);

        $this->assertEquals(150, $this->applyCoupon());
    }

    public function test_single_store_cart_still_receives_the_full_coupon(): void
    {
        $this->addStoreProductToCart(500);

        $this->createCoupon(['value' => 150]);

        $this->assertEquals(150, $this->applyCoupon());
    }

    public function test_coupon_larger_than_the_cart_is_capped_at_the_cart_total(): void
    {
        // $60 + $20 = $80 cart, $150 coupon.
        $this->addStoreProductToCart(60);
        $this->addStoreProductToCart(20);

        $this->createCoupon(['value' => 150]);

        $this->assertEquals(80, $this->applyCoupon());
    }

    public function test_per_store_amounts_sum_to_the_cart_wide_total(): void
    {
        // Vendor sub-orders keep their own share, which must add up to the capped total.
        $this->addStoreProductToCart(500);
        $this->addStoreProductToCart(300);
        $this->addStoreProductToCart(200);

        $this->createCoupon(['value' => 150]);

        $total = $this->applyCoupon();
        $storeAmounts = collect($this->marketplaceSessionData())->pluck('coupon_discount_amount');

        $this->assertCount(3, $storeAmounts);
        $this->assertEquals($total, round($storeAmounts->sum(), 2));
        $storeAmounts->each(fn ($amount) => $this->assertGreaterThan(0, $amount));
    }

    public function test_minimum_order_amount_coupon_is_granted_once(): void
    {
        $this->addStoreProductToCart(500);
        $this->addStoreProductToCart(400);

        $this->createCoupon([
            'value' => 100,
            'target' => DiscountTargetEnum::MINIMUM_ORDER_AMOUNT,
            'min_order_price' => 300,
        ]);

        $this->assertEquals(100, $this->applyCoupon());
    }

    public function test_percentage_coupon_still_applies_to_the_whole_cart(): void
    {
        // Percentage coupons accumulate per store by design: 10% of $1,000 = $100.
        $this->addStoreProductToCart(500);
        $this->addStoreProductToCart(300);
        $this->addStoreProductToCart(200);

        $this->createCoupon([
            'value' => 10,
            'type_option' => DiscountTypeOptionEnum::PERCENTAGE,
        ]);

        $this->assertEquals(100, $this->applyCoupon());
    }

    public function test_vendor_scoped_coupon_applies_to_its_own_store_only(): void
    {
        [$store] = $this->addStoreProductToCart(500);
        $this->addStoreProductToCart(400);

        $coupon = $this->createCoupon(['value' => 150]);
        $coupon->forceFill(['store_id' => $store->id])->save();

        $this->assertEquals(150, $this->applyCoupon());

        $storeAmounts = collect($this->marketplaceSessionData());
        $this->assertEquals(150, Arr::get($storeAmounts->get($store->id), 'coupon_discount_amount'));
    }

    public function test_product_coupon_applied_per_order_is_granted_once_across_stores(): void
    {
        // The same product coupon matched in two stores previously paid out twice.
        [, $firstProduct] = $this->addStoreProductToCart(500);
        [, $secondProduct] = $this->addStoreProductToCart(400);

        $coupon = $this->createCoupon([
            'value' => 100,
            'target' => DiscountTargetEnum::SPECIFIC_PRODUCT,
            'discount_on' => 'per-order',
        ]);
        $coupon->products()->sync([$firstProduct->id, $secondProduct->id]);

        $this->assertEquals(100, $this->applyCoupon());
    }

    public function test_product_coupon_applied_per_item_still_accumulates(): void
    {
        // per-every-item is meant to add up: $20 off each of the two matched items.
        [, $firstProduct] = $this->addStoreProductToCart(500);
        [, $secondProduct] = $this->addStoreProductToCart(400);

        $coupon = $this->createCoupon([
            'value' => 20,
            'target' => DiscountTargetEnum::SPECIFIC_PRODUCT,
            'discount_on' => 'per-every-item',
        ]);
        $coupon->products()->sync([$firstProduct->id, $secondProduct->id]);

        $this->assertEquals(40, $this->applyCoupon());
    }

    public function test_free_shipping_coupon_keeps_its_flag_and_adds_no_discount(): void
    {
        $this->addStoreProductToCart(500);
        $this->addStoreProductToCart(400);

        $this->createCoupon([
            'value' => 100,
            'type_option' => DiscountTypeOptionEnum::SHIPPING,
        ]);

        $this->assertEquals(0, $this->applyCoupon());

        foreach ($this->marketplaceSessionData() as $storeData) {
            $this->assertTrue(Arr::get($storeData, 'is_free_shipping'));
        }
    }

    public function test_reapplying_the_same_coupon_does_not_shrink_it_further(): void
    {
        // processGetCheckoutData() re-runs the apply flow on every checkout page load, so
        // capping must be idempotent rather than compounding.
        $this->addStoreProductToCart(500);
        $this->addStoreProductToCart(400);
        $this->addStoreProductToCart(300);

        $this->createCoupon(['value' => 150]);

        $this->assertEquals(150, $this->applyCoupon());
        $this->assertEquals(150, $this->applyCoupon());
        $this->assertEquals(150, $this->applyCoupon());
    }

    protected function applyCoupon(string $code = 'SAVE150'): float
    {
        $provider = new OrderSupportServiceProvider($this->app);
        $provider->processApplyCouponCode([], new Request(['coupon_code' => $code]));

        $sessionData = OrderHelper::getOrderSessionData(OrderHelper::getOrderSessionToken());

        return round((float) Arr::get($sessionData, 'coupon_discount_amount', 0), 2);
    }

    protected function marketplaceSessionData(): array
    {
        $sessionData = OrderHelper::getOrderSessionData(OrderHelper::getOrderSessionToken());

        return (array) Arr::get($sessionData, 'marketplace', []);
    }

    protected function createCoupon(array $attributes = []): Discount
    {
        return Discount::query()->create(array_merge([
            'title' => 'Test coupon',
            'code' => 'SAVE150',
            'type' => DiscountTypeEnum::COUPON,
            'type_option' => DiscountTypeOptionEnum::AMOUNT,
            'target' => DiscountTargetEnum::ALL_ORDERS,
            'value' => 150,
            'quantity' => 100,
            'total_used' => 0,
            'can_use_with_promotion' => true,
            'can_use_with_flash_sale' => true,
            'start_date' => now()->subDay(),
        ], $attributes));
    }

    /**
     * Create a vendor store holding one product and put that product in the cart.
     *
     * @return array{0: Store, 1: Product}
     */
    protected function addStoreProductToCart(float $price): array
    {
        $index = ++$this->storeCounter;

        $customer = Customer::query()->create([
            'name' => "Vendor $index",
            'email' => "vendor$index@example.com",
            'password' => bcrypt('password'),
        ]);

        $store = Store::query()->create([
            'name' => "Store $index",
            'customer_id' => $customer->id,
            'status' => StoreStatusEnum::PUBLISHED,
        ]);

        $product = Product::query()->create([
            'name' => "Product $index",
            'price' => $price,
            'status' => BaseStatusEnum::PUBLISHED,
            'quantity' => 100,
            'with_storehouse_management' => false,
        ]);

        // store_id is added to ec_products by marketplace and is not in Product::$fillable,
        // so it has to be forced - mass assignment drops it and every product would end up
        // grouped under store 0, which would make these tests pass without proving anything.
        $product->forceFill(['store_id' => $store->id])->save();

        Cart::instance('cart')->add($product->id, $product->name, 1, $price);

        return [$store, $product->refresh()];
    }
}
