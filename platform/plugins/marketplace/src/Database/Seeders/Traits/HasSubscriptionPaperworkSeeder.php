<?php

namespace Botble\Marketplace\Database\Seeders\Traits;

use Botble\Marketplace\Enums\SubscriptionInvoiceStatusEnum;
use Botble\Marketplace\Enums\SubscriptionStatusEnum;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Models\VendorSubscriptionInvoice;
use Botble\Marketplace\Models\VendorSubscriptionLog;
use Illuminate\Support\Carbon;

/**
 * The records a seeded subscription leaves behind: its invoice and its audit trail.
 *
 * Split from HasVendorSubscriptionSeeder because this is the part that has to imitate
 * what the live services write — frozen invoice totals, and log entries dated where each
 * event actually happened rather than all at once.
 */
trait HasSubscriptionPaperworkSeeder
{
    protected function subscriptionWasPaid(string|SubscriptionStatusEnum $status): bool
    {
        return in_array((string) $status, [
            SubscriptionStatusEnum::ACTIVE,
            SubscriptionStatusEnum::EXPIRED,
            SubscriptionStatusEnum::CANCELLED,
        ], true);
    }

    protected function createSubscriptionInvoice(VendorSubscription $subscription): void
    {
        $billing = (array) $subscription->billing_data;

        $invoice = VendorSubscriptionInvoice::query()->create([
            'vendor_subscription_id' => $subscription->getKey(),
            'customer_id' => $subscription->customer_id,
            'title' => trans('plugins/marketplace::subscription.invoices.titles.subscribed'),
            'description' => trans('plugins/marketplace::subscription.invoices.descriptions.subscribed', [
                'plan' => $subscription->planName(),
                'date' => $subscription->ends_at
                    ? $subscription->ends_at->translatedFormat('M j, Y')
                    : trans('plugins/marketplace::subscription.subscriptions.lifetime'),
            ]),
            'sub_total' => $subscription->sub_total,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'amount' => $subscription->amount,
            'currency' => $subscription->currency,
            'status' => SubscriptionInvoiceStatusEnum::PAID,
            'paid_at' => $subscription->starts_at,
            'billing_name' => $billing['name'] ?? null,
            'billing_email' => $billing['email'] ?? null,
            'billing_phone' => $billing['phone'] ?? null,
            'billing_address' => $billing['address'] ?? null,
            'billing_country' => $billing['country'] ?? null,
            'billing_state' => $billing['state'] ?? null,
            'billing_city' => $billing['city'] ?? null,
            'billing_zip_code' => $billing['zip_code'] ?? null,
            'billing_tax_id' => $billing['tax_id'] ?? null,
        ]);

        // The ledger shows created_at as the issue date, so it has to be the day of the
        // charge rather than the day the demo was seeded.
        $invoice->forceFill(['created_at' => $subscription->starts_at])->save();
    }

    /**
     * The trail the vendor and admin screens read.
     *
     * Entries are dated where they actually happened — the opening events around the
     * start of the period, the closing ones around its end — so the activity tab reads
     * like a history instead of a burst of rows at one timestamp.
     */
    protected function createSubscriptionLogs(VendorSubscription $subscription, array $item): void
    {
        $status = $subscription->status;
        $openedAt = Carbon::parse($subscription->starts_at ?: $subscription->created_at);

        // A vendor moving off a plan they already held reads as a change, not a first buy.
        $entries = [[
            isset($item['changed_from'])
                ? VendorSubscriptionLog::TYPE_CHANGED_PLAN
                : VendorSubscriptionLog::TYPE_CLAIMED,
            $openedAt,
        ]];

        if ($this->subscriptionWasPaid($status)) {
            // A free plan is granted, not bought, so it has no payment to record.
            if ($subscription->amount > 0) {
                $entries[] = [VendorSubscriptionLog::TYPE_PAID, $openedAt->copy()->addMinutes(4)];

                // An offline transfer only becomes a subscription once someone signs it off.
                if (($item['payment_channel'] ?? null) === 'bank_transfer') {
                    $entries[] = [VendorSubscriptionLog::TYPE_ADMIN_APPROVED, $openedAt->copy()->addHours(6)];
                }
            }

            $entries[] = [VendorSubscriptionLog::TYPE_ACTIVATED, $openedAt->copy()->addHours(6)->addMinutes(2)];
        }

        $endsAt = $subscription->ends_at ? Carbon::parse($subscription->ends_at) : null;

        $entries = array_merge($entries, match ((string) $status) {
            SubscriptionStatusEnum::EXPIRED => $endsAt ? [
                [VendorSubscriptionLog::TYPE_REMINDER_SENT, $endsAt->copy()->subDays(7)],
                [VendorSubscriptionLog::TYPE_REMINDER_SENT, $endsAt->copy()->subDay()],
                [VendorSubscriptionLog::TYPE_EXPIRED, $endsAt],
            ] : [],
            SubscriptionStatusEnum::CANCELLED => [[
                VendorSubscriptionLog::TYPE_VENDOR_CANCELLED,
                Carbon::parse($subscription->cancelled_at ?: $openedAt),
            ]],
            SubscriptionStatusEnum::REJECTED => [[
                VendorSubscriptionLog::TYPE_ADMIN_REJECTED,
                $openedAt->copy()->addDay(),
            ]],
            default => [],
        });

        foreach ($entries as [$type, $at]) {
            $log = VendorSubscriptionLog::query()->create([
                'vendor_subscription_id' => $subscription->getKey(),
                'type' => $type,
                'data' => match ($type) {
                    VendorSubscriptionLog::TYPE_ADMIN_REJECTED => ['reason' => $subscription->rejected_reason],
                    VendorSubscriptionLog::TYPE_CHANGED_PLAN => [
                        'from' => $item['changed_from'],
                        'plan' => $subscription->planName(),
                    ],
                    default => ['plan' => $subscription->planName()],
                },
            ]);

            // created_at is guarded, so the timestamp is written after the insert.
            $log->forceFill(['created_at' => $at])->save();
        }
    }
}
