<?php

namespace Botble\Marketplace\Shortcodes;

use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Shortcode\Compilers\Shortcode;
use Botble\Theme\Facades\Theme;

class SubscriptionPlansShortcode
{
    public function render(Shortcode $shortcode): string
    {
        if (! MarketplaceHelper::hasVendorSubscriptions()) {
            return '';
        }

        $limit = (int) $shortcode->limit;

        $plans = SubscriptionPlan::query()
            ->available()
            ->when($limit > 0, fn ($query) => $query->limit($limit))
            ->get();

        if ($plans->isEmpty()) {
            return '';
        }

        $view = 'plugins/marketplace::themes.shortcodes.subscription-plans';
        $themeView = Theme::getThemeNamespace('partials.shortcodes.marketplace-subscription-plans.index');

        if (view()->exists($themeView)) {
            $view = $themeView;
        }

        return view($view, compact('plans', 'shortcode'))->render();
    }
}
