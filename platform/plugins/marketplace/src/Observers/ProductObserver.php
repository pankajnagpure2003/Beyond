<?php

namespace Botble\Marketplace\Observers;

use Botble\Ecommerce\Models\Product;
use Botble\Marketplace\Exceptions\ProductLimitExceededException;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Models\Store;
use Botble\Marketplace\Services\VendorSubscriptionService;
use Illuminate\Support\Facades\Cache;

class ProductObserver
{
    /**
     * Enforce the subscription's product allowance at the point every path converges on.
     *
     * The vendor UI has its own guard in ProductController, which gives a friendlier
     * redirect; this is the backstop for the CSV importer, which had no check at all and
     * let a vendor on an import-enabled plan blow straight past their limit.
     *
     * IMPORTANT — why this is inert on the vendor UI and the admin screen: both save the
     * product *first* and assign store_id afterwards (ProductController::store() and the
     * `created_content` hook), so store_id is null here on those paths. That is also what
     * keeps seeders and the test suite unaffected. If either path is ever changed to set
     * store_id before the first save, this guard starts firing there and the vendor UI
     * will throw instead of redirecting — move the controller guard earlier if that happens.
     */
    public function creating(Product $product): void
    {
        if (! MarketplaceHelper::hasVendorSubscriptions()) {
            return;
        }

        // A product with three variations must cost one slot, not four.
        // VendorSubscriptionService::usedProductSlots() counts the same way.
        if ($product->is_variation || ! $product->store_id) {
            return;
        }

        // Store::customer() is a withDefault() relation, so an orphaned store yields a
        // hollow Customer rather than null — hence the exists() check.
        $vendor = Store::query()->find($product->store_id)?->customer;

        if (! $vendor || ! $vendor->exists) {
            return;
        }

        $service = app(VendorSubscriptionService::class);

        // Read-only: canCreateProduct() would lazily assign the free default plan, which
        // opens a transaction and republishes the vendor's catalogue — not something a
        // product save should trigger. Anything reaching here has already passed
        // RequireActiveVendorSubscription, so a null plan means "not our business".
        $subscription = $service->current($vendor, assignDefault: false);

        if (! $subscription || $subscription->isUnlimited('product_limit')) {
            return;
        }

        $limit = $subscription->option('product_limit');
        $used = $service->usedProductSlots($vendor);

        if ($used < $limit) {
            return;
        }

        throw new ProductLimitExceededException($limit, $used);
    }

    /**
     * Handle the Product "saved" event.
     */
    public function saved(Product $product): void
    {
        $this->clearCache($product);
    }

    /**
     * Handle the Product "deleted" event.
     */
    public function deleted(Product $product): void
    {
        $this->clearCache($product);
    }

    /**
     * Clear vendor categories cache
     */
    protected function clearCache(Product $product): void
    {
        // Only clear cache if the product has a store_id
        if ($product->store_id) {
            $cacheKey = 'marketplace_store_categories_' . $product->store_id;
            Cache::forget($cacheKey);
        }

        // If store_id changed, clear cache for the old store as well
        if ($product->wasChanged('store_id') && $product->getOriginal('store_id')) {
            $oldCacheKey = 'marketplace_store_categories_' . $product->getOriginal('store_id');
            Cache::forget($oldCacheKey);
        }
    }
}
