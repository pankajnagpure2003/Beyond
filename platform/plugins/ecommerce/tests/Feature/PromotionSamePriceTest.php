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
use Botble\Ecommerce\Services\HandleApplyPromotionsService;
use Botble\Ecommerce\Services\PromotionCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A same-price promotion sets a fixed price for the matched items, so the discount is the
 * difference between the item price and that price. Configured above the item's own price the
 * difference goes negative, which would inflate the order total and overcharge the customer.
 */
class PromotionSamePriceTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Available promotions are memoised per process, so a promotion created by one test
        // would otherwise be invisible to the next.
        (new PromotionCacheService())->flush();
        Cart::instance('cart')->destroy();
    }

    public function test_promotion_priced_above_the_item_does_not_become_a_surcharge(): void
    {
        // $80 "same price" on a $50 item: the difference is -$30 per unit.
        $this->addPromotionProductToCart(price: 50, promotionValue: 80, qty: 2);

        $this->assertEquals(0, $this->promotionDiscountAmount());
    }

    public function test_promotion_priced_below_the_item_still_discounts_the_difference(): void
    {
        // $30 "same price" on a $50 item, 2 units -> $40 off.
        $this->addPromotionProductToCart(price: 50, promotionValue: 30, qty: 2);

        $this->assertEquals(40, $this->promotionDiscountAmount());
    }

    public function test_promotion_priced_at_the_item_price_discounts_nothing(): void
    {
        $this->addPromotionProductToCart(price: 50, promotionValue: 50, qty: 2);

        $this->assertEquals(0, $this->promotionDiscountAmount());
    }

    protected function promotionDiscountAmount(): float
    {
        $result = app(HandleApplyPromotionsService::class)->getPromotionDiscountAmount();

        return round((float) $result, 2);
    }

    protected function addPromotionProductToCart(float $price, float $promotionValue, int $qty): Product
    {
        $product = Product::query()->create([
            'name' => 'Same price product',
            'price' => $price,
            'status' => BaseStatusEnum::PUBLISHED,
            'quantity' => 100,
            'with_storehouse_management' => false,
        ]);

        $promotion = Discount::query()->create([
            'title' => 'Same price promotion',
            'type' => DiscountTypeEnum::PROMOTION,
            'type_option' => DiscountTypeOptionEnum::SAME_PRICE,
            'target' => DiscountTargetEnum::SPECIFIC_PRODUCT,
            'value' => $promotionValue,
            // The same-price branch only runs for a quantity threshold above one.
            'product_quantity' => 2,
            'start_date' => now()->subDay(),
        ]);

        $promotion->products()->sync([$product->id]);

        Cart::instance('cart')->add($product->id, $product->name, $qty, $price);

        return $product;
    }
}
