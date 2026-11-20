@php
    $breadcrumbStyle = Theme::get('breadcrumbStyle', theme_option('breadcrumb_style', 'align-start'));
    $currentRoute = request()->route();
    $categorySlug = $currentRoute && $currentRoute->parameter('prefix') === 'product-categories'
        ? $currentRoute->parameter('slug')
        : null;
    $hasCategoryBanner = (bool) ($currentRoute
        && $currentRoute->parameter('prefix') === 'product-categories'
        && $currentRoute->parameter('slug'));
    $hasPageBanner = request()->is('categories', 'brands', 'products', 'coupons', 'cart', 'compare', 'wishlist', 'orders/tracking', 'blog', 'blog/*', 'contact', 'contact/*', 'stores');
    $isPagesDropdown = request()->is('faqs', 'faq', 'login', 'register', 'password/reset', 'password/email', 'password/*', '404', 'coming-soon', 'coming-soon/*');
    $hasImageBanner = $hasCategoryBanner || $hasPageBanner || $isPagesDropdown;
    $isCategoryPage = $hasCategoryBanner;
    $categoryBannerImage = in_array($categorySlug, ['electronics', 'new-arrivals'], true)
        ? 'main/product-categories/electronics-new-arrivals-banner.png'
        : 'main/product-categories/all-category-banner.png';
    $breadcrumbImage = $hasCategoryBanner
        ? $categoryBannerImage
        : Theme::get('breadcrumbBackground', theme_option('breadcrumb_background_image'));
    $breadcrumbBackground = ($hasPageBanner || $isPagesDropdown)
        ? Theme::asset()->url('images/shop-category-banner.png')
        : RvMedia::getImageUrl($breadcrumbImage);
    $breadcrumbBackgroundColor = theme_option('breadcrumb_background_color');
    $breadcrumbHeight = Theme::get('breadcrumbHeight') ?: theme_option('breadcrumb_height');
    if ($isPagesDropdown) {
        $breadcrumbHeight = '220px';
    } else {
        $breadcrumbHeight = is_numeric($breadcrumbHeight) ? "{$breadcrumbHeight}px" : $breadcrumbHeight;
    }
    $breadcrumbClasses = Theme::get('breadcrumbClasses');
    if ($hasImageBanner && $breadcrumbStyle === 'align-center') {
        $breadcrumbStyle = 'align-start';
    }
@endphp

@if (theme_option('theme_breadcrumb_enabled', true) && $breadcrumbStyle !== 'none')
    <section
        @class([
            'breadcrumb__area include-bg',
            'category-banner' => $isCategoryPage,
            'pages-banner' => $isPagesDropdown,
            'pt-60 pb-60 mb-50' => ! $hasImageBanner && $breadcrumbStyle !== 'without-title' && empty($breadcrumbHeight),
            'pt-25 pb-25 mb-25' => ! $hasImageBanner && $breadcrumbStyle === 'without-title' && empty($breadcrumbHeight),
            'breadcrumb__style-2 include-bg' => $breadcrumbStyle === 'without-title',
            'mb-30 text-center' => $breadcrumbStyle === 'align-center',
            'mb-30 text-start' => $breadcrumbStyle === 'align-start',
            'breadcrumb__padding' => $breadcrumbStyle === 'full-width',
            'pt-30' => ! $hasImageBanner && $breadcrumbStyle !== 'without-title' && (empty($breadcrumbHeight) || $breadcrumbHeight < 90),
            $breadcrumbClasses ?? null,
        ])
        @style([
            "background-image: url($breadcrumbBackground)" => $breadcrumbBackground && ! $hasImageBanner && $breadcrumbStyle !== 'without-title',
            "background-color: $breadcrumbBackgroundColor" => $breadcrumbBackgroundColor && ! $hasImageBanner && $breadcrumbStyle !== 'without-title',
            "background-color: " . ($isCategoryPage ? "#dbeafe" : "#041a4a") . "; position: relative; overflow: hidden; box-sizing: border-box; display: flex; align-items: center; height: " . ($breadcrumbHeight ?: '250px') . "; padding-top: 0; padding-bottom: 0" => $hasImageBanner,
            "display: flex; align-items: center; height: $breadcrumbHeight" => $breadcrumbHeight && ! $hasImageBanner
        ])
    >
        @if ($hasImageBanner)
            <img src="{{ $breadcrumbBackground }}" alt="" class="category-banner-image" style="position: absolute; inset: 0; width: 100%; height: {{ $breadcrumbHeight ?: '250px' }}; object-fit: cover; object-position: center; z-index: 0;">
        @endif

        <div @class(['container' => $breadcrumbStyle !== 'full-width', 'container-fluid' => $breadcrumbStyle === 'full-width'])>
            <div class="breadcrumb__content p-relative z-index-1">
                @if (theme_option('breadcrumb_hide_title', 'no') == 'no' && $breadcrumbStyle !== 'without-title')
                    <h1 class="breadcrumb__title" @style([
                        'color: #012d84; font-weight: 800; text-shadow: 0 1px 2px rgba(255,255,255,0.8); letter-spacing: -0.5px;' => $isCategoryPage,
                        'color: #fff; text-shadow: 0 2px 4px rgba(0,0,0,0.5); font-weight: 700;' => $hasImageBanner && ! $isCategoryPage
                    ])>{!! BaseHelper::clean(Theme::get('pageTitle') ? Theme::get('pageTitle') : SeoHelper::getTitleOnly()) !!}</h1>
                @endif
                <div @class(['breadcrumb__list', 'js_breadcrumb_reduce_length_on_mobile' => theme_option('breadcrumb_reduce_length_on_mobile', 'yes') == 'yes',]) @style([
                    'color: #0141aa; text-shadow: 0 1px 2px rgba(255,255,255,0.8); font-weight: 600;' => $isCategoryPage,
                    'color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,0.5);' => $hasImageBanner && ! $isCategoryPage
                ])>
                    @foreach(Theme::breadcrumb()->getCrumbs() as $crumb)
                        <span>
                            @if($loop->last)
                                <span @style([
                                    'color: #0989ff; font-weight: 700;' => $isCategoryPage,
                                    'color: #fff; font-weight: 700;' => request()->is('categories', 'categories/*'),
                                    'color: #ffd200;' => $hasImageBanner && ! $isCategoryPage && ! request()->is('categories', 'categories/*')
                                ])>{!! BaseHelper::clean($crumb['label']) !!}</span>
                            @else
                                <a class="d-inline-block" href="{{ $crumb['url'] }}" @style([
                                    'color: #0141aa; font-weight: 600;' => $isCategoryPage,
                                    'color: #fff;' => $hasImageBanner && ! $isCategoryPage
                                ])>{!! BaseHelper::clean($crumb['label']) !!}</a>
                            @endif
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
@endif
