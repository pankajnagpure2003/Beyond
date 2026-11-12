<?php

namespace Botble\Marketplace\Services;

use Botble\Marketplace\Exceptions\RenewalPaymentFailedException;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Models\VendorSubscriptionLog;
use Botble\Marketplace\Models\VendorSubscriptionNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The three things the daily command does to a subscription: renew it from the vendor's
 * balance, warn that it is about to end, or end it.
 */
class SubscriptionRenewalService
{
    public function __construct(
        protected SubscribeVendorService $subscribeService,
        protected SubscriptionBalancePaymentService $balanceService,
        protected SubscriptionNotifier $notifier,
        protected SubscriptionTaxService $taxService,
        protected SubscriptionNotificationLedger $ledger
    ) {
    }

    /**
     * Renew from the vendor's marketplace balance. Returns false — leaving the
     * subscription to expire normally — when the balance is short or the plan is gone.
     */
    public function autoRenew(VendorSubscription $subscription): bool
    {
        $plan = $subscription->plan;
        $vendor = $subscription->customer;

        if (! $plan || ! $vendor || $plan->isLifetime()) {
            return false;
        }

        // Reuse the billing block the vendor gave when they first paid, so a renewal is
        // taxed by the same address and its invoice carries the same details.
        $billing = (array) $subscription->billing_data;

        // The balance is debited for the gross amount, so the pre-check has to include
        // tax — checking the bare plan price would pass a vendor who cannot actually
        // afford the charge, only to have the payment fail a moment later.
        $gross = (float) $plan->price + $this->taxService->calculate((float) $plan->price, $billing)['amount'];

        if (! $plan->isFree() && ! $this->balanceService->canPay($vendor, $gross)) {
            return $this->failRenewal($subscription);
        }

        try {
            // One transaction over claim -> debit -> activate. SubscriptionBalancePaymentService
            // commits its own debit, so without this an exception from activate() would leave
            // the vendor charged, unactivated, and reading an email that says we could not take
            // their money. Nesting is fine: the inner transaction becomes a savepoint.
            $renewal = DB::transaction(function () use ($vendor, $plan, $subscription, $billing) {
                $renewal = $this->subscribeService->claim($vendor, $plan, [
                    'renewed_from_id' => $subscription->getKey(),
                    'auto_renew' => true,
                    'payment_channel' => 'balance',
                    'billing_data' => $billing,
                ]);

                if (! $plan->isFree() && ! $this->balanceService->pay($renewal)) {
                    // Thrown rather than returned so the claim rolls back with it, instead
                    // of leaving a stray PENDING row behind.
                    throw new RenewalPaymentFailedException();
                }

                // Continue from the old end date so a late run costs the vendor no days.
                $from = $subscription->ends_at && $subscription->ends_at->isFuture()
                    ? $subscription->ends_at->copy()
                    : null;

                $this->subscribeService->activate($renewal, $from);
                $this->subscribeService->log($renewal, VendorSubscriptionLog::TYPE_AUTO_RENEWED, [
                    'renewed_from' => $subscription->getKey(),
                ]);

                return $renewal;
            });

            // After the commit: a mail failure must not undo a renewal the vendor paid for.
            $this->notifier->renewed($renewal);

            return true;
        } catch (RenewalPaymentFailedException) {
            return $this->failRenewal($subscription);
        } catch (Throwable $exception) {
            Log::error('Failed to auto-renew vendor subscription', [
                'subscription_id' => $subscription->getKey(),
                'message' => $exception->getMessage(),
            ]);

            // The debit rolled back with the transaction, so telling the vendor the charge
            // did not go through is now accurate.
            return $this->failRenewal($subscription);
        }
    }

    /**
     * Tell the vendor the charge was attempted and failed, once per billing period.
     *
     * Without this the plan simply lapses days later with a generic expiry notice, and a
     * vendor who only needed to top up their balance never learns why. There is no retry
     * machinery to add: a failed subscription stays inside the command's due window and is
     * attempted again every night until the grace period runs out — the ledger claim is
     * what stops that turning into a nightly mailshot.
     *
     * Keyed on the period's end date, so an admin extending ends_at re-arms the warning
     * for the new period, which is the behaviour you would want.
     *
     * Always returns false: the caller's contract is "did the renewal happen".
     */
    protected function failRenewal(VendorSubscription $subscription): bool
    {
        // This runs from inside autoRenew()'s catch block, so it must not throw: the
        // command drives it from a chunkById loop, and an exception escaping here would
        // abandon every remaining vendor's renewal for the night.
        try {
            $key = $subscription->ends_at?->toDateString() ?: (string) $subscription->getKey();

            $claimed = $this->ledger->claim(
                $subscription,
                VendorSubscriptionNotification::TYPE_RENEWAL_FAILED,
                [$key]
            );

            if ($claimed === 0) {
                return false;
            }

            if (! $this->notifier->renewalFailed($subscription)) {
                $this->ledger->release($subscription, VendorSubscriptionNotification::TYPE_RENEWAL_FAILED, [$key]);

                return false;
            }

            $this->subscribeService->log($subscription, VendorSubscriptionLog::TYPE_RENEWAL_FAILED);

            $this->ledger->markSent(
                $subscription,
                VendorSubscriptionNotification::TYPE_RENEWAL_FAILED,
                [$key]
            );
        } catch (Throwable $exception) {
            Log::error('Failed to notify a vendor of a failed subscription renewal', [
                'subscription_id' => $subscription->getKey(),
                'message' => $exception->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Send the closest matching reminder, once per configured offset.
     *
     * @param  array<int, int>  $reminderDays
     */
    public function remind(VendorSubscription $subscription, array $reminderDays): bool
    {
        $daysLeft = $subscription->daysUntilExpiry();

        if ($daysLeft === null || $daysLeft < 0) {
            return false;
        }

        // Every window we have already entered, not just the one we just crossed. A
        // vendor 3 days out has also passed the 7-day mark, so both are recorded and
        // neither can fire again on tomorrow's run.
        $reached = array_values(array_filter($reminderDays, fn (int $day) => $daysLeft <= $day));

        if (! $reached) {
            return false;
        }

        // Claim every reached window in one insert, before sending. If nothing was newly
        // claimed an earlier run already covered all of them, so there is nothing to send.
        $claimed = $this->ledger->claim(
            $subscription,
            VendorSubscriptionNotification::TYPE_EXPIRING,
            $reached
        );

        if ($claimed === 0) {
            return false;
        }

        if (! $this->notifier->expiring($subscription, $daysLeft)) {
            // Nothing to send — give the windows back rather than burning them.
            $this->ledger->release($subscription, VendorSubscriptionNotification::TYPE_EXPIRING, $reached);

            return false;
        }

        $this->ledger->markSent(
            $subscription,
            VendorSubscriptionNotification::TYPE_EXPIRING,
            $reached
        );

        $this->subscribeService->log($subscription, VendorSubscriptionLog::TYPE_REMINDER_SENT, [
            'days_left' => $daysLeft,
        ]);

        return true;
    }

    public function expire(VendorSubscription $subscription): void
    {
        $this->subscribeService->expire($subscription);
    }
}
