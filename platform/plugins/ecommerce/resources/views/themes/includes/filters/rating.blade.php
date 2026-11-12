@php
    if (! EcommerceHelper::isReviewEnabled()) {
        return;
    }

    $selectedRating = (int) request()->query('rating');
@endphp

<div class="bb-product-filter">
    <h4 class="bb-product-filter-title">{{ trans('plugins/ecommerce::products.rating') }}</h4>

    <div class="bb-product-filter-content">
        <ul class="bb-product-filter-items filter-checkbox">
            @for ($value = 5; $value >= 1; $value--)
                <li class="bb-product-filter-item">
                    <input id="filter-rating-{{ $value }}" type="radio" name="rating" value="{{ $value }}" data-action="apply-filter" @checked($selectedRating === $value) />
                    <label for="filter-rating-{{ $value }}">
                        @for ($star = 1; $star <= 5; $star++)
                            @if ($star <= $value)
                                <x-core::icon name="ti ti-star-filled" />
                            @else
                                <x-core::icon name="ti ti-star" />
                            @endif
                        @endfor
                        <span>{{ $value > 1 ? trans('plugins/ecommerce::products.stars_and_up', ['count' => $value]) : trans('plugins/ecommerce::products.star_and_up', ['count' => $value]) }}</span>
                    </label>
                </li>
            @endfor
        </ul>
    </div>
</div>
