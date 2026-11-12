<?php

namespace Botble\Marketplace\Services;

use Botble\Ecommerce\Models\Customer;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Supports\CommissionRate;

/**
 * The single place that decides what commission a given vendor pays.
 *
 * Commission mode  - the global settings, unchanged.
 * Subscription mode - nothing; the marketplace earns from plans instead.
 * Hybrid mode      - the vendor's plan may override either half of the global rate.
 *
 * No memoisation here on purpose: VendorSubscriptionService is registered scoped() and
 * already caches per vendor id for the request, so resolving twice costs one array lookup.
 */
class CommissionFeeResolver
{
    public function __construct(protected VendorSubscriptionService $subscriptions)
    {
    }

    public function forVendor(Customer $vendor): CommissionRate
    {
        if (! MarketplaceHelper::shouldChargeCommission()) {
            return CommissionRate::none();
        }

        $percentage = (float) MarketplaceHelper::getSetting('fee_per_order', 0);
        $fixed = (float) MarketplaceHelper::getSetting('fee_per_order_fixed', 0);

        // Commission mode has no subscriptions to read, so this short-circuits before any query.
        if (MarketplaceHelper::hasVendorSubscriptions()) {
            [$percentage, $fixed] = $this->applyPlanOverride($vendor, $percentage, $fixed);
        }

        return new CommissionRate($percentage, $fixed);
    }

    /**
     * A plan option of -1 means "inherit the global rate"; 0 is a real rate meaning the plan
     * pays nothing. A vendor with no subscription row keeps the global rate.
     *
     * @return array{float, float}
     */
    protected function applyPlanOverride(Customer $vendor, float $percentage, float $fixed): array
    {
        // assignDefault: false is mandatory - this runs inside the order transaction, and the
        // default-plan assignment opens its own transaction and republishes the catalogue.
        $subscription = $this->subscriptions->current($vendor, assignDefault: false);

        if (! $subscription) {
            return [$percentage, $fixed];
        }

        $planPercentage = (float) $subscription->option('commission_percentage');
        $planFixed = (float) $subscription->option('commission_fixed');

        return [
            $planPercentage >= 0 ? $planPercentage : $percentage,
            $planFixed >= 0 ? $planFixed : $fixed,
        ];
    }
}
