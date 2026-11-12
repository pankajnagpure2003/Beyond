<?php

namespace Botble\Marketplace\Services;

use Botble\Marketplace\Models\VendorSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Correlates a gateway round trip back to the subscription that started it.
 *
 * The session marker covers redirect gateways; charge_id, persisted before the redirect,
 * covers gateways that confirm by webhook after the session is gone.
 *
 * The marker is deliberately hard to leave lying around: it expires, and it only counts
 * while the subscription it points at is still pending. A vendor who abandons a
 * subscription checkout and then buys something from the storefront in the same browser
 * session must not have that order's payment data, amount or return URL replaced by this
 * one's.
 */
class SubscriptionPaymentSession
{
    public const SESSION_KEY = 'marketplace_subscription_checkout';

    /**
     * How long a gateway round trip may take before the marker is considered abandoned.
     */
    public const TTL_MINUTES = 120;

    public function start(VendorSubscription $subscription): void
    {
        session()->put(self::SESSION_KEY, [
            'id' => $subscription->getKey(),
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
        ]);
    }

    public function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    public function isActive(): bool
    {
        return (bool) $this->current();
    }

    /**
     * The subscription this browser session is mid-checkout for, or null when there is
     * none, it expired, or it is no longer awaiting payment.
     */
    public function current(): ?VendorSubscription
    {
        $marker = session(self::SESSION_KEY);

        if (! is_array($marker) || empty($marker['id'])) {
            return null;
        }

        if (($marker['expires_at'] ?? 0) < Carbon::now()->getTimestamp()) {
            $this->forget();

            return null;
        }

        $subscription = VendorSubscription::query()->find($marker['id']);

        // Already paid, rejected or cleaned up: the round trip is over either way.
        if (! $subscription || ! $subscription->isPending()) {
            $this->forget();

            return null;
        }

        return $subscription;
    }

    public function resolveFromCallback(Request $request): ?VendorSubscription
    {
        $marker = session(self::SESSION_KEY);
        $subscription = is_array($marker) && ! empty($marker['id'])
            ? VendorSubscription::query()->find($marker['id'])
            : null;

        if (! $subscription && $chargeId = $request->input('charge_id')) {
            $subscription = VendorSubscription::query()->where('charge_id', $chargeId)->first();
        }

        $this->forget();

        return $subscription?->refresh();
    }

    /**
     * Payload the gateways read via PAYMENT_FILTER_PAYMENT_DATA.
     */
    public function paymentData(VendorSubscription $subscription): array
    {
        $vendor = $subscription->customer;

        return [
            'amount' => (float) $subscription->amount,
            'currency' => $subscription->currency ?: get_application_currency()->title,
            'payment_fee' => 0,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            // Deliberately empty: an order id here would make the ecommerce listeners
            // treat this charge as an order payment.
            'order_id' => [],
            'description' => trans('plugins/marketplace::subscription.vendor.menu') . ': ' . $subscription->planName(),
            'customer_id' => $vendor?->getKey(),
            'customer_type' => $vendor ? $vendor::class : null,
            'return_url' => route('marketplace.vendor.subscriptions.cancel-payment'),
            'callback_url' => route('marketplace.vendor.subscriptions.callback'),
            'products' => [],
            'address' => [
                'name' => (string) $vendor?->name,
                'email' => (string) $vendor?->email,
                'phone' => (string) $vendor?->phone,
            ],
        ];
    }
}
