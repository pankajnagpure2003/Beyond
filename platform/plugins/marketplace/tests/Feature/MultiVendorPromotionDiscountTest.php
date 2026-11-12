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
use Botble\Ecommerce\Services\HandleApplyPromotionsService;
use Botble\Marketplace\Enums\StoreStatusEnum;
use Botble\Marketplace\Models\Store;
use Botble\Marketplace\Providers\OrderSupportServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use ReflectionMethod;

/**
 * Promotions run through the same per vendor loop as coupons: HandleApplyPromotionsService
 * is invoked once per store with that store's subtotal, and the per store results are summed.
 * A fixed amount promotion therefore has to stay a single cart wide deduction.
 */
class MultiVendorPromotionDiscountTest extends BaseTestCase
{
    use RefreshDatabase;

    protected int $storeCounter = 0;

    public function test_fixed_amount_promotion_is_granted_once_for_a_three_store_cart(): void
    {
        $this->addStoreProductToCart(500);
        $this->addStoreProductToCart(400);
        $this->addStoreProductToCart(300);

        $this->createPromotion(['value' => 150]);

        $this->assertEquals(150, $this->promotionDiscountAmount());
    }

    public function test_fixed_amount_promotion_is_granted_once_for_a_nine_store_cart(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->addStoreProductToCart(200);
        }

        $this->createPromotion(['value' => 150]);

        $this->assertEquals(150, $this->promotionDiscountAmount());
    }

    public function test_single_store_cart_still_receives_the_full_promotion(): void
    {
        $this->addStoreProductToCart(500);

        $this->createPromotion(['value' => 150]);

        $this->assertEquals(150, $this->promotionDiscountAmount());
    }

    public function test_promotion_larger_than_the_cart_is_capped_at_the_cart_total(): void
    {
        // A fixed promotion must never exceed what the cart is actually worth.
        $this->addStoreProductToCart(80);

        $this->createPromotion(['value' => 150, 'min_order_price' => 50]);

        $this->assertEquals(80, $this->promotionDiscountAmount());
    }

    public function test_minimum_order_threshold_is_evaluated_per_store(): void
    {
        // Pre-existing behaviour, documented rather than changed: the min_order_price of a
        // cart-wide promotion is checked against each store's subtotal, so the $20 store does
        // not qualify even though the $80 cart clears the $50 threshold. This under-discounts
        // rather than over-discounts, so it is outside the scope of the multiplication fix.
        $this->addStoreProductToCart(60);
        $this->addStoreProductToCart(20);

        $this->createPromotion(['value' => 150, 'min_order_price' => 50]);

        $this->assertEquals(60, $this->promotionDiscountAmount());
    }

    public function test_percentage_promotion_still_applies_to_the_whole_cart(): void
    {
        // Percentage promotions accumulate per store by design: 10% of $1,000 = $100.
        $this->addStoreProductToCart(500);
        $this->addStoreProductToCart(300);
        $this->addStoreProductToCart(200);

        $this->createPromotion([
            'value' => 10,
            'type_option' => DiscountTypeOptionEnum::PERCENTAGE,
        ]);

        $this->assertEquals(100, $this->promotionDiscountAmount());
    }

    public function test_capped_shares_are_persisted_for_each_vendor_sub_order(): void
    {
        // handleCheckoutOrderByStore() prices each sub-order from the session share written
        // here, so this is the value that decides what the buyer is actually charged.
        $this->addStoreProductToCart(500);
        $this->addStoreProductToCart(300);
        $this->addStoreProductToCart(200);

        $this->createPromotion(['value' => 150]);

        $shares = collect($this->primeAndCapPromotions())->pluck('promotion_discount_amount');

        $this->assertCount(3, $shares);
        $this->assertEquals(150, round($shares->sum(), 2));
        $shares->each(fn ($amount) => $this->assertGreaterThan(0, $amount));
    }

    /**
     * Run the pre-loop priming step used by processPostCheckoutOrder() and return the
     * resulting per-store session data.
     */
    protected function primeAndCapPromotions(): array
    {
        $token = OrderHelper::getOrderSessionToken();
        $provider = new OrderSupportServiceProvider($this->app);

        $group = new ReflectionMethod($provider, 'cartGroupByStore');
        $group->setAccessible(true);

        $prime = new ReflectionMethod($provider, 'applyCappedPromotionsForStores');
        $prime->setAccessible(true);

        $sessionCheckoutData = $prime->invoke(
            $provider,
            $group->invoke($provider, Cart::instance('cart')->products()),
            $token,
            $this->app->make(HandleApplyPromotionsService::class)
        );

        return (array) Arr::get($sessionCheckoutData, 'marketplace', []);
    }

    /**
     * Drive the real aggregation: processShippingDiscountOrderData() runs the promotion
     * service once per store and returns the summed promotion discount at index 5.
     */
    protected function promotionDiscountAmount(): float
    {
        $token = OrderHelper::getOrderSessionToken();
        $provider = new OrderSupportServiceProvider($this->app);

        $result = $provider->processShippingDiscountOrderData(
            Cart::instance('cart')->products(),
            $token,
            OrderHelper::getOrderSessionData($token),
            new Request()
        );

        return round((float) $result[5], 2);
    }

    protected function createPromotion(array $attributes = []): Discount
    {
        return Discount::query()->create(array_merge([
            'title' => 'Test promotion',
            'type' => DiscountTypeEnum::PROMOTION,
            'type_option' => DiscountTypeOptionEnum::AMOUNT,
            'target' => DiscountTargetEnum::MINIMUM_ORDER_AMOUNT,
            'value' => 150,
            'min_order_price' => 100,
            'product_quantity' => 1,
            'can_use_with_promotion' => true,
            'can_use_with_flash_sale' => true,
            'start_date' => now()->subDay(),
        ], $attributes));
    }

    /**
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

        // store_id is not in Product::$fillable - see MultiVendorCouponCheckoutTest.
        $product->forceFill(['store_id' => $store->id])->save();

        Cart::instance('cart')->add($product->id, $product->name, 1, $price);

        return [$store, $product->refresh()];
    }
}
