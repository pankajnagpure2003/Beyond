<?php

namespace Botble\Marketplace\Services;

use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Models\VendorSubscriptionNotification;
use Illuminate\Support\Carbon;

/**
 * Decides whether a subscription notification has already been sent.
 *
 * The rule is claim-before-send: a row is inserted to reserve the send, and only if that
 * insert actually created something does the caller mail anything. The unique index does
 * the locking, so there is no read-then-write window for two concurrent cron runs to slip
 * through — which is exactly how the previous `reminders_sent` JSON column could double-send.
 *
 * The trade this makes is deliberate: delivery becomes at-most-once. If the mail is handed
 * over and the transport then fails, that notification is lost rather than retried — for a
 * dunning email that is the right failure mode, better silent than sent five times. Do not
 * "fix" this by moving the claim after the send.
 *
 * A claim is released again only when there was nothing to send in the first place (no
 * address, template switched off), so enabling that template later still warns the vendor.
 * Note that `sent_at` records that the mail was handed to the mailer, NOT that it arrived:
 * SubscriptionNotifier swallows transport errors by design.
 */
class SubscriptionNotificationLedger
{
    /**
     * Reserve the right to send. Returns how many of $keys were newly claimed; 0 means
     * every one of them was already claimed by an earlier run, so nothing should be sent.
     *
     * @param  array<int, string>  $keys
     */
    public function claim(VendorSubscription $subscription, string $type, array $keys): int
    {
        $keys = array_values(array_unique(array_map('strval', $keys)));

        if (! $keys) {
            return 0;
        }

        $now = Carbon::now();

        return VendorSubscriptionNotification::query()->insertOrIgnore(
            array_map(fn (string $key) => [
                'vendor_subscription_id' => $subscription->getKey(),
                'type' => $type,
                'dedupe_key' => $key,
                'claimed_at' => $now,
            ], $keys)
        );
    }

    /**
     * Give a claim back when there turned out to be nothing to send, so the notification is
     * not burned for the rest of the period.
     *
     * @param  array<int, string>  $keys
     */
    public function release(VendorSubscription $subscription, string $type, array $keys): void
    {
        VendorSubscriptionNotification::query()
            ->where('vendor_subscription_id', $subscription->getKey())
            ->where('type', $type)
            ->whereIn('dedupe_key', array_map('strval', $keys))
            ->whereNull('sent_at')
            ->delete();
    }

    /**
     * Close the claims once the mail has been handed over.
     *
     * @param  array<int, string>  $keys
     */
    public function markSent(VendorSubscription $subscription, string $type, array $keys): void
    {
        VendorSubscriptionNotification::query()
            ->where('vendor_subscription_id', $subscription->getKey())
            ->where('type', $type)
            ->whereIn('dedupe_key', array_map('strval', $keys))
            ->whereNull('sent_at')
            ->update(['sent_at' => Carbon::now()]);
    }
}
