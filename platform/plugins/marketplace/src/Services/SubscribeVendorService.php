<?php

namespace Botble\Marketplace\Services;

use Botble\Ecommerce\Models\Customer;
use Botble\Marketplace\Enums\SubscriptionDurationUnitEnum;
use Botble\Marketplace\Enums\SubscriptionStatusEnum;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Models\VendorSubscriptionLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The vendor subscription state machine. Every transition asserts its precondition and
 * throws otherwise, so an illegal move fails loudly instead of leaving a vendor with two
 * active plans.
 */
class SubscribeVendorService
{
    public function __construct(
        protected SubscriptionProductVisibilityService $productVisibility,
        protected SubscriptionNotifier $notifier,
        protected SubscriptionTaxService $taxService,
        protected CreateSubscriptionInvoiceService $invoiceService
    ) {
    }

    /**
     * Create a PENDING subscription for a plan the vendor selected but has not paid for yet.
     */
    public function claim(Customer $vendor, SubscriptionPlan $plan, array $attributes = []): VendorSubscription
    {
        $billing = (array) ($attributes['billing_data'] ?? []);
        $subTotal = (float) ($attributes['amount'] ?? $plan->price);

        // Tax is exclusive: sub_total is the plan price, amount is what the vendor pays.
        // Both the rate and the amount are frozen here so a later rate change cannot
        // alter what was charged.
        $tax = $this->taxService->calculate($subTotal, $billing);

        $subscription = VendorSubscription::query()->create(array_merge([
            'customer_id' => $vendor->getKey(),
            'subscription_plan_id' => $plan->getKey(),
            'plan_data' => $plan->toSnapshot(),
            'currency' => get_application_currency()->title,
            'status' => SubscriptionStatusEnum::PENDING,
        ], $attributes, [
            'sub_total' => $subTotal,
            'tax_rate' => $tax['rate'],
            'tax_amount' => $tax['amount'],
            'amount' => round($subTotal + $tax['amount'], 2),
        ]));

        // A vendor who already holds a plan is switching, not buying for the first time.
        // Recording that distinction is what makes the audit trail readable later. A
        // renewal is excluded: it keeps the same plan and logs its own renewal event.
        $previous = $subscription->renewed_from_id
            ? null
            : $this->currentPlanOf($vendor, $subscription);

        if ($previous) {
            $this->log($subscription, VendorSubscriptionLog::TYPE_CHANGED_PLAN, [
                'from' => $previous->planName(),
                'plan' => $plan->name,
            ]);
        } else {
            $this->log($subscription, VendorSubscriptionLog::TYPE_CLAIMED, ['plan' => $plan->name]);
        }

        return $subscription;
    }

    /**
     * The active subscription the vendor holds right now, ignoring the row just created.
     */
    protected function currentPlanOf(Customer $vendor, VendorSubscription $exclude): ?VendorSubscription
    {
        return VendorSubscription::query()
            ->where('customer_id', $vendor->getKey())
            ->where('id', '!=', $exclude->getKey())
            ->where('status', SubscriptionStatusEnum::ACTIVE)
            ->latest('id')
            ->first();
    }

    /**
     * Immediately grant a free plan. Used for the lazily assigned default plan.
     */
    public function activateFreePlan(Customer $vendor, SubscriptionPlan $plan): VendorSubscription
    {
        $subscription = $this->claim($vendor, $plan, ['amount' => 0]);

        // No invoice: this plan is assigned automatically, not purchased, so billing
        // paperwork for it would be noise in the vendor's ledger.
        return $this->activate($subscription, issueInvoice: false);
    }

