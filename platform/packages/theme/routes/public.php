<?php

use Botble\Slug\Facades\SlugHelper;
use Botble\Theme\Events\ThemeRoutingAfterEvent;
use Botble\Theme\Events\ThemeRoutingBeforeEvent;
use Botble\Theme\Facades\SiteMapManager;
use Botble\Theme\Facades\Theme;
use Botble\Theme\Http\Controllers\PublicController;
use Illuminate\Support\Facades\Route;

Theme::registerRoutes(function (): void {
    Route::group(['controller' => PublicController::class], function (): void {
        event(new ThemeRoutingBeforeEvent(app()->make('router')));

        Route::get('/', 'getIndex')->name('public.index');

        // Dynamic llms.txt fallback (served only when public/llms.txt is absent).
        // Registered before the sitemap/catch-all routes so it always matches first.
        // Can be turned off via Admin -> Settings -> Sitemap -> Enable llms.txt.
        // Registered unconditionally, and before the sitemap routes below: the sitemap
        // catch-all `{key}.{extension}` accepts the `txt` extension, so a conditionally
        // registered llms route would otherwise fall through to it and answer 200 with an
        // empty sitemap body - which reads to a crawler as "this site has no content".
        // Whether these endpoints are enabled is decided in the controller, which 404s.
        // Registering them unconditionally also means toggling the setting takes effect
        // without `artisan route:clear`.
        Route::get('llms.txt', 'getLlmsTxt')->name('public.llms-txt');

        // Full-content variant, opt-in: it republishes complete article bodies.
        Route::get('llms-full.txt', 'getLlmsFullTxt')->name('public.llms-full-txt');

        // Dynamic robots.txt carrying the AI crawler policy (served only when
        // public/robots.txt is absent, same as llms.txt above).
        Route::get('robots.txt', 'getRobotsTxt')->name('public.robots-txt');

        if (setting('sitemap_enabled', true)) {
            Route::get('sitemap.xml', 'getSiteMap')->name('public.sitemap');

            Route::get('{key}.{extension}', 'getSiteMapIndex')
                ->whereIn('extension', SiteMapManager::allowedExtensions())
                ->name('public.sitemap.index');
        }

        Route::get('{slug?}', 'getView')->name('public.single');

        Route::get('{prefix}/{slug?}', 'getViewWithPrefix')
            ->whereIn('prefix', SlugHelper::getAllPrefixes() ?: ['1437bcd2-d94e-4a5fd-9a39-b5d60225e9af']);

        event(new ThemeRoutingAfterEvent(app()->make('router')));
    });
});
