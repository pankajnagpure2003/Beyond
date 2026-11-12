<?php

namespace Botble\Ecommerce\Tests\Feature;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Enums\OrderStatusEnum;
use Botble\Ecommerce\Enums\ShippingMethodEnum;
use Botble\Ecommerce\Enums\StockStatusEnum;
use Botble\Ecommerce\Events\OrderPlacedEvent;
use Botble\Ecommerce\Events\ProductQuantityUpdatedEvent;
use Botble\Ecommerce\Models\Order;
use Botble\Ecommerce\Models\OrderProduct;
use Botble\Ecommerce\Models\Product;
use Botble\Ecommerce\Supports\OrderHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

/**
 * Checkout validates stock but must never deduct it: stock is decremented exactly once,
 * when the order is finalized in OrderHelper::processOrder(). Deducting at checkout as
 * well double-counted every order and leaked stock on cancelled/failed gateway payments.
 */
class StockReservationTest extends BaseTestCase
{
    use RefreshDatabase;

    protected OrderHelper $orderHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderHelper = app(OrderHelper::class);
    }

    protected function createProduct(array $attributes = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => 'Test Product',
            'price' => 100,
            'quantity' => 10,
            'with_storehouse_management' => true,
            'allow_checkout_when_out_of_stock' => false,
            'stock_status' => StockStatusEnum::IN_STOCK,
            'status' => BaseStatusEnum::PUBLISHED,
        ], $attributes));
    }

    protected function createOrderWithProduct(Product $product, int $qty, array $attributes = []): Order
    {
        $order = Order::query()->create(array_merge([
            'amount' => 0,
            'sub_total' => $product->price * $qty,
            'status' => OrderStatusEnum::PENDING,
            'shipping_method' => ShippingMethodEnum::DEFAULT,
            'is_finished' => false,
        ], $attributes));

        OrderProduct::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'qty' => $qty,
            'price' => $product->price,
        ]);

        return $order;
    }

    public function test_validate_stock_does_not_change_quantity(): void
    {
        $product = $this->createProduct(['quantity' => 10]);

        $result = $this->orderHelper->validateStock([
            ['product_id' => $product->id, 'qty' => 3],
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals(10, $product->fresh()->quantity);
    }

    public function test_validate_stock_does_not_fire_quantity_updated_event(): void
    {
        Event::fake([ProductQuantityUpdatedEvent::class]);

        $product = $this->createProduct(['quantity' => 5]);

        $this->orderHelper->validateStock([
            ['product_id' => $product->id, 'qty' => 2],
        ]);

        Event::assertNotDispatched(ProductQuantityUpdatedEvent::class);
    }

    public function test_validate_stock_fails_when_insufficient_quantity(): void
    {
        $product = $this->createProduct(['quantity' => 2]);

        $result = $this->orderHelper->validateStock([
            ['product_id' => $product->id, 'qty' => 5],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('only has 2 item(s) left', $result['message']);
        $this->assertEquals(2, $product->fresh()->quantity);
    }

    public function test_validate_stock_fails_for_out_of_stock_product(): void
    {
        $product = $this->createProduct([
            'quantity' => 0,
            'stock_status' => StockStatusEnum::OUT_OF_STOCK,
        ]);

        $result = $this->orderHelper->validateStock([
            ['product_id' => $product->id, 'qty' => 1],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('out of stock', $result['message']);
    }

    public function test_validate_stock_allows_exact_remaining_quantity(): void
    {
        $product = $this->createProduct(['quantity' => 3]);

        $result = $this->orderHelper->validateStock([
            ['product_id' => $product->id, 'qty' => 3],
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals(3, $product->fresh()->quantity);
    }

    public function test_validate_stock_handles_multiple_products(): void
    {
        $product1 = $this->createProduct(['quantity' => 10]);
        $product2 = $this->createProduct(['quantity' => 1]);

        $result = $this->orderHelper->validateStock([
            ['product_id' => $product1->id, 'qty' => 2],
            ['product_id' => $product2->id, 'qty' => 2],
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals($product2->id, $result['product']->id);
        $this->assertEquals(10, $product1->fresh()->quantity);
        $this->assertEquals(1, $product2->fresh()->quantity);
    }

    public function test_validate_stock_skips_products_without_storehouse_management(): void
    {
        $product = $this->createProduct([
            'quantity' => 0,
            'with_storehouse_management' => false,
            'stock_status' => StockStatusEnum::IN_STOCK,
        ]);

        $result = $this->orderHelper->validateStock([
            ['product_id' => $product->id, 'qty' => 1],
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_validate_stock_skips_products_allowing_oversell(): void
    {
        $product = $this->createProduct([
            'quantity' => 0,
            'allow_checkout_when_out_of_stock' => true,
        ]);

        $result = $this->orderHelper->validateStock([
            ['product_id' => $product->id, 'qty' => 5],
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $product->fresh()->quantity);
    }

    public function test_validate_stock_fails_below_minimum_order_quantity(): void
    {
        $product = $this->createProduct(['quantity' => 10, 'minimum_order_quantity' => 3]);

        $result = $this->orderHelper->validateStock([
            ['product_id' => $product->id, 'qty' => 2],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Minimum order quantity', $result['message']);
        $this->assertEquals(10, $product->fresh()->quantity);
    }

    public function test_validate_stock_fails_above_maximum_order_quantity(): void
    {
        $product = $this->createProduct(['quantity' => 10, 'maximum_order_quantity' => 2]);

        $result = $this->orderHelper->validateStock([
            ['product_id' => $product->id, 'qty' => 5],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Maximum order quantity', $result['message']);
        $this->assertEquals(10, $product->fresh()->quantity);
    }

    public function test_validate_stock_skips_missing_products(): void
    {
        $result = $this->orderHelper->validateStock([
            ['product_id' => 99999, 'qty' => 5],
        ]);

        $this->assertTrue($result['success']);
    }

    public function test_deprecated_validate_and_reserve_stock_reserves_nothing(): void
    {
        $product = $this->createProduct(['quantity' => 5]);

        $result = $this->orderHelper->validateAndReserveStock([
            ['product_id' => $product->id, 'qty' => 2],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame([], $result['reserved_items']);
        $this->assertEquals(5, $product->fresh()->quantity);
    }

    public function test_decrease_product_quantity_decrements_once_after_validation(): void
    {
        $product = $this->createProduct(['quantity' => 10]);

        $this->orderHelper->validateStock([
            ['product_id' => $product->id, 'qty' => 4],
        ]);

        $order = $this->createOrderWithProduct($product, 4, ['is_finished' => true]);

        $this->orderHelper->decreaseProductQuantity($order);

        $this->assertEquals(6, $product->fresh()->quantity);
    }

    public function test_process_order_decrements_stock_exactly_once_when_called_twice(): void
    {
        Event::fake([OrderPlacedEvent::class]);

        $product = $this->createProduct(['quantity' => 10]);
        $order = $this->createOrderWithProduct($product, 3);

        $this->orderHelper->processOrder($order->id);
        $this->orderHelper->processOrder($order->id);

        $this->assertTrue((bool) $order->fresh()->is_finished);
        $this->assertEquals(7, $product->fresh()->quantity);
        Event::assertDispatchedTimes(OrderPlacedEvent::class, 1);
    }
}
