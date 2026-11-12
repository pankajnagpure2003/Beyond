<?php

namespace Botble\Ecommerce\Tests\Feature;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Enums\DiscountTargetEnum;
use Botble\Ecommerce\Enums\DiscountTypeEnum;
use Botble\Ecommerce\Enums\DiscountTypeOptionEnum;
use Botble\Ecommerce\Facades\Cart;
use Botble\Ecommerce\Models\Discount;
use Botble\Ecommerce\Models\Product;
use Botble\Ecommerce\Models\ProductCollection;
use Botble\Ecommerce\Services\HandleApplyPromotionsService;
use Botble\Ecommerce\Services\PromotionCacheService;
use Botble\Ecommerce\ValueObjects\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A promotion is a public price cut, so it must render on the product page, in listings and in
 * the API - not only inside the cart. isOnSale() gates the displayed price, so when it ignores
 * promotions the storefront keeps showing the full price while the cart charges the discounted
 * one.
 */
class PromotionOnProductPageTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Available promotions are memoised per process.
        (new PromotionCacheService())->flush();
        Cart::instance('cart')->destroy();
    }

    public function test_product_matched_by_a_collection_promotion_is_on_sale(): void
    {
        $product = $this->createProductInPromotedCollection(price: 210, percentage: 10);

        $this->assertTrue($product->isOnSale());
        $this->assertEqualsWithDelta(189, $product->front_sale_price, 0.01);
        $this->assertEqualsWithDelta(189, ProductPrice::make($product)->getPrice(), 0.01);
        $this->assertEquals(10, $product->sale_percent);
    }

    public function test_product_without_any_discount_is_not_on_sale(): void
    {
        $product = Product::query()->create([
            'name' => 'Plain product',
            'price' => 210,
            'status' => BaseStatusEnum::PUBLISHED,
            'quantity' => 100,
            'with_storehouse_management' => false,
        ]);

        $this->assertFalse($product->isOnSale());
        $this->assertEqualsWithDelta(210, ProductPrice::make($product)->getPrice(), 0.01);
        $this->assertEquals(0, $product->sale_percent);
    }

    public function test_promotion_requiring_more_than_one_product_stays_out_of_the_product_page(): void
    {
        // Cart-only promotions must not change the single product price.
        $product = $this->createProductInPromotedCollection(price: 210, percentage: 10, productQuantity: 3);

        $this->assertFalse($product->isOnSale());
        $this->assertEqualsWithDelta(210, ProductPrice::make($product)->getPrice(), 0.01);
    }

    public function test_single_product_promotion_is_not_discounted_a_second_time_on_the_order_total(): void
    {
        // The promotion is already baked into the item price, so subtracting it from the order
        // total as well would hand the customer the discount twice.
        $product = $this->createProductInPromotedCollection(price: 210, percentage: 10);

        Cart::instance('cart')->add($product->getKey(), $product->name, 1, $product->front_sale_price);

        $this->assertEqualsWithDelta(189, Cart::instance('cart')->rawTotal(), 0.01);
        $this->assertEquals(0, round((float) app(HandleApplyPromotionsService::class)->getPromotionDiscountAmount(), 2));
    }

    public function test_expired_scheduled_sale_price_does_not_report_a_discount_percentage(): void
    {
        // The sale window has closed, so the pipeline keeps the base price and the badge must
        // not advertise a reduction the customer will not get.
        $product = Product::query()->create([
            'name' => 'Expired sale product',
            'price' => 210,
            'sale_price' => 189,
            'sale_type' => 1,
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDay(),
            'status' => BaseStatusEnum::PUBLISHED,
            'quantity' => 100,
            'with_storehouse_management' => false,
        ]);

        $this->assertEqualsWithDelta(210, ProductPrice::make($product)->getPrice(), 0.01);
        $this->assertEquals(0, $product->sale_percent);
    }

    protected function createProductInPromotedCollection(
        float $price,
        float $percentage,
        int $productQuantity = 1
    ): Product {
        $product = Product::query()->create([
            'name' => 'Promoted product',
            'price' => $price,
            'status' => BaseStatusEnum::PUBLISHED,
            'quantity' => 100,
            'with_storehouse_management' => false,
        ]);

        $collection = ProductCollection::query()->create([
            'name' => 'Top Partner Brands',
            'status' => BaseStatusEnum::PUBLISHED,
        ]);

        $product->productCollections()->sync([$collection->getKey()]);

        $promotion = Discount::query()->create([
            'title' => 'Collection promotion',
            'type' => DiscountTypeEnum::PROMOTION,
            'type_option' => DiscountTypeOptionEnum::PERCENTAGE,
            'target' => DiscountTargetEnum::PRODUCT_COLLECTIONS,
            'value' => $percentage,
            'product_quantity' => $productQuantity,
            'start_date' => now()->subDay(),
        ]);

        $promotion->productCollections()->sync([$collection->getKey()]);

        return $product->refresh();
    }
}
