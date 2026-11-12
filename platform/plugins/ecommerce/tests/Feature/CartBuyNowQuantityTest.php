<?php

namespace Botble\Ecommerce\Tests\Feature;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Cart\CartItem;
use Botble\Ecommerce\Enums\ProductTypeEnum;
use Botble\Ecommerce\Facades\Cart;
use Botble\Ecommerce\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CartBuyNowQuantityTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cart::instance('cart')->destroy();
    }

    protected function createProduct(string $name = 'Organic Quinoa'): Product
    {
        return Product::query()->create([
            'name' => $name,
            'price' => 100,
            'weight' => 520,
            'status' => BaseStatusEnum::PUBLISHED,
        ]);
    }

    protected function addToCart(Product $product, bool $buyNow, int $qty = 1): void
    {
        $this
            ->postJson(route('public.cart.add-to-cart'), [
                'id' => $product->getKey(),
                'qty' => $qty,
                'checkout' => $buyNow ? 1 : 0,
            ], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJsonPath('error', false);
    }

    protected function cartQuantities(): array
    {
        return Cart::instance('cart')->content()->pluck('qty', 'id')->all();
    }

    public function test_row_id_is_stable_after_json_round_trip_of_options(): void
    {
        $options = [
            'weight' => 520.0,
            'taxRate' => 0.0,
            'product_type' => ProductTypeEnum::PHYSICAL(),
        ];

        $fresh = CartItem::fromAttributes(1, 'Organic Quinoa', 100, $options);

        // What a cart item's options look like after the JSON session serializer round-trip.
        $rehydrated = CartItem::fromAttributes(1, 'Organic Quinoa', 100, json_decode(json_encode($options), true));

        $this->assertSame($fresh->rowId, $rehydrated->rowId);
    }

    public function test_row_id_does_not_depend_on_translated_enum_label(): void
    {
        $fresh = CartItem::fromAttributes(1, 'Organic Quinoa', 100, [
            'product_type' => ProductTypeEnum::PHYSICAL(),
        ]);

        // Stored by the JSON session serializer while the site was in another language.
        $storedInOtherLocale = CartItem::fromAttributes(1, 'Organic Quinoa', 100, [
            'product_type' => ['value' => ProductTypeEnum::PHYSICAL, 'label' => 'Vật lý'],
        ]);

        $this->assertSame($fresh->rowId, $storedInOtherLocale->rowId);
    }

    public function test_buy_now_respects_stock_across_other_lines_of_the_same_product(): void
    {
        $product = $this->createProduct();
        $product->update(['with_storehouse_management' => true, 'quantity' => 3]);

        // Same product in a separate line, e.g. added earlier with different product options.
        Cart::instance('cart')->add($product->getKey(), $product->name, 2, 100, ['extras' => ['note' => 'gift']]);

        $this
            ->postJson(route('public.cart.add-to-cart'), [
                'id' => $product->getKey(),
                'qty' => 2,
                'checkout' => 1,
            ], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJsonPath('error', true);

        $this->assertSame(1, Cart::instance('cart')->content()->count());
        $this->assertSame(2, Cart::instance('cart')->count());
    }

    public function test_repeated_buy_now_keeps_the_requested_quantity(): void
    {
        $product = $this->createProduct();

        $this->addToCart($product, buyNow: true);
        $this->addToCart($product, buyNow: true);
        $this->addToCart($product, buyNow: true);

        $this->assertSame([$product->getKey() => 1], $this->cartQuantities());
    }

    public function test_buy_now_replaces_quantity_previously_added_to_cart(): void
    {
        $product = $this->createProduct();

        $this->addToCart($product, buyNow: false);
        $this->addToCart($product, buyNow: false);
        $this->assertSame([$product->getKey() => 2], $this->cartQuantities());

        $this->addToCart($product, buyNow: true);

        $this->assertSame([$product->getKey() => 1], $this->cartQuantities());
    }

    public function test_add_to_cart_still_accumulates_quantity(): void
    {
        $product = $this->createProduct();

        $this->addToCart($product, buyNow: false);
        $this->addToCart($product, buyNow: false, qty: 2);

        $this->assertSame([$product->getKey() => 3], $this->cartQuantities());
    }

    public function test_buy_now_keeps_other_cart_items(): void
    {
        $productA = $this->createProduct('Organic Quinoa');
        $productB = $this->createProduct('Chicken Meatballs');

        $this->addToCart($productA, buyNow: true);
        $this->addToCart($productB, buyNow: true);

        $this->assertSame(
            [$productA->getKey() => 1, $productB->getKey() => 1],
            $this->cartQuantities()
        );
    }

    public function test_cart_item_can_be_removed_by_row_id(): void
    {
        $productA = $this->createProduct('Organic Quinoa');
        $productB = $this->createProduct('Chicken Meatballs');

        $this->addToCart($productA, buyNow: true);
        $this->addToCart($productB, buyNow: true);

        $rowId = Cart::instance('cart')->content()->firstWhere('id', $productA->getKey())->rowId;

        $this
            ->getJson(route('public.cart.remove', $rowId), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJsonPath('error', false);

        $this->assertSame([$productB->getKey() => 1], $this->cartQuantities());
    }
}
