<?php

namespace Botble\Marketplace\Services;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Ecommerce\Models\Customer;
use Botble\Ecommerce\Models\Product;
use Illuminate\Support\Carbon;

/**
 * Hides and restores a vendor's products around subscription expiry.
 *
 * Expiry stamps ec_products.unpublished_by_subscription_at so renewal can republish
 * exactly the products we took down, and never touches products the vendor drafted
 * themselves.
 */
class SubscriptionProductVisibilityService
{
    public function unpublish(Customer $vendor): int
    {
        $storeId = $vendor->store?->getKey();

        if (! $storeId) {
            return 0;
        }

        return Product::query()
            ->where('store_id', $storeId)
            ->where('status', BaseStatusEnum::PUBLISHED)
            ->whereNull('unpublished_by_subscription_at')
            ->update([
                'status' => BaseStatusEnum::DRAFT,
                'unpublished_by_subscription_at' => Carbon::now(),
            ]);
    }

    /**
     * Restore the products expiry took down.
     *
     * $limit is the new plan's product ceiling (null = unlimited). A vendor dropping to a
     * smaller plan gets the oldest products back up to that ceiling; the rest stay drafted
     * and keep their stamp, so a later upgrade restores them too.
     */
    /**
     * Restore every product any subscription ever unpublished, across all vendors.
     *
     * Only republish() undoes an expiry, and it only runs when a subscription activates —
     * so a marketplace leaving subscription mode would strand those products offline
     * permanently, with no subscription left in the system to bring them back.
     */
    public function restoreAll(): int
    {
        return Product::query()
            ->whereNotNull('unpublished_by_subscription_at')
            ->update([
                'status' => BaseStatusEnum::PUBLISHED,
                'unpublished_by_subscription_at' => null,
            ]);
    }

    public function republish(Customer $vendor, ?int $limit = null): int
    {
        $storeId = $vendor->store?->getKey();

        if (! $storeId) {
            return 0;
        }

        $query = Product::query()
            ->where('store_id', $storeId)
            ->whereNotNull('unpublished_by_subscription_at');

        if ($limit === null) {
            return $query->update([
                'status' => BaseStatusEnum::PUBLISHED,
                'unpublished_by_subscription_at' => null,
            ]);
        }

        $alreadyPublished = Product::query()
            ->where('store_id', $storeId)
            ->where('status', BaseStatusEnum::PUBLISHED)
            ->where('is_variation', false)
            ->count();

        $slots = max(0, $limit - $alreadyPublished);

        if ($slots === 0) {
            return 0;
        }

        $ids = $query->orderBy('id')->limit($slots)->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return Product::query()
            ->whereIn('id', $ids)
            ->update([
                'status' => BaseStatusEnum::PUBLISHED,
                'unpublished_by_subscription_at' => null,
            ]);
    }
}
