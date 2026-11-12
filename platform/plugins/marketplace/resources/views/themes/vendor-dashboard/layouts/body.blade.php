@php
    $customer = auth('customer')->user();
@endphp

<style>
    /* The profile actions are icon-only to keep the sidebar header compact. The theme
       styles .ps-block__right children as block-level links, which collapses the inline
       SVGs onto each other, so size and space them explicitly here. */
    .vendor-profile-actions {
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .vendor-profile-actions > a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.25rem;
        height: 2.25rem;
        border: 1px solid var(--bs-border-color, #dee2e6);
        border-radius: var(--bs-border-radius, .375rem);
        color: var(--bs-secondary-color, #6c757d);
        background: transparent;
        transition: background-color .15s ease, color .15s ease, border-color .15s ease;
        /* Visual box stays 36px; the hit area is padded out to the 44px minimum. */
        position: relative;
    }

    .vendor-profile-actions > a::after {
        content: '';
        position: absolute;
        inset: -4px;
    }

    .vendor-profile-actions > a:hover,
    .vendor-profile-actions > a:focus-visible {
        background-color: var(--bs-secondary-bg, #e9ecef);
        color: var(--bs-body-color, #212529);
    }

    .vendor-profile-actions > a.text-danger:hover,
    .vendor-profile-actions > a.text-danger:focus-visible {
        background-color: var(--bs-danger-bg-subtle, #f8d7da);
        border-color: var(--bs-danger-border-subtle, #f1aeb5);
        color: var(--bs-danger, #dc3545);
    }

    .vendor-profile-actions svg {
        width: 1.125rem;
        height: 1.125rem;
    }
</style>

<header class="header--mobile">
    <div class="header__left">
        <button class="ps-drawer-toggle">
            <x-core::icon name="ti ti-menu-2" />
        </button>
    </div>
    <div class="header__center">
        <a
            class="ps-logo"
            href="{{ route('marketplace.vendor.dashboard') }}"
        >
            @if ($logo = theme_option('logo_vendor_dashboard', Theme::getLogo()))
                <img
                    src="{{ RvMedia::getImageUrl($logo) }}"
                    alt="{{ Theme::getSiteTitle() }}"
                >
            @endif
        </a>
    </div>
    <div class="header__right d-flex align-items-center gap-2">
        <a class="header__site-link" href="{{ route('customer.overview') }}" title="{{ $mobileDashboard = trans('plugins/marketplace::marketplace.go_to_customer_dashboard') }}" aria-label="{{ $mobileDashboard }}">
            <x-core::icon name="ti ti-user" />
        </a>
        <a class="header__site-link" href="{{ route('customer.logout') }}" title="{{ $mobileLogout = trans('core/base::layouts.logout') }}" aria-label="{{ $mobileLogout }}">
            <x-core::icon name="ti ti-logout" />
        </a>
    </div>
</header>
<aside class="ps-drawer--mobile">
    <div class="ps-drawer__header">
        <h4 class="fs-3 mb-0">Menu</h4>
        <button class="ps-drawer__close">
            <x-core::icon name="ti ti-x" />
        </button>
    </div>
    <div class="ps-drawer__content">
        @include(MarketplaceHelper::viewPath('vendor-dashboard.layouts.menu'))

        <div class="ps-drawer__footer mt-4 pt-3 border-top">
            <ul class="menu">
                <li>
                    <a href="{{ route('customer.overview') }}">
                        <x-core::icon name="ti ti-user" />
                        {{ trans('plugins/marketplace::marketplace.go_to_customer_dashboard') }}
                    </a>
                </li>
                <li>
                    <a href="{{ BaseHelper::getHomepageUrl() }}">
                        <x-core::icon name="ti ti-home" />
                        {{ trans('plugins/marketplace::marketplace.go_to_homepage') }}
                    </a>
                </li>
            </ul>
        </div>
    </div>
</aside>
<div class="ps-site-overlay"></div>
<main class="ps-main">
    <div class="ps-main__sidebar">
        <div class="ps-sidebar">
            <div class="ps-sidebar__top">
                <div class="ps-block--user-wellcome">
                    <div class="ps-block__left">
                        <img
                            src="{{ $customer->store->logo_url }}"
                            alt="{{ $customer->store->name }}"
                            class="avatar avatar-lg"
                        />
                    </div>
                    <div class="ps-block__right">
                        <p>{{ trans('plugins/marketplace::marketplace.hello') }}, {{ $customer->name }}</p>
                        <small>{{ trans('plugins/marketplace::marketplace.joined_on_date', ['date' => $customer->created_at->translatedFormat('M d, Y')]) }}</small>

                        {{-- Icon-only to keep the sidebar header compact. Each carries a
                             title (tooltip) and an aria-label, since an icon alone gives
                             screen readers nothing to announce. --}}
                        <div class="vendor-profile-actions mt-3">
                            @if ($customer?->store)
                                <a
                                    href="{{ $customer->store->url }}"
                                    target="_blank"
                                    rel="noopener"
                                    title="{{ $viewStore = trans('plugins/marketplace::marketplace.view_your_store') }}"
                                    aria-label="{{ $viewStore }}"
                                >
                                    <x-core::icon name="ti ti-building-store" />
                                </a>
                            @endif

                            <a
                                href="{{ route('customer.overview') }}"
                                title="{{ $customerDashboard = trans('plugins/marketplace::marketplace.go_to_customer_dashboard') }}"
                                aria-label="{{ $customerDashboard }}"
                            >
                                <x-core::icon name="ti ti-user" />
                            </a>

                            {{-- Logout is destructive-ish, so it is separated and coloured
                                 apart from the navigational actions. --}}
                            <a
                                href="{{ route('customer.logout') }}"
                                class="text-danger ms-auto"
                                title="{{ $logout = trans('core/base::layouts.logout') }}"
                                aria-label="{{ $logout }}"
                            >
                                <x-core::icon name="ti ti-logout" />
                            </a>
                        </div>
                    </div>
                </div>
                <div class="ps-block--earning-count">
                    <small>{{ trans('plugins/marketplace::marketplace.balance') }}</small>
                    <h3 class="mt-1">{{ format_price($customer->balance) }}</h3>
                </div>
            </div>
            <div class="ps-sidebar__content">
                <div class="ps-sidebar__center">
                    @include(MarketplaceHelper::viewPath('vendor-dashboard.layouts.menu'))
                    {!! apply_filters('marketplace_vendor_sidebar_menu_items', '') !!}
                </div>
                <div class="ps-sidebar__footer">
                    <div class="ps-copyright">
                        @if ($logo)
                            <a href="{{ BaseHelper::getHomepageUrl() }}" title="{{ $siteTitle = Theme::getSiteTitle() }}">
                                <img
                                    src="{{ RvMedia::getImageUrl($logo) }}"
                                    alt="{{ $siteTitle }}"
                                >
                            </a>
                        @endif
                        <p>{!! BaseHelper::clean(str_replace('%Y', Carbon\Carbon::now()->year, theme_option('copyright'))) !!}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div
        class="ps-main__wrapper"
        id="vendor-dashboard"
    >
        <header class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="fs-1 mb-0 text-truncate me-3">{{ page_title()->getTitle(false) }}</h3>
            <div class="d-flex align-items-center gap-4">
                @if (is_plugin_active('language'))
                    {!! apply_filters('marketplace_vendor_dashboard_language_switcher', view(MarketplaceHelper::viewPath('vendor-dashboard.partials.language-switcher'))->render()) !!}
                @endif

                <div class="d-none d-md-flex align-items-center gap-3">
                    <a href="{{ route('customer.overview') }}" class="text-uppercase">
                        <x-core::icon name="ti ti-user" />
                        <span>{{ trans('plugins/marketplace::marketplace.go_to_customer_dashboard') }}</span>
                    </a>
                    <span class="text-muted">|</span>
                    <a href="{{ BaseHelper::getHomepageUrl() }}" target="_blank" class="text-uppercase">
                        <span>{{ trans('plugins/marketplace::marketplace.go_to_homepage') }}</span>
                        <x-core::icon name="ti ti-arrow-right" />
                    </a>
                </div>
            </div>
        </header>

        <div id="app">
            @yield('content')
        </div>
    </div>
</main>
