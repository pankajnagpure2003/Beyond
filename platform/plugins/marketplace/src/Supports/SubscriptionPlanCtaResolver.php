<?php

namespace Botble\Marketplace\Supports;

use Botble\Ecommerce\Facades\EcommerceHelper;
use Botble\Ecommerce\Models\Customer;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Services\VendorSubscriptionService;
use Illuminate\Support\Facades\Route;

class SubscriptionPlanCtaResolver
{
    /**
     * @var array<int, VendorSubscription|null>
     */
    protected array $currentSubscriptionCache = [];

    /**
     * @var array<int, VendorSubscription|null>
     */
    protected array $pendingSubscriptionCache = [];

    /**
     * @return array{url: string|null, label: string, disabled: bool}
     */
    public function resolve(SubscriptionPlan $plan): array
    {
        $customer = auth('customer')->user();

        if (! $customer) {
            return EcommerceHelper::isCustomerRegistrationEnabled() && Route::has('customer.register')
                ? $this->cta(route('customer.register'), trans('plugins/marketplace::subscription.public.get_started'))
                : $this->cta(route('customer.login'), trans('plugins/marketplace::subscription.public.sign_in'));
        }

        if (! $customer->is_vendor) {
            return MarketplaceHelper::isVendorRegistrationEnabled() && Route::has('marketplace.vendor.become-vendor')
                ? $this->cta(
                    route('marketplace.vendor.become-vendor'),
                    trans('plugins/marketplace::subscription.public.become_vendor')
                )
                : $this->cta(null, trans('plugins/marketplace::subscription.public.become_vendor'), true);
        }

        if (MarketplaceHelper::getSetting('verify_vendor', true) && ! $customer->vendor_verified_at) {
            return Route::has('marketplace.vendor.become-vendor')
                ? $this->cta(
                    route('marketplace.vendor.become-vendor'),
                    trans('plugins/marketplace::subscription.public.become_vendor')
                )
                : $this->cta(null, trans('plugins/marketplace::subscription.public.become_vendor'), true);
        }

        if ($this->pendingSubscription($customer)) {
            return $this->cta(
                route('marketplace.vendor.subscriptions.index'),
                trans('plugins/marketplace::subscription.vendor.pending_title')
            );
        }

        $current = $this->currentSubscription($customer);

        if ($current && (int) $current->subscription_plan_id === $plan->getKey()) {
            return $this->cta(null, trans('plugins/marketplace::subscription.vendor.current_plan'), true);
        }

        return $this->cta(
            route('marketplace.vendor.subscriptions.checkout', $plan->getKey()),
            trans($current
                ? 'plugins/marketplace::subscription.vendor.change_plan'
                : 'plugins/marketplace::subscription.vendor.subscribe')
        );
    }

    protected function currentSubscription(Customer $customer): ?VendorSubscription
    {
        $key = (int) $customer->getKey();

        if (array_key_exists($key, $this->currentSubscriptionCache)) {
            return $this->currentSubscriptionCache[$key];
        }

        return $this->currentSubscriptionCache[$key] = rescue(
            fn () => app(VendorSubscriptionService::class)->current($customer, false),
            null,
            false
        );
    }

    protected function pendingSubscription(Customer $customer): ?VendorSubscription
    {
        $key = (int) $customer->getKey();

        if (array_key_exists($key, $this->pendingSubscriptionCache)) {
            return $this->pendingSubscriptionCache[$key];
        }

        return $this->pendingSubscriptionCache[$key] = rescue(
            fn () => app(VendorSubscriptionService::class)->pending($customer),
            null,
            false
        );
    }

    /**
     * @return array{url: string|null, label: string, disabled: bool}
     */
    protected function cta(?string $url, string $label, bool $disabled = false): array
    {
        return [
            'url' => $url,
            'label' => $label,
            'disabled' => $disabled,
        ];
    }
}
