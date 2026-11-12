<?php

namespace Botble\Marketplace\Services;

use Botble\Marketplace\Enums\SubscriptionInvoiceStatusEnum;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Models\VendorSubscriptionInvoice;

/**
 * Issues the billing document for a subscription charge.
 *
 * The narrative is rendered through trans() once and stored, so the invoice keeps the
 * wording and locale the vendor saw when they paid rather than re-translating later.
 */
class CreateSubscriptionInvoiceService
{
    public function __construct(protected SubscriptionBillingService $billingService)
    {
    }

    public function forActivation(VendorSubscription $subscription, bool $isRenewal = false): VendorSubscriptionInvoice
    {
        $key = $isRenewal ? 'renewed' : 'subscribed';

        return $this->create($subscription, [
            'title' => trans("plugins/marketplace::subscription.invoices.titles.$key"),
            'description' => trans("plugins/marketplace::subscription.invoices.descriptions.$key", [
                'plan' => $subscription->planName(),
                'date' => $subscription->ends_at
                    ? $subscription->ends_at->translatedFormat('M j, Y')
                    : trans('plugins/marketplace::subscription.subscriptions.lifetime'),
            ]),
        ]);
    }

    public function create(VendorSubscription $subscription, array $attributes = []): VendorSubscriptionInvoice
    {
        $billing = $this->billingSnapshot($subscription);

        // Older subscriptions predate the tax columns, so fall back to amount as net.
        $subTotal = $subscription->sub_total ?: $subscription->amount;

        return VendorSubscriptionInvoice::query()->create(array_merge([
            'vendor_subscription_id' => $subscription->getKey(),
            'customer_id' => $subscription->customer_id,
            'sub_total' => $subTotal,
            'tax_rate' => $subscription->tax_rate ?: 0,
            'tax_amount' => $subscription->tax_amount ?: 0,
            'amount' => $subscription->amount,
            'currency' => $subscription->currency,
            'status' => $subscription->isActive()
                ? SubscriptionInvoiceStatusEnum::PAID
                : SubscriptionInvoiceStatusEnum::PENDING,
            'paid_at' => $subscription->isActive() ? $subscription->starts_at : null,
            'payment_id' => $subscription->payment_id,
        ], $billing, $attributes));
    }

    /**
     * Prefer what the vendor entered at checkout; fall back to their default address so
     * an invoice is never issued with an empty billing block.
     */
    protected function billingSnapshot(VendorSubscription $subscription): array
    {
        $billing = (array) $subscription->billing_data;

        if (! $billing && $subscription->customer) {
            $billing = $this->billingService->prefilled($subscription->customer);
        }

        $snapshot = [];

        foreach (['name', 'email', 'phone', 'address', 'country', 'state', 'city', 'zip_code', 'tax_id'] as $key) {
            $snapshot["billing_$key"] = $billing[$key] ?? null;
        }

        return $snapshot;
    }
}
