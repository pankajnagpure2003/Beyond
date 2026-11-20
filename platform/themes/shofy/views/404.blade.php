@php
    SeoHelper::setTitle(__('Page not found') . ' - ' . Theme::getSiteTitle());
    Theme::fireEventGlobalAssets();

    $showSearch = theme_option('404_page_search_enabled', true);

    Theme::set('pageTitle', __('404 Error'));
    Theme::breadcrumb()->add(__('Home'), route('public.index'))->add(__('404 Error'), url('/404'));
@endphp

<div class="row justify-content-center">
    <div class="col-xl-7 col-lg-8 col-md-10">
        <div class="tp-error-hero text-center">
            <div class="tp-error-thumb" style="margin-bottom: 20px;">
                <img src="{{ theme_option('404_page_image') ? RvMedia::getImageUrl(theme_option('404_page_image')) : Theme::asset()->url('images/404.png') }}" alt="{{ Theme::getSiteTitle() }}" style="max-height: 220px; width: auto;">
            </div>

            <h3 style="font-size: 32px; font-weight: 700; margin-bottom: 12px;">{{ theme_option('404_page_title') ?: __('Oops! Page not found') }}</h3>
            <p style="color: #55585b; font-size: 16px; margin-bottom: 30px;">{{ theme_option('404_page_description') ?: __("The page you were looking for doesn't exist or has been moved.") }}</p>

            @if ($showSearch && is_plugin_active('ecommerce'))
                <div style="max-width: 480px; margin: 0 auto 30px;">
                    <x-plugins-ecommerce::fronts.ajax-search>
                        <div class="tp-search-input tp-error-search-box">
                            <x-plugins-ecommerce::fronts.ajax-search.input />
                            <button type="submit" aria-label="{{ __('Search') }}"><x-core::icon name="ti ti-search" /></button>
                        </div>
                    </x-plugins-ecommerce::fronts.ajax-search>
                </div>
            @endif

            <div class="d-flex justify-content-center gap-3 flex-wrap">
                <a href="{{ BaseHelper::getHomepageUrl() }}" class="tp-error-btn">{{ __('Back to Home') }}</a>
                @if (is_plugin_active('ecommerce'))
                    <a href="{{ route('public.products') }}" class="tp-error-btn tp-error-btn-outline">{{ __('Browse Products') }}</a>
                @endif
            </div>
        </div>
    </div>
</div>

<style>
    .tp-error-btn {
        font-size: 14px;
        padding: 9px 24px;
        border-radius: 4px;
        background-color: var(--primary-color);
        color: #fff;
        transition: all 0.3s;
    }
    .tp-error-btn:hover {
        background-color: var(--tp-common-black);
        color: #fff;
    }
    .tp-error-btn-outline {
        background-color: transparent !important;
        border: 1.5px solid var(--tp-common-black) !important;
        color: var(--tp-common-black) !important;
    }
    .tp-error-btn-outline:hover {
        background-color: var(--tp-common-black) !important;
        color: #fff !important;
    }
    .tp-error-search-box {
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        border-radius: 8px;
    }
</style>
