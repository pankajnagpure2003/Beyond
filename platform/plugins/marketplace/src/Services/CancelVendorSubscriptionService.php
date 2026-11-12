<?php

namespace Botble\Marketplace\Services;

use Botble\Ecommerce\Models\Customer;
use Botble\Marketplace\Enums\SubscriptionStatusEnum;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Models\VendorSubscriptionLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ends a vendor's own subscription immediately, at their request.
 *
 * Distinct from turning off auto-renew, which lets the plan run to its end date. This is
 * the irreversible one: access stops now and there is no refund for the unused time, so
 * the caller is expected to have taken a deliberate confirmation first.
 *
 * The row is kept and moved to CANCELLED rather than deleted, so the vendor's history and
 * the invoices attached to it stay intact.
 */
class CancelVendorSubscriptionService
{
    public function __construct(
        protected SubscribeVendorService $subscribeService,
        protected SubscriptionProductVisibilityService $productVisibility,
        protected VendorSubscriptionService $subscriptionService
    ) {
    }

    public function isEnabled(): bool
    {
        return MarketplaceHelper::hasVendorSubscriptions()
            && (bool) MarketplaceHelper::getSetting('subscription_allow_vendor_cancel', true);
    }

    /**
     * @throws RuntimeException when the admin has disabled vendor cancellation, or the
     *                          vendor has no active subscription to cancel
     */
    public function handle(Customer $vendor): VendorSubscription
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException(trans('plugins/marketplace::subscription.vendor.cancel_not_allowed'));
        }

        $subscription = $this->subscriptionService->current($vendor);

        if (! $subscription) {
            throw new RuntimeException(trans('plugins/marketplace::subscription.vendor.no_plan'));
        }

        DB::transaction(function () use ($subscription): void {
            $subscription->fill([
                'status' => SubscriptionStatusEnum::CANCELLED,
                'auto_renew' => false,
                'cancelled_at' => Carbon::now(),
            ])->save();

            $this->subscribeService->log($subscription, VendorSubscriptionLog::TYPE_VENDOR_CANCELLED);
        });

        // Honours the same setting as expiry: an admin who leaves products published when
        // a plan lapses expects the same on cancellation.
        if (MarketplaceHelper::shouldUnpublishProductsOnSubscriptionExpired()) {
            $this->productVisibility->unpublish($vendor);
        }

        $this->subscriptionService->forget($vendor);

        return $subscription;
    }
}
