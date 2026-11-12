{!! SeoHelper::render() !!}

@include(MarketplaceHelper::viewPath('vendor-dashboard.layouts.header-meta'))

<link
    href="{{ asset('vendor/core/plugins/marketplace/fonts/linearicons/linearicons.css') }}?v={{ MarketplaceHelper::getAssetVersion() }}"
    rel="stylesheet"
>
<link
    href="{{ asset('vendor/core/plugins/marketplace/css/marketplace.css') }}?v={{ MarketplaceHelper::getAssetVersion() }}"
    rel="stylesheet"
>

@if (session('locale_direction', 'ltr') == 'rtl')
    <link href="{{ asset('vendor/core/core/base/css/core.rtl.css') }}" rel="stylesheet">

    <link
        href="{{ asset('vendor/core/plugins/marketplace/css/marketplace-rtl.css') }}?v={{ MarketplaceHelper::getAssetVersion() }}"
        rel="stylesheet"
    >
@endif

@if (File::exists($styleIntegration = Theme::getStyleIntegrationPath()))
    {{-- TENANCY PATCH (platform/packages/tenancy): link the SAME filename getStyleIntegrationPath() writes (suffixed per store under tenancy); basename() is the stock name for a single-tenant install. --}}
    {!! Html::style(Theme::asset()->url('css/' . basename($styleIntegration) . '?v=' . filectime($styleIntegration))) !!}
@endif
