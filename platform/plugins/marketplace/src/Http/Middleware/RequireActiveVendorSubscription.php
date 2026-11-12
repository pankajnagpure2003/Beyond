<?php

namespace Botble\Marketplace\Http\Middleware;

use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Services\VendorSubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Blocks a vendor without an active subscription from the parts of the dashboard a plan
 * pays for. Inert in commission mode.
 *
 * Never apply this to the dashboard, settings or the subscription routes themselves —
 * an expired vendor has to be able to reach the page where they buy a new plan.
 *
 * Pass a plan option name to also require that feature, e.g. 'subscription:allow_coupons'.
 */
class RequireActiveVendorSubscription
{
    public function __construct(protected VendorSubscriptionService $subscriptionService)
    {
    }

    public function handle(Request $request, Closure $next, ?string $option = null)
    {
        if (! MarketplaceHelper::hasVendorSubscriptions()) {
            return $next($request);
        }

        $vendor = Auth::guard('customer')->user();

        if (! $vendor) {
            return $next($request);
        }

        if (! $this->subscriptionService->canPublishProducts($vendor)) {
            return $this->deny($request, trans('plugins/marketplace::subscription.vendor.subscription_required'));
        }

        if ($option && ! $this->subscriptionService->allows($vendor, $option)) {
            return $this->deny($request, trans('plugins/marketplace::subscription.vendor.feature_not_available'));
        }

        return $next($request);
    }

    protected function deny(Request $request, string $message)
    {
        if ($request->ajax() || $request->wantsJson()) {
            return response($message, 403);
        }

        return redirect()
            ->route('marketplace.vendor.subscriptions.index')
            ->with('error_msg', $message);
    }
}
