<?php

namespace Botble\SalePopup\Providers;

use Botble\Base\Supports\ServiceProvider;
use Botble\Language\Facades\Language;
use Botble\SalePopup\Support\SalePopupHelper;
use Botble\Setting\Facades\Setting;
use Botble\Theme\Facades\Theme;
use Illuminate\Routing\Events\RouteMatched;

class HookServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The same action is also fired for the "write a review" page (`public.product.review`),
        // so the slug route has to be checked as well - otherwise selecting "Product detail"
        // would leak the popup onto pages that are not the product detail page.
        add_action(BASE_ACTION_PUBLIC_RENDER_SINGLE, function (string $screen): void {
            if (! defined('PRODUCT_MODULE_SCREEN_NAME') || $screen !== PRODUCT_MODULE_SCREEN_NAME) {
                return;
            }

            $salePopupHelper = app(SalePopupHelper::class);

            if (! $salePopupHelper->isSlugRoute()) {
                return;
            }

            $salePopupHelper->markCurrentPageAsProductDetail();
        }, 55, 1);

        $this->app['events']->listen(RouteMatched::class, function (): void {
            // Long-running workers (Octane) reuse the container between requests.
            app(SalePopupHelper::class)->resetCurrentPageState();

            if (defined('THEME_FRONT_FOOTER')) {
                Theme::asset()
                    ->container('footer')
                    ->usePath(false)
                    ->add(
                        'sale-popup-js',
                        asset('vendor/core/plugins/sale-popup/js/sale-popup.js'),
                        ['jquery'],
                        [],
                        '1.2.2'
                    );

                add_filter(
                    THEME_FRONT_FOOTER,
                    function (?string $html) {
                        if (! setting('sale_popup_enabled', 1)) {
                            return $html;
                        }

                        if (! app(SalePopupHelper::class)->shouldDisplayOnCurrentPage()) {
                            return $html;
                        }

                        return $html . view('plugins/sale-popup::front', [
                            'show_on_mobile' => setting('sale_popup_show_on_mobile', false),
                            'hide_duration_after_closed' => (int) setting('sale_popup_hide_duration_after_closed', 24),
                        ])->render();
                    },
                    1457
                );
            }
        });

        add_filter('sale_popup_setting_key', function (string $key): string {
            if (! is_plugin_active('language') || ! is_plugin_active('language-advanced')) {
                return $key;
            }

            $currentLocale = is_in_admin(true) ? Language::getCurrentAdminLocale() : Language::getCurrentLocale();
            $locale = $currentLocale !== Language::getDefaultLocale() ? $currentLocale : null;

            if ($locale && in_array($locale, array_keys(Language::getSupportedLocales()))) {
                $key = "$key-$locale";

                return Setting::has("$key-$locale") ? "$key-$locale" : $key;
            }

            return $key;
        }, 55);
    }
}
