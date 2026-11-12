<?php

namespace Botble\Marketplace\Http\Controllers\Settings;

use Botble\Base\Facades\DashboardMenu;
use Botble\Base\Supports\Helper;
use Botble\Ecommerce\Models\ProductCategory;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Forms\Settings\MarketplaceSettingForm;
use Botble\Marketplace\Http\Requests\MarketPlaceSettingFormRequest;
use Botble\Marketplace\Models\Store;
use Botble\Marketplace\Services\SubscriptionProductVisibilityService;
use Botble\Marketplace\Services\VendorSubscriptionService;
use Botble\Setting\Facades\Setting;
use Illuminate\Support\Arr;

class MarketplaceSettingController extends SettingController
{
    public function edit()
    {
        $this->pageTitle(trans('plugins/marketplace::marketplace.name'));

        $productCategories = ProductCategory::query()->get();

        $form = MarketplaceSettingForm::create();

        return view('plugins/marketplace::settings.index', compact('productCategories', 'form'));
    }

    public function update(MarketPlaceSettingFormRequest $request)
    {
        $validated = Arr::except($request->validated(), 'payout_methods');

        if ($payoutMethod = $request->input('payout_methods')) {
            $preFix = MarketplaceHelper::getSettingKey();

            foreach ($payoutMethod as $key => $value) {
                Setting::set($preFix . 'payout_methods.' . $key, $value);
            }
        }

        if ($request->input('enable_commission_fee_for_each_category')) {
            $commissionByCategories = $request->input('commission_by_category');
            Store::handleCommissionEachCategory($commissionByCategories);
        }

        $preVerifyVendor = MarketplaceHelper::getSetting('verify_vendor', 1);
        $preMode = MarketplaceHelper::getMode();

        if (in_array('fee_per_order', array_keys($validated))) {
            $value = $validated['fee_per_order'];
            $validated['fee_per_order'] = $value < 0 ? 0 : min($value, 100);
        }

        $this->saveSettings($validated);

        if ($preVerifyVendor != MarketplaceHelper::getSetting('verify_vendor', 1)) {
            Helper::clearCache();
        }

        $missingDefaultPlan = false;

        // Switching mode changes which admin and vendor menu items are registered,
        // and both menus are cached per user.
        if ($preMode !== MarketplaceHelper::getMode()) {
            Helper::clearCache();
            DashboardMenu::clearCaches();

            // Leaving subscription mode strands anything expiry had unpublished: commission
            // mode has no subscriptions, so nothing would ever republish them and the
            // vendor's catalogue would stay dark with no way back.
            if (MarketplaceHelper::getMode() === MarketplaceModeEnum::COMMISSION) {
                app(SubscriptionProductVisibilityService::class)->restoreAll();
            }

            // Entering a subscription-bearing mode with no published default plan leaves every
            // vendor without one unable to publish, which locks them out of the vendor area.
            // The save still goes through: the admin may be on their way to create the plan.
            $missingDefaultPlan = MarketplaceHelper::hasVendorSubscriptions()
                && ! app(VendorSubscriptionService::class)->defaultPlan();
        }

        DashboardMenu::clearCachesForCurrentUser();

        if ($missingDefaultPlan) {
            return $this
                ->httpResponse()
                ->setNextUrl(route('marketplace.settings'))
                ->setMessage(trans('plugins/marketplace::subscription.settings.no_default_plan_warning'));
        }

        return $this
            ->httpResponse()
            ->setNextUrl(route('marketplace.settings'))
            ->withUpdatedSuccessMessage();
    }
}
