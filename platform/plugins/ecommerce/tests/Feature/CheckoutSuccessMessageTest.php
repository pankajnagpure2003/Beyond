<?php

namespace Botble\Ecommerce\Tests\Feature;

use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Facades\OrderHelper;
use Botble\Payment\Enums\PaymentMethodEnum;
use Botble\Payment\Enums\PaymentStatusEnum;
use Botble\Payment\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The checkout toast must describe the real payment state: "paid" only when the payment
 * is completed, otherwise tell the buyer the order is placed and what to do next.
 */
class CheckoutSuccessMessageTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function createPayment(string $channel, string $status): Payment
    {
        return Payment::query()->create([
            'amount' => 100,
            'currency' => 'USD',
            'charge_id' => 'ch_' . uniqid(),
            'order_id' => 1,
            'payment_channel' => $channel,
            'status' => $status,
            'user_id' => 0,
        ]);
    }

    protected function getMessage(Payment $payment): string
    {
        return OrderHelper::getCheckoutSuccessMessage([
            'charge_id' => $payment->charge_id,
            'type' => $payment->payment_channel->getValue(),
        ]);
    }

    public function testCompletedPaymentShowsPaidMessage(): void
    {
        $payment = $this->createPayment('stripe', PaymentStatusEnum::COMPLETED);

        $this->assertSame(
            trans('plugins/ecommerce::order.order_placed_and_paid_successfully'),
            $this->getMessage($payment)
        );
    }

    public function testCodPaymentShowsPayOnDeliveryMessage(): void
    {
        $payment = $this->createPayment(PaymentMethodEnum::COD, PaymentStatusEnum::PENDING);

        $this->assertSame(
            trans('plugins/ecommerce::order.order_placed_successfully_cod'),
            $this->getMessage($payment)
        );
    }

    public function testBankTransferPaymentShowsTransferInstructionMessage(): void
    {
        $payment = $this->createPayment(PaymentMethodEnum::BANK_TRANSFER, PaymentStatusEnum::PENDING);

        $this->assertSame(
            trans('plugins/ecommerce::order.order_placed_successfully_bank_transfer'),
            $this->getMessage($payment)
        );
    }

    public function testPendingGatewayPaymentShowsAwaitingConfirmationMessage(): void
    {
        $payment = $this->createPayment('stripe', PaymentStatusEnum::PENDING);

        $this->assertSame(
            trans('plugins/ecommerce::order.order_placed_successfully_payment_pending'),
            $this->getMessage($payment)
        );
    }

    public function testCompletedBankTransferStillShowsPaidMessage(): void
    {
        $payment = $this->createPayment(PaymentMethodEnum::BANK_TRANSFER, PaymentStatusEnum::COMPLETED);

        $this->assertSame(
            trans('plugins/ecommerce::order.order_placed_and_paid_successfully'),
            $this->getMessage($payment)
        );
    }

    public function testMissingPaymentRecordFallsBackToPaymentMethod(): void
    {
        $this->assertSame(
            trans('plugins/ecommerce::order.order_placed_successfully_cod'),
            OrderHelper::getCheckoutSuccessMessage(['charge_id' => 'unknown', 'type' => PaymentMethodEnum::COD])
        );

        $this->assertSame(
            trans('plugins/ecommerce::order.order_placed_successfully_payment_pending'),
            OrderHelper::getCheckoutSuccessMessage([])
        );
    }

    public function testVietnameseMessagesNoLongerClaimPaymentForUnpaidOrders(): void
    {
        app()->setLocale('vi');

        $payment = $this->createPayment(PaymentMethodEnum::COD, PaymentStatusEnum::PENDING);

        $this->assertSame('Đặt hàng thành công! Bạn sẽ thanh toán khi nhận hàng.', $this->getMessage($payment));
        $this->assertSame('Đặt hàng thành công!', trans('plugins/ecommerce::order.checkout_successfully'));
    }
}
