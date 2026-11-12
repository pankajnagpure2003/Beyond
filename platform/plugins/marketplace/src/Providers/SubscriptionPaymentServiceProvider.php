<?php

namespace Botble\Marketplace\Providers;

use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Models\VendorSubscriptionLog;
use Botble\Marketplace\Services\SubscribeVendorService;
use Botble\Marketplace\Services\SubscriptionPaymentSession;
use Botble\Payment\Enums\PaymentStatusEnum;
use Botble\Payment\Models\Payment;
use Illuminate\Support\ServiceProvider;

/**
 * Bridges vendor subscription checkouts into the shared payment plugin.
 *
 * A subscription charge has no order, which is exactly how the ecommerce listeners tell
 * it apart: they all bail when order_id is empty, so these hooks and theirs never
 * both fire for the same payment.
 */
class SubscriptionPaymentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! is_plugin_active('payment')) {
            return;
        }

        $this->app->booted(function (): void {
            $this->registerPaymentDataFilter();
            $this->registerReturnUrlFilters();
            $this->registerPaymentProcessedAction();
        });
    }

    /**
     * Gateways build their charge from this filter. Ecommerce's own contributor returns
     * the payload untouched when no orders match, so ours survives.
     */
    protected function registerPaymentDataFilter(): void
    {
        if (! defined('PAYMENT_FILTER_PAYMENT_DATA')) {
            return;
        }

        add_filter(PAYMENT_FILTER_PAYMENT_DATA, function (array $data, $request = null) {
            // A request carrying order ids is a storefront checkout; never rewrite it,
            // even if this browser session also has a subscription marker.
            if ($request && $request->input('order_id')) {
                return $data;
            }

            $session = $this->app->make(SubscriptionPaymentSession::class);
            $subscription = $session->current();

            return $subscription ? $session->paymentData($subscription) : $data;
        }, 1, 2);
    }

    /**
     * Ecommerce points both URLs at the storefront checkout at priority 123. Ours runs
     * later and only when a subscription checkout is in flight.
     */
    protected function registerReturnUrlFilters(): void
    {
        if (defined('PAYMENT_FILTER_REDIRECT_URL')) {
            add_filter(PAYMENT_FILTER_REDIRECT_URL, function ($url) {
                return $this->inSubscriptionCheckout()
                    ? route('marketplace.vendor.subscriptions.callback')
                    : $url;
            }, 999);
        }

        if (defined('PAYMENT_FILTER_CANCEL_URL')) {
            add_filter(PAYMENT_FILTER_CANCEL_URL, function ($url) {
                return $this->inSubscriptionCheckout()
                    ? route('marketplace.vendor.subscriptions.cancel-payment')
                    : $url;
            }, 999);
        }
    }

    protected function registerPaymentProcessedAction(): void
    {
        if (! defined('PAYMENT_ACTION_PAYMENT_PROCESSED')) {
            return;
        }

        add_action(PAYMENT_ACTION_PAYMENT_PROCESSED, function (array $data): void {
            if (! empty($data['order_id'])) {
                return;
            }

            $subscription = $this->resolveSubscription($data);

            if (! $subscription) {
                return;
            }

            $this->storePayment($subscription, $data);

            if (($data['status'] ?? null) != PaymentStatusEnum::COMPLETED) {
                return;
            }

            $subscribeService = $this->app->make(SubscribeVendorService::class);
            $subscribeService->log($subscription, VendorSubscriptionLog::TYPE_PAID, [
                'charge_id' => $data['charge_id'] ?? null,
            ]);

            // A cancelled or rejected subscription must not spring back to life, and
            // activate() would throw out of this action and fail the webhook.
            if ($subscription->isPending()) {
                $subscribeService->activate($subscription);
            }
        }, 999);
    }

    protected function resolveSubscription(array $data): ?VendorSubscription
    {
        if ($chargeId = $data['charge_id'] ?? null) {
            $subscription = VendorSubscription::query()->where('charge_id', $chargeId)->first();

            if ($subscription) {
                return $subscription;
            }
        }

        return $this->app->make(SubscriptionPaymentSession::class)->current();
    }

    /**
     * PaymentHelper::storeLocalPayment() keys on order_id and cannot dedupe an
     * order-less payment, so the row is written here instead.
     */
    protected function storePayment(VendorSubscription $subscription, array $data): void
    {
        $chargeId = $data['charge_id'] ?? null;

        $payment = $chargeId
            ? Payment::query()
                ->where('charge_id', $chargeId)
                ->whereNull('order_id')
                ->where('customer_id', $subscription->customer_id)
                ->first()
            : null;

        $attributes = [
            'amount' => $data['amount'] ?? $subscription->amount,
            'currency' => $data['currency'] ?? $subscription->currency,
            'charge_id' => $chargeId,
            'payment_channel' => $data['payment_channel'] ?? $subscription->payment_channel,
            'status' => $data['status'] ?? PaymentStatusEnum::PENDING,
            'payment_type' => 'vendor-subscription',
            'order_id' => null,
            'customer_id' => $subscription->customer_id,
            'customer_type' => $subscription->customer ? $subscription->customer::class : null,
            'description' => trans('plugins/marketplace::subscription.vendor.menu')
                . ': ' . $subscription->planName(),
            'user_id' => 0,
        ];

        if ($payment) {
            $payment->fill($attributes)->save();
        } else {
            $payment = Payment::query()->create($attributes);
        }

        $subscription->fill(['payment_id' => $payment->getKey()])->save();
    }

    protected function inSubscriptionCheckout(): bool
    {
        return $this->app->make(SubscriptionPaymentSession::class)->isActive();
    }
}
