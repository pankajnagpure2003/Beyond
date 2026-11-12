<?php

namespace Botble\Marketplace\Database\Seeders\Traits;

use Botble\Ecommerce\Models\Customer;
use Botble\Marketplace\Enums\SubscriptionStatusEnum;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Models\VendorSubscriptionInvoice;
use Botble\Marketplace\Models\VendorSubscriptionLog;
use Botble\Marketplace\Models\VendorSubscriptionNotification;
use Botble\Setting\Facades\Setting;
use Illuminate\Support\Arr;

/**
 * Builds a believable subscription history for the demo marketplace.
 *
 * Rows are written directly rather than through SubscribeVendorService: the service
 * dates everything from "now", sends mail and moves product visibility, none of which a
 * seeder wants. What it does reproduce faithfully is the shape the service leaves
 * behind — the plan snapshot, the frozen invoice totals, and a log trail that matches
 * each subscription's status.
 */
trait HasVendorSubscriptionSeeder
{
    use HasSubscriptionPaperworkSeeder;

    protected function createSubscriptionPlans(array $plans): array
    {
        $this->resetSubscriptionData();

        $created = [];

        foreach ($plans as $order => $plan) {
            $options = Arr::pull($plan, 'options', []);

            $model = new SubscriptionPlan(Arr::except($plan, ['options']));
            $model->order = $order;
            $model->fillOptions(array_merge(SubscriptionPlan::defaultOptions(), $options));
            $model->save();

            $created[$model->name] = $model;
        }

        return $created;
    }

    /**
     * @param  array<int, array<string, mixed>>  $subscriptions  each keyed by plan name,
     *                                                          with a status and an age in days
     */
    /**
     * Children before parents, so this still holds if a real foreign key is ever added
     * to any of these tables.
     */
    protected function resetSubscriptionData(): void
    {
        VendorSubscriptionInvoice::query()->truncate();
        VendorSubscriptionLog::query()->truncate();
        // Mass delete does not fire the model events that would cascade these, so the
        // ledger has to be cleared explicitly or re-seeding leaves orphaned claims behind.
        VendorSubscriptionNotification::query()->truncate();
        VendorSubscription::query()->delete();
        SubscriptionPlan::query()->truncate();
    }

    protected function createVendorSubscriptions(array $plans, array $subscriptions): void
    {
        foreach ($subscriptions as $item) {
            $plan = $plans[$item['plan']] ?? null;
            $vendor = $item['vendor'] ?? null;

            if (! $plan || ! $vendor) {
                continue;
            }

            $this->createVendorSubscription($plan, $vendor, $item);
        }
    }

    protected function createVendorSubscription(
        SubscriptionPlan $plan,
        Customer $vendor,
        array $item
    ): VendorSubscription {
        $status = $item['status'];
        $startedDaysAgo = $item['started_days_ago'] ?? 0;

        // Neither a request still awaiting approval nor one that was turned down was ever
        // activated, so neither has a period.
        $neverActivated = in_array((string) $status, [
            SubscriptionStatusEnum::PENDING,
            SubscriptionStatusEnum::REJECTED,
        ], true);

        // BaseSeeder::now() memoises a single mutable Carbon, so every date here works on
        // a copy — subtracting straight off it would shift every subsequent seeded date.
        $startsAt = $neverActivated
            ? null
            : $this->now()->copy()->subDays($startedDaysAgo);

        $subscription = VendorSubscription::query()->create([
            'customer_id' => $vendor->getKey(),
            'subscription_plan_id' => $plan->getKey(),
            'plan_data' => $plan->toSnapshot(),
            'sub_total' => $plan->price,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'amount' => $plan->price,
            'billing_data' => $this->subscriptionBillingData($vendor),
            'currency' => get_application_currency()->title,
            'status' => $status,
            'starts_at' => $startsAt,
            // ends_days_ago cuts a period short, which is what an upgrade mid-period does
            // to the plan it replaces.
            'ends_at' => match (true) {
                ! $startsAt => null,
                isset($item['ends_days_ago']) => $this->now()->copy()->subDays($item['ends_days_ago']),
                default => $plan->calculateEndsAt($startsAt),
            },
            'auto_renew' => $item['auto_renew'] ?? false,
            'payment_channel' => $item['payment_channel'] ?? null,
            'rejected_reason' => $item['rejected_reason'] ?? null,
            'cancelled_at' => $status == SubscriptionStatusEnum::CANCELLED
                ? $this->now()->copy()->subDays(max(1, (int) round($startedDaysAgo / 2)))
                : null,
        ]);

        // Otherwise every row in the admin table reads as created on seeding day. A
        // pending request is dated a couple of days back so it looks like a real queue.
        $subscription->forceFill([
            'created_at' => $startsAt ?: $this->now()->copy()->subDays($item['requested_days_ago'] ?? 2),
        ])->save();

        $this->createSubscriptionLogs($subscription, $item);

        // Only a charge the vendor actually completed produces paperwork.
        if (! $plan->isFree() && $this->subscriptionWasPaid($status)) {
            $this->createSubscriptionInvoice($subscription);
        }

        return $subscription;
    }

    /**
     * A billing block per vendor, derived from their store so the invoice matches the
     * address shown everywhere else on the demo.
     */
    protected function subscriptionBillingData(Customer $vendor): array
    {
        $store = $vendor->store;

        return [
            'name' => $store?->name ?: $vendor->name,
            'email' => $store?->email ?: $vendor->email,
            'phone' => $store?->phone ?: $vendor->phone,
            'address' => $store?->address,
            'country' => $store?->country,
            'state' => $store?->state,
            'city' => $store?->city,
            'zip_code' => $store?->zip_code,
            'tax_id' => sprintf('VAT-%s', str_pad((string) $vendor->getKey(), 6, '0', STR_PAD_LEFT)),
        ];
    }

    protected function saveSubscriptionSettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            Setting::set('marketplace_' . $key, $value);
        }

        Setting::save();
    }
}