    /**
     * Move a subscription to ACTIVE, ending whatever the vendor had before.
     *
     * $from lets a renewal continue from the previous end date instead of now, so a late
     * cron run never shortens a paid period.
     */
    public function activate(
        VendorSubscription $subscription,
        ?Carbon $from = null,
        bool $issueInvoice = true
    ): VendorSubscription {
        if ($subscription->status == SubscriptionStatusEnum::ACTIVE) {
            return $subscription;
        }

        if (! in_array($subscription->status->getValue(), [
            SubscriptionStatusEnum::PENDING,
            SubscriptionStatusEnum::EXPIRED,
        ], true)) {
            throw new LogicException(
                sprintf('Cannot activate a subscription with status [%s].', $subscription->status->getValue())
            );
        }

        DB::transaction(function () use ($subscription, $from): void {
            // Lock the vendor's rows so two concurrent callbacks cannot both activate.
            VendorSubscription::query()
                ->where('customer_id', $subscription->customer_id)
                ->lockForUpdate()
                ->get();

            VendorSubscription::query()
                ->where('customer_id', $subscription->customer_id)
                ->where('id', '!=', $subscription->getKey())
                ->where('status', SubscriptionStatusEnum::ACTIVE)
                ->update(['status' => SubscriptionStatusEnum::EXPIRED]);

            $startsAt = $from ?: Carbon::now();

            $subscription->fill([
                'status' => SubscriptionStatusEnum::ACTIVE,
                'starts_at' => $startsAt,
                'ends_at' => $this->calculateEndsAt($subscription, $startsAt),
            ])->save();

            $vendor = $subscription->customer;

            if ($vendor) {
                // Restore only as many products as the new plan actually allows, so a
                // downgrade cannot silently republish a bigger plan's catalogue.
                $this->productVisibility->republish($vendor, $this->productLimitFor($subscription));
            }

            $this->log($subscription, VendorSubscriptionLog::TYPE_ACTIVATED);
        });

        $this->forgetCachedSubscription($subscription);

        if ($issueInvoice) {
            $this->issueInvoice($subscription);
        }

        // Outside the transaction on purpose: a mail failure must not roll back an
        // activation the vendor has already paid for.
        $this->notifier->activated($subscription);

        return $subscription;
    }

    public function expire(VendorSubscription $subscription): VendorSubscription
    {
        $subscription->fill(['status' => SubscriptionStatusEnum::EXPIRED])->save();

        $vendor = $subscription->customer;

        if ($vendor && MarketplaceHelper::shouldUnpublishProductsOnSubscriptionExpired()) {
            $this->productVisibility->unpublish($vendor);
        }

        $this->forgetCachedSubscription($subscription);

        $this->log($subscription, VendorSubscriptionLog::TYPE_EXPIRED);
        $this->notifier->expired($subscription);

        return $subscription;
    }

    /**
     * Create the successor of an expiring subscription. The new period continues from the
     * old end date so a late run does not cost the vendor days.
     */
    public function createRenewal(VendorSubscription $subscription, string $logType): VendorSubscription
    {
        $plan = $subscription->plan;
        $vendor = $subscription->customer;

        if (! $plan) {
            throw new LogicException('Cannot renew a subscription whose plan no longer exists.');
        }

        if (! $vendor) {
            throw new LogicException('Cannot renew a subscription whose vendor no longer exists.');
        }

        $renewal = $this->claim($vendor, $plan, [
            'renewed_from_id' => $subscription->getKey(),
            'auto_renew' => $subscription->auto_renew,
        ]);

        $from = $subscription->ends_at && $subscription->ends_at->isFuture()
            ? $subscription->ends_at->copy()
            : Carbon::now();

        $renewal = $this->activate($renewal, $from);

        $this->log($renewal, $logType, ['renewed_from' => $subscription->getKey()]);
        $this->notifier->renewed($renewal);

        return $renewal;
    }

    /**
     * Issue the billing document for a charge. Like the notifier, a failure here must
     * not undo an activation the vendor already paid for.
     */
    protected function issueInvoice(VendorSubscription $subscription): void
    {
        try {
            $this->invoiceService->forActivation($subscription, (bool) $subscription->renewed_from_id);
        } catch (Throwable $exception) {
            Log::error('Failed to issue vendor subscription invoice', [
                'subscription_id' => $subscription->getKey(),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Drop the reader's memoised copy for this vendor after a status change.
     */
    protected function forgetCachedSubscription(VendorSubscription $subscription): void
    {
        if ($vendor = $subscription->customer) {
            app(VendorSubscriptionService::class)->forget($vendor);
        }
    }

    public function log(
        VendorSubscription $subscription,
        string $type,
        array $data = [],
        ?int $userId = null
    ): VendorSubscriptionLog {
        return VendorSubscriptionLog::query()->create([
            'vendor_subscription_id' => $subscription->getKey(),
            'type' => $type,
            'data' => $data ?: null,
            'user_id' => $userId,
        ]);
    }

    /**
     * Product ceiling this subscription grants, or null when unlimited.
     */
    protected function productLimitFor(VendorSubscription $subscription): ?int
    {
        return $subscription->isUnlimited('product_limit')
            ? null
            : $subscription->option('product_limit');
    }

    protected function calculateEndsAt(VendorSubscription $subscription, Carbon $startsAt): ?Carbon
    {
        $snapshot = (array) $subscription->plan_data;

        return SubscriptionDurationUnitEnum::addTo(
            (string) ($snapshot['duration_unit'] ?? 'month'),
            max(1, (int) ($snapshot['duration_value'] ?? 1)),
            $startsAt
        );
    }
}
