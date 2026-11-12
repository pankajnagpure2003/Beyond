<?php

namespace Botble\Marketplace\Services;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Ecommerce\Models\Customer;
use Botble\Ecommerce\Models\Product;
use Botble\Marketplace\Enums\SubscriptionStatusEnum;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Models\Scopes\HideProductsByLockedVendorScope;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\VendorSubscription;

/**
 * Read-only questions about a vendor's subscription: which one is current, and what
 * it allows them to do. All the "can this vendor do X?" answers live here so the
 * controllers, the middleware and the Blade templates can never disagree.
 */
class VendorSubscriptionService
{
    /** @var array<int, VendorSubscription|null> */
    protected array $cache = [];

    public function __construct(protected SubscribeVendorService $subscribeVendorService)
    {
    }

    /**
     * The vendor's current subscription. When they have none and the admin has published
     * a default plan, one is created lazily — that is how existing vendors keep working
     * the moment the marketplace is switched to subscription mode.
     */
    /**
     * @param  bool  $assignDefault  whether a vendor with no plan should lazily be given the
     *                               free default one. Callers running inside a model event or
     *                               someone else's transaction must pass false: the assignment
     *                               opens its own transaction and republishes the vendor's
     *                               catalogue, which is not a reasonable side effect of, say,
     *                               saving an unrelated product.
     */
    public function current(Customer $vendor, bool $assignDefault = true): ?VendorSubscription
    {
        // Nothing to resolve, and nothing to create, while the marketplace runs on
        // commission — otherwise merely viewing a page would seed subscription rows.
        if (! MarketplaceHelper::hasVendorSubscriptions()) {
            return null;
        }

        $key = (int) $vendor->getKey();

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $subscription = VendorSubscription::query()
            ->where('customer_id', $vendor->getKey())
            ->where('status', SubscriptionStatusEnum::ACTIVE)
            ->orderByDesc('id')
            ->first();

        if ($subscription && $subscription->isExpired() && $subscription->isPastGracePeriod(
            MarketplaceHelper::subscriptionGracePeriodDays()
        )) {
            $subscription = null;
        }

        if (! $subscription) {
            if (! $assignDefault) {
                // Deliberately not cached: a later caller that *is* allowed to assign the
                // default plan must not be handed this null.
                return null;
            }

            $subscription = $this->assignDefaultPlan($vendor);
        }

        return $this->cache[$key] = $subscription;
    }

    /**
     * The vendor's most recent subscription regardless of status — used to show a
     * pending-approval or rejection notice.
     */
    public function latest(Customer $vendor): ?VendorSubscription
    {
        return VendorSubscription::query()
            ->where('customer_id', $vendor->getKey())
            ->orderByDesc('id')
            ->first();
    }

    public function pending(Customer $vendor): ?VendorSubscription
    {
        return VendorSubscription::query()
            ->where('customer_id', $vendor->getKey())
            ->where('status', SubscriptionStatusEnum::PENDING)
            ->orderByDesc('id')
            ->first();
    }

    public function defaultPlan(): ?SubscriptionPlan
    {
        return SubscriptionPlan::query()
            ->where('is_default', true)
            ->where('status', BaseStatusEnum::PUBLISHED)
            ->first();
    }

    protected function assignDefaultPlan(Customer $vendor): ?VendorSubscription
    {
        $plan = $this->defaultPlan();

        if (! $plan) {
            return null;
        }

        return $this->subscribeVendorService->activateFreePlan($vendor, $plan);
    }

    public function canPublishProducts(Customer $vendor): bool
    {
        if (! MarketplaceHelper::hasVendorSubscriptions()) {
            return true;
        }

        return (bool) $this->current($vendor);
    }

    /**
     * Maximum products the vendor's plan allows. Null means unlimited.
     */
    public function productLimit(Customer $vendor): ?int
    {
        $subscription = $this->current($vendor);

        if (! $subscription || $subscription->isUnlimited('product_limit')) {
            return null;
        }

        return $subscription->option('product_limit');
    }

    public function usedProductSlots(Customer $vendor): int
    {
        $storeId = $vendor->store?->getKey();

        if (! $storeId) {
            return 0;
        }

        // HideProductsByLockedVendorScope is registered globally on Product for every
        // non-console request and filters to store.status = published. Leaving it on would
        // report 0 used slots — and therefore an unlimited quota — for any vendor whose
        // store is not published. Quota accounting must see the vendor's real catalogue.
        return Product::query()
            ->withoutGlobalScope(HideProductsByLockedVendorScope::class)
            ->where('store_id', $storeId)
            ->where('is_variation', false)
            ->count();
    }

    public function remainingProductSlots(Customer $vendor): ?int
    {
        $limit = $this->productLimit($vendor);

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->usedProductSlots($vendor));
    }

    public function canCreateProduct(Customer $vendor): bool
    {
        if (! MarketplaceHelper::hasVendorSubscriptions()) {
            return true;
        }

        if (! $this->canPublishProducts($vendor)) {
            return false;
        }

        $remaining = $this->remainingProductSlots($vendor);

        return $remaining === null || $remaining > 0;
    }

    /**
     * Whether the vendor's plan enables a feature flag such as allow_coupons.
     */
    public function allows(Customer $vendor, string $option): bool
    {
        if (! MarketplaceHelper::hasVendorSubscriptions()) {
            return true;
        }

        $subscription = $this->current($vendor);

        return $subscription && $subscription->allows($option);
    }

    public function forget(Customer $vendor): void
    {
        unset($this->cache[(int) $vendor->getKey()]);
    }
}
