<?php

namespace Botble\Ecommerce\Tests\Feature;

use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Enums\OrderStatusEnum;
use Botble\Ecommerce\Enums\ShippingMethodEnum;
use Botble\Ecommerce\Facades\OrderHelper;
use Botble\Ecommerce\Models\Order;
use Botble\Ecommerce\Models\OrderProduct;
use Botble\Payment\Enums\PaymentMethodEnum;
use Botble\Payment\Enums\PaymentStatusEnum;
use Botble\Payment\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Covers the recurring "order total higher than the amount the gateway captured" report
 * from cakepearls.com: the buyer pays, then returns to checkout in the same browser
 * session with a different cart (back button, a second tab, or they keep shopping), and
 * the checkout rewrites the already-paid order from the live cart.
 *
 * Fix surface:
 *   - Botble\Ecommerce\Supports\OrderHelper::isOrderLocked() (shared guard)
 *   - OrderHelper::processOrderProductData() (line items)
 *   - PublicCheckoutController::processOrderData() (line items)
 *   - PublicCheckoutController::postSaveInformation() (order total)
 */
class PaidOrderNotRewrittenByCheckoutTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function createOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'amount' => 320,
            'sub_total' => 320,
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'discount_amount' => 0,
            'payment_fee' => 0,
            'status' => OrderStatusEnum::PENDING,
            'shipping_method' => ShippingMethodEnum::DEFAULT,
            'is_finished' => false,
        ], $overrides));
    }

    protected function createCompletedPayment(Order $order, bool $link = true): Payment
    {
        $payment = Payment::query()->create([
            'amount' => 320,
            'currency' => 'INR',
            'charge_id' => 'pay_' . uniqid(),
            'order_id' => $order->getKey(),
            'payment_channel' => PaymentMethodEnum::BANK_TRANSFER,
            'status' => PaymentStatusEnum::COMPLETED,
            'user_id' => 0,
        ]);

        if ($link) {
            // payment_id is not in Order::$fillable; set explicitly so Eloquent persists it.
            $order->payment_id = $payment->getKey();
            $order->save();
        }

        return $payment;
    }

    protected function createOrderProduct(Order $order): OrderProduct
    {
        return OrderProduct::query()->create([
            'order_id' => $order->getKey(),
            'product_id' => 1,
            'product_name' => 'Bamboo mould',
            'product_image' => 'product.jpg',
            'qty' => 1,
            'weight' => 0,
            'price' => 320,
            'tax_amount' => 0,
            'options' => [],
        ]);
    }

    public function test_order_is_not_locked_while_pending_and_unpaid(): void
    {
        $this->assertFalse(OrderHelper::isOrderLocked($this->createOrder()));
    }

    public function test_finished_order_is_locked(): void
    {
        $this->assertTrue(OrderHelper::isOrderLocked($this->createOrder(['is_finished' => true])));
    }

    public function test_order_with_linked_completed_payment_is_locked(): void
    {
        $order = $this->createOrder();
        $this->createCompletedPayment($order);

        $this->assertTrue(OrderHelper::isOrderLocked($order->refresh()));
    }

    public function test_order_is_locked_when_completed_payment_is_only_linked_by_order_id(): void
    {
        $order = $this->createOrder();
        $this->createCompletedPayment($order, link: false);

        $this->assertTrue(OrderHelper::isOrderLocked($order->refresh()));
    }

    public function test_pending_payment_does_not_lock_the_order(): void
    {
        $order = $this->createOrder();

        Payment::query()->create([
            'amount' => 320,
            'currency' => 'INR',
            'charge_id' => 'pay_' . uniqid(),
            'order_id' => $order->getKey(),
            'payment_channel' => PaymentMethodEnum::BANK_TRANSFER,
            'status' => PaymentStatusEnum::PENDING,
            'user_id' => 0,
        ]);

        $this->assertFalse(OrderHelper::isOrderLocked($order->refresh()));
    }

    public function test_create_or_update_incomplete_order_leaves_a_paid_order_untouched(): void
    {
        $order = $this->createOrder();
        $this->createCompletedPayment($order);

        OrderHelper::createOrUpdateIncompleteOrder([
            'amount' => 720,
            'sub_total' => 720,
        ], $order->refresh());

        $order->refresh();

        $this->assertEquals(320, $order->amount);
        $this->assertEquals(320, $order->sub_total);
    }

    public function test_process_order_product_data_does_not_touch_line_items_of_a_paid_order(): void
    {
        $order = $this->createOrder();
        $this->createCompletedPayment($order);
        $orderProduct = $this->createOrderProduct($order);

        $sessionData = ['created_order_id' => $order->getKey()];

        // An empty cart: without the guard every line item would be deleted as "unmatched".
        $returned = OrderHelper::processOrderProductData(['products' => collect()], $sessionData);

        $this->assertSame($sessionData, $returned);
        $this->assertDatabaseHas('ec_order_product', [
            'id' => $orderProduct->getKey(),
            'qty' => 1,
            'price' => 320,
        ]);
    }

    public function test_process_order_product_data_still_syncs_line_items_of_an_unpaid_order(): void
    {
        $order = $this->createOrder();
        $orderProduct = $this->createOrderProduct($order);

        $sessionData = ['created_order_id' => $order->getKey()];

        OrderHelper::processOrderProductData(['products' => collect()], $sessionData);

        $this->assertDatabaseMissing('ec_order_product', ['id' => $orderProduct->getKey()]);
    }
}
