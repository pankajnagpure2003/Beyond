<?php

namespace Botble\SalePopup\Support;

use Botble\Setting\Facades\Setting;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class SalePopupHelper
{
    /**
     * Controller methods behind the theme's slug routes. Product detail lives on
     * `getViewWithPrefix` (`/products/{slug}`), which is registered without a name,
     * so the action has to be matched instead of the route name.
     */
    public const SLUG_ROUTE_ACTIONS = ['getView', 'getViewWithPrefix'];

    protected bool $isProductDetailPage = false;

    public function getSettingKeyPrefix()
    {
        return apply_filters('sale_popup_setting_key_prefix', 'sale_popup');
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return setting(self::getSettingKey($key), $default);
    }

    public function getSettingKey(string $key)
    {
        return apply_filters(
            'sale_popup_setting_key',
            "{$this->getSettingKeyPrefix()}_$key"
        );
    }

    public function saveSettings(array $settings): void
    {
        foreach ($settings as $settingKey => $settingValue) {
            $settingValue = is_array($settingValue) ? json_encode($settingValue) : $settingValue;

            Setting::set($this->getSettingKey($settingKey), $settingValue);
        }

        Setting::save();
    }

    public function settingKeys(): array
    {
        return [
            'enabled',
            'collection_id',
            'purchased_text',
            'verified_text',
            'quick_view_text',
            'list_users_purchased',
            'show_time_ago_suggest',
            'list_sale_time',
            'limit_products',
            'show_verified',
            'show_close_button',
            'show_quick_view_button',
            'show_on_mobile',
            'hide_duration_after_closed',
            'display_pages',
        ];
    }

    public function displayPages(): array
    {
        return [
            'public.index' => trans('plugins/sale-popup::sale-popup.display_pages.homepage'),
            'public.product' => trans('plugins/sale-popup::sale-popup.display_pages.product_detail'),
            'public.products' => trans('plugins/sale-popup::sale-popup.display_pages.product_listing'),
            'public.cart' => trans('plugins/sale-popup::sale-popup.display_pages.cart'),
        ];
    }

    /**
     * Product detail pages are rendered through the catch-all `public.single` route,
     * so they cannot be identified by route name alone. The flag is set while the
     * single view is being resolved (see HookServiceProvider).
     */
    public function markCurrentPageAsProductDetail(): void
    {
        $this->isProductDetailPage = true;
    }

    public function isSlugRoute(): bool
    {
        $action = Route::currentRouteAction();

        if (! is_string($action) || ! str_contains($action, '@')) {
            return false;
        }

        return in_array(Str::afterLast($action, '@'), self::SLUG_ROUTE_ACTIONS, true);
    }

    public function isProductDetailPage(): bool
    {
        return $this->isProductDetailPage;
    }

    public function resetCurrentPageState(): void
    {
        $this->isProductDetailPage = false;
    }

    public function shouldDisplayOnCurrentPage(): bool
    {
        $displayPages = setting("{$this->getSettingKeyPrefix()}_display_pages", '["public.index"]');

        if (is_string($displayPages)) {
            $displayPages = json_decode($displayPages, true);
        }

        if (! is_array($displayPages) || ! $displayPages) {
            return false;
        }

        if (in_array(Route::currentRouteName(), $displayPages, true)) {
            return true;
        }

        return in_array('public.product', $displayPages, true) && $this->isProductDetailPage();
    }
}
