<?php

namespace Botble\Ecommerce\Tests\Feature;

use Botble\Base\Facades\BaseHelper;
use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Enums\OrderStatusEnum;
use Botble\Ecommerce\Enums\ShippingMethodEnum;
use Botble\Ecommerce\Facades\OrderHelper;
use Botble\Ecommerce\Http\Controllers\Fronts\PublicCheckoutController;
use Botble\Ecommerce\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;

/**
 * Covers the "paid weeks after the order was cancelled" report from cakepearls.com:
 * order #SF-10000817 was created on 28 Jul, auto-cancelled, then charged again on
 * 18 Sep through the same checkout link.
 *
 * Cancelling an order leaves is_finished = false, so the checkout kept resuming it.
 * The buyer paid, Razorpay captured the money, and the cancelled order was never
 * revived - which is also why the success page rendered blank for them.
 *
 * Fix surface:
 *   - PublicCheckoutController::findResumableOrderByToken()
 *   - PublicCheckoutController::handlePostCheckout() (cancelled-order guard)
 */
class CancelledOrderCheckoutNotResumableTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function createOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'amount' => 7198,
            'sub_total' => 7198,
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'discount_amount' => 0,
            'payment_fee' => 0,
            'status' => OrderStatusEnum::PENDING,
            'shipping_method' => ShippingMethodEnum::DEFAULT,
            'is_finished' => false,
            'token' => 'token-' . uniqid(),
        ], $overrides));
    }

    protected function findResumableOrderByToken(string $token): ?Order
    {
        $method = new ReflectionMethod(PublicCheckoutController::class, 'findResumableOrderByToken');

        return $method->invoke(app(PublicCheckoutController::class), $token);
    }

    public function test_a_cancelled_order_is_not_resumable(): void
    {
        $order = $this->createOrder(['status' => OrderStatusEnum::CANCELED]);

        $this->assertNull($this->findResumableOrderByToken($order->token));
    }

    public function test_a_pending_order_is_still_resumable(): void
    {
        // A pending order is a live checkout. It must keep resuming, otherwise every
        // ordinary "back to checkout" would break.
        $order = $this->createOrder();

        $resumed = $this->findResumableOrderByToken($order->token);

        $this->assertNotNull($resumed);
        $this->assertEquals($order->getKey(), $resumed->getKey());
    }

    public function test_a_finished_order_is_not_resumable(): void
    {
        $order = $this->createOrder(['is_finished' => true]);

        $this->assertNull($this->findResumableOrderByToken($order->token));
    }

    public function test_a_cancelled_order_is_locked_against_being_rebuilt_from_the_cart(): void
    {
        // The token guard only runs when the checkout is not the one in session. A buyer
        // sitting on the checkout page when the auto-cancel job fires is still in session,
        // so the lock is what stops createOrUpdateIncompleteOrder() writing the order back
        // to pending and reselling the stock cancelling just returned.
        $order = $this->createOrder(['status' => OrderStatusEnum::CANCELED]);

        $this->assertTrue(OrderHelper::isOrderLocked($order));
    }

    public function test_a_pending_order_is_not_locked(): void
    {
        $this->assertFalse(OrderHelper::isOrderLocked($this->createOrder()));
    }

    public function test_submitting_a_cancelled_checkout_drops_the_dead_token(): void
    {
        // Without this the buyer is bounced back to the same cancelled checkout on every
        // retry, because getOrderSessionToken() keeps handing back the stored token.
        $order = $this->createOrder(['status' => OrderStatusEnum::CANCELED]);

        $this->withSession(['tracked_start_checkout' => $order->token])
            ->post(route('public.checkout.process', $order->token), [
                'amount' => 0,
                // Required only when the store shows the terms checkbox; harmless otherwise.
                'agree_terms_and_policy' => 1,
                'address' => [
                    'name' => 'Test Buyer',
                    'email' => 'buyer@example.com',
                    'state' => 'Delhi',
                    'city' => 'Delhi',
                    'address' => '1 Test Street',
                    'phone' => '9876543210',
                    'country' => 'IN',
                ],
            ]);

        $this->assertNotEquals($order->token, session('tracked_start_checkout'));

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELED, $order->status->getValue());
        $this->assertFalse((bool) $order->is_finished);
    }

    public function test_checkout_page_sends_a_cancelled_order_home_instead_of_resuming_it(): void
    {
        $order = $this->createOrder(['status' => OrderStatusEnum::CANCELED]);

        $this
            ->get(route('public.checkout.information', $order->token))
            ->assertRedirect(BaseHelper::getHomepageUrl());

        // The cancelled order must be left exactly as it was: not revived, not finished.
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELED, $order->status->getValue());
        $this->assertFalse((bool) $order->is_finished);
        $this->assertNull($order->payment_id);
    }
}
