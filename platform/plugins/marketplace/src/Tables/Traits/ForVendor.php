<?php

namespace Botble\Marketplace\Tables\Traits;

use Botble\Marketplace\Facades\MarketplaceHelper;

trait ForVendor
{
    public function booted(): void
    {
        $this
            ->setView(MarketplaceHelper::viewPath('vendor-dashboard.table.base'))
            ->bulkChangeUrl(route('marketplace.vendor.table.bulk-change.save'))
            ->bulkChangeDataUrl(route('marketplace.vendor.table.bulk-change.data'))
            ->bulkActionDispatchUrl(route('marketplace.vendor.table.bulk-action.dispatch'))
            ->filterInputUrl(route('marketplace.vendor.table.filter.input'));
    }

    /**
     * Vendor tables intentionally run without an ACL admin user (customer/vendor
     * guard), so they opt out of TableAbstract::hasPermission()'s default deny.
     */
    public function isVendorScopedTable(): bool
    {
        return true;
    }

    /**
     * A vendor may only bulk-act on / bulk-change rows their own scoped query()
     * would actually list - e.g. another vendor's product must never be
     * reachable just because its id was guessed. Intersect the requested ids
     * with what the table's own query() returns instead of trusting the raw
     * request input.
     */
    protected function scopeBulkActionIds(array $ids): array
    {
        if (empty($ids)) {
            return $ids;
        }

        $keyName = $this->getModel()->getKeyName();

        $allowedIds = array_map(
            'strval',
            $this->query()->whereIn($keyName, $ids)->pluck($keyName)->all()
        );

        return array_values(array_filter($ids, fn ($id) => in_array((string) $id, $allowedIds, true)));
    }
}
