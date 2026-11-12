<?php

namespace Botble\Marketplace\Services;

use Botble\Ecommerce\Models\Customer;
use Botble\Marketplace\Enums\SubscriptionStatusEnum;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Models\VendorSubscriptionLog;
use Botble\Payment\Enums\PaymentStatusEnum;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * Admin-driven subscription transitions: the approval queue plus manual assignment,
 * extension and cancellation.
 */
class ManageVendorSubscriptionService
{
    public function __construct(
        protected SubscribeVendorService $subscribeVendorService,
        protected SubscriptionNotifier $notifier
    ) {
    }

    public function approve(VendorSubscription $subscription): VendorSubscription
    {
        if (! $subscription->isPending()) {
            throw new LogicException('Only a pending subscription can be approved.');
        }

        $this->subscribeVendorService->log(
            $subscription,
            VendorSubscriptionLog::TYPE_ADMIN_APPROVED,
            [],
            Auth::id()
        );

        $subscription->payment?->fill(['status' => PaymentStatusEnum::COMPLETED])->save();

        return $this->subscribeVendorService->activate($subscription);
    }

    public function reject(VendorSubscription $subscription, string $reason): VendorSubscription
    {
        if (! $subscription->isPending()) {
            throw new LogicException('Only a pending subscription can be rejected.');
        }

        $subscription->fill([
            'status' => SubscriptionStatusEnum::REJECTED,
            'rejected_reason' => $reason,
        ])->save();

        $this->subscribeVendorService->log(
            $subscription,
            VendorSubscriptionLog::TYPE_ADMIN_REJECTED,
            ['reason' => $reason],
            Auth::id()
        );

        $this->notifier->rejected($subscription);

        return $subscription;
    }

    /**
     * Grant a plan directly, with no payment.
     */
    public function assign(Customer $vendor, SubscriptionPlan $plan, ?Carbon $endsAt = null): VendorSubscription
    {
        $subscription = $this->subscribeVendorService->claim($vendor, $plan, [
            'amount' => 0,
            'created_by_id' => Auth::id(),
            'created_by_type' => Auth::user() ? Auth::user()::class : null,
        ]);

        $this->subscribeVendorService->log(
            $subscription,
            VendorSubscriptionLog::TYPE_ADMIN_ASSIGNED,
            ['plan' => $plan->name],
            Auth::id()
        );

        $subscription = $this->subscribeVendorService->activate($subscription);

        if ($endsAt) {
            $subscription->fill(['ends_at' => $endsAt])->save();
        }

        return $subscription;
    }

    public function extend(VendorSubscription $subscription, int $days): VendorSubscription
    {
        if ($subscription->isLifetime()) {
            return $subscription;
        }

        $base = $subscription->ends_at && $subscription->ends_at->isFuture()
            ? $subscription->ends_at->copy()
            : Carbon::now();

        $subscription->fill([
            'ends_at' => $base->addDays($days),
            'status' => SubscriptionStatusEnum::ACTIVE,
        ])->save();

        $this->subscribeVendorService->log(
            $subscription,
            VendorSubscriptionLog::TYPE_RENEWED,
            ['days' => $days],
            Auth::id()
        );

        return $subscription;
    }

    public function cancel(VendorSubscription $subscription): VendorSubscription
    {
        $subscription->fill([
            'status' => SubscriptionStatusEnum::CANCELLED,
            'auto_renew' => false,
            'cancelled_at' => Carbon::now(),
        ])->save();

        $this->subscribeVendorService->log(
            $subscription,
            VendorSubscriptionLog::TYPE_CANCELLED,
            [],
            Auth::id()
        );

        return $subscription;
    }
}
