<?php

namespace Botble\Marketplace\Http\Controllers\Fronts;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\SeoHelper\Facades\SeoHelper;
use Botble\Theme\Facades\Theme;

class PublicSubscriptionPlanController extends BaseController
{
    public function index()
    {
        abort_unless(MarketplaceHelper::isPublicSubscriptionPlansEnabled(), 404);

        $title = trans('plugins/marketplace::subscription.plans.name');

        Theme::breadcrumb()->add($title, route('public.marketplace.subscription-plans'));

        SeoHelper::setTitle($title)
            ->setDescription(trans('plugins/marketplace::subscription.public.description'));

        $plans = SubscriptionPlan::query()->available()->get();

        return Theme::scope(
            'marketplace.subscription-plans',
            compact('plans', 'title'),
            MarketplaceHelper::viewPath('subscription-plans', false)
        )->render();
    }
}
