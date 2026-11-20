@php
    $imageField = $imageField ?? ($shortcode->image_type === 'icon_image' ? 'icon_image' : 'image');
@endphp

<section {!! $shortcode->htmlAttributes() !!} class="tp-product-category pt-60 pb-15"
     @if ($shortcode->background_color)
         style="background-color: {{ $shortcode->background_color }} !important;"
     @endif
>
    <div class="container">
        {!! Theme::partial('section-title', compact('shortcode')) !!}
        <div class="tp-product-categories-slider swiper-container"
             data-items="{{ (int) $shortcode->items_per_view ?: 5 }}"
             {!! Theme\Shofy\Helpers\SliderAutoplayHelper::getDataAttributes($shortcode) !!}>
            <div class="swiper-wrapper">
                @foreach ($categories as $category)
                    <div class="swiper-slide">
                        <div class="tp-product-category-item text-center mb-40">
                            <div class="tp-product-category-thumb fix">
                                <a href="{{ $category->url }}" title="{{ $category->name }}">
                                    @php
                                        $isHomeCategorySlider = Theme::get('isHomePage');
                                        $categoryImageOverrides = [
                                            'electronics' => 'main/product-categories/electronics-home.png',
                                            'watches' => 'main/product-categories/watches-home.png',
                                            'belts' => 'main/product-categories/belts-home.png',
                                            'perfumes' => 'main/product-categories/perfumes-home.png',
                                            'lifestyle-products' => 'main/product-categories/lifestyle-products-home.png',
                                        ];
                                        $categoryImage = $isHomeCategorySlider
                                            ? ($categoryImageOverrides[$category->slug] ?? $category->{$imageField})
                                            : $category->{$imageField};
                                        $imageAttributes = $isHomeCategorySlider && in_array($category->slug, ['home-appliances', 'electronics', 'watches', 'belts', 'perfumes', 'lifestyle-products'], true)
                                            ? ['style' => 'max-width: 100%; max-height: 100%;']
                                            : [];
                                    @endphp
                                    {{ RvMedia::image($categoryImage, $category->name, attributes: $imageAttributes) }}
                                </a>
                            </div>
                            <div class="tp-product-category-content">
                                <h3 class="tp-product-category-title">
                                    <a href="{{ $category->url }}" title="{{ $category->name }}">{{ $category->name }}</a>
                                </h3>
                                @if ($shortcode->show_products_count)
                                    <p>
                                        @if ($category->count_all_products === 1)
                                            {{ __('1 product') }}
                                        @else
                                            {{ __(':count products', ['count' => number_format($category->count_all_products)]) }}
                                        @endif
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>
