<?php

namespace Botble\Ecommerce\Tests\Feature;

use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Enums\OrderStatusEnum;
use Botble\Ecommerce\Enums\ShippingMethodEnum;
use Botble\Ecommerce\Facades\OrderHelper;
use Botble\Ecommerce\Models\Order;
use Botble\Ecommerce\Models\OrderHistory;
use Botble\Ecommerce\Providers\HookServiceProvider;
use Botble\Payment\Enums\PaymentStatusEnum;
use Botble\Payment\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;

/**
 * Covers the cakepearls.com "payment landed on an old order" reports (#SF-10001055,
 * #SF-10001065, #SF-10000817): the buyer paid, a gateway webhook finalized the order,
 * but the buyer never reached the success page - the only place that cleared the
 * checkout token and cart. Their next purchase reused the spent token, so the new
 * charge was matched to the old, already-paid order and the new items were lost.
 *
 * Fix surface:
 *   - OrderHelper::isCheckoutTokenSpent() / discardSpentCheckoutSession()
 *   - PublicCheckoutController::getCheckout() and PublicCartController entry points
 *   - HookServiceProvider::flagOverpaidPayment() (#SF-10001118: paid 430, order 310)
 */
class SpentCheckoutSessionIsDiscardedTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function createOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'amount' => 870,
            'sub_total' => 750,
            'tax_amount' => 0,
            'shipping_amount' => 120,
            'discount_amount' => 0,
            'payment_fee' => 0,
            'status' => OrderStatusEnum::PENDING,
            'shipping_method' => ShippingMethodEnum::DEFAULT,
            'is_finished' => false,
            'token' => 'token-' . uniqid(),
        ], $overrides));
    }

    public function test_a_token_without_orders_is_not_spent(): void
    {
        // A fresh session token has no order until the checkout page creates the draft.
        $this->assertFalse(OrderHelper::isCheckoutTokenSpent(null));
        $this->assertFalse(OrderHelper::isCheckoutTokenSpent('token-with-no-order'));
    }

    public function test_a_pending_checkout_is_not_spent(): void
    {
        $this->assertFalse(OrderHelper::isCheckoutTokenSpent($this->createOrder()->token));
    }

    public function test_a_finished_or_cancelled_checkout_is_spent(): void
    {
        $this->assertTrue(OrderHelper::isCheckoutTokenSpent($this->createOrder(['is_finished' => true])->token));
        $this->assertTrue(OrderHelper::isCheckoutTokenSpent(
            $this->createOrder(['status' => OrderStatusEnum::CANCELED])->token
        ));
    }

    public function test_a_marketplace_checkout_with_one_open_vendor_order_is_not_spent(): void
    {
        $token = 'token-' . uniqid();
        $this->createOrder(['token' => $token, 'is_finished' => true]);
        $this->createOrder(['token' => $token]);

        $this->assertFalse(OrderHelper::isCheckoutTokenSpent($token));
    }

    public function test_a_spent_session_token_is_discarded(): void
    {
        $order = $this->createOrder(['is_finished' => true]);
        session(['tracked_start_checkout' => $order->token]);

        $this->assertTrue(OrderHelper::discardSpentCheckoutSession());
        $this->assertFalse(session()->has('tracked_start_checkout'));
        $this->assertNotEquals($order->token, OrderHelper::getOrderSessionToken());
    }

    public function test_a_live_session_token_is_kept(): void
    {
        $order = $this->createOrder();
        session(['tracked_start_checkout' => $order->token]);

        $this->assertFalse(OrderHelper::discardSpentCheckoutSession());
        $this->assertEquals($order->token, session('tracked_start_checkout'));
    }

    public function test_checkout_page_on_a_spent_session_token_starts_over(): void
    {
        // Rendering the page would open a new (embedded) gateway payment on the old token.
        $order = $this->createOrder(['is_finished' => true]);

        $this->withSession(['tracked_start_checkout' => $order->token])
            ->get(route('public.checkout.information', $order->token))
            ->assertRedirect(route('public.cart'));

        $this->assertNotEquals($order->token, session('tracked_start_checkout'));
    }

    protected function flagOverpaidPayment(Order $order, array $data): void
    {
        $method = new ReflectionMethod(HookServiceProvider::class, 'flagOverpaidPayment');

        $method->invoke(app()->getProvider(HookServiceProvider::class), collect([$order]), $data);
    }

    protected function paymentData(array $overrides = []): array
    {
        return array_merge([
            'charge_id' => 'pay_' . uniqid(),
            'amount' => 430,
            'currency' => cms_currency()->getDefaultCurrency()->title,
            'status' => PaymentStatusEnum::COMPLETED,
            'payment_channel' => 'razorpay',
        ], $overrides);
    }

    public function test_a_capture_above_the_order_total_is_noted_on_the_order(): void
    {
        $order = $this->createOrder(['amount' => 310, 'sub_total' => 310, 'shipping_amount' => 0]);

        $this->flagOverpaidPayment($order, $this->paymentData());

        $this->assertEquals(1, OrderHistory::query()->where('order_id', $order->getKey())->count());
    }

    public function test_an_exact_capture_is_not_noted(): void
    {
        $order = $this->createOrder(['amount' => 430]);

        $this->flagOverpaidPayment($order, $this->paymentData());

        $this->assertEquals(0, OrderHistory::query()->where('order_id', $order->getKey())->count());
    }

    public function test_a_replayed_capture_is_not_noted_twice(): void
    {
        $order = $this->createOrder(['amount' => 310, 'sub_total' => 310, 'shipping_amount' => 0]);
        $data = $this->paymentData();

        Payment::query()->create([
            'charge_id' => $data['charge_id'],
            'amount' => 310,
            'currency' => $data['currency'],
            'payment_channel' => 'razorpay',
            'status' => PaymentStatusEnum::COMPLETED,
            'order_id' => $order->getKey(),
        ]);

        $this->flagOverpaidPayment($order, $data);

        $this->assertEquals(0, OrderHistory::query()->where('order_id', $order->getKey())->count());
    }
}
