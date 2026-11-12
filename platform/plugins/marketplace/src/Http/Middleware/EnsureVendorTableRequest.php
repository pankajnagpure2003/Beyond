<?php

namespace Botble\Marketplace\Http\Middleware;

use Botble\Marketplace\Tables\Traits\ForVendor;
use Closure;
use Illuminate\Http\Request;

/**
 * `marketplace/routes/vendor.php` mounts the shared core table routes
 * (`table/routes/web-actions.php`: bulk-actions, bulk-changes, filters, columns
 * visibility) inside the vendor panel. Those controllers instantiate whatever
 * `TableAbstract` subclass the request names (`bulk_action_table` / `class` /
 * `table`) with no further authorization - a vendor could otherwise name ANY
 * table class (admin-only tables such as `BrandTable`, the core `UserTable`,
 * even another plugin's table) and run bulk deletes / bulk changes / filters
 * against it, since `TableAbstract::hasPermission()` treats "no admin user"
 * (always true for a vendor/customer-guard request) as "allowed".
 *
 * Restrict every table request made through the vendor panel to table classes
 * that explicitly opt into running in the vendor context via the `ForVendor`
 * trait.
 */
class EnsureVendorTableRequest
{
    /**
     * Request keys the core table routes use to name a TableAbstract class.
     */
    protected const TABLE_CLASS_INPUTS = ['bulk_action_table', 'class', 'table'];

    public function handle(Request $request, Closure $next)
    {
        foreach (self::TABLE_CLASS_INPUTS as $key) {
            $class = $request->input($key);

            if (! $class) {
                continue;
            }

            if (! is_string($class) || ! $this->isVendorTable($class)) {
                abort(403);
            }
        }

        return $next($request);
    }

    protected function isVendorTable(string $class): bool
    {
        return class_exists($class) && in_array(ForVendor::class, class_uses_recursive($class), true);
    }
}
