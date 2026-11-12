<div class="tp-product-badge-3">
    @if ($product->isOutOfStock())
        <span class="product-out-stock">{{ __('Out Of Stock') }}</span>
    @else
        @if ($product->productLabels->isNotEmpty())
            @foreach ($product->productLabels as $label)
                <span {!! $label->css_styles !!}>{{ $label->name }}</span>
            @endforeach
        @else
            {{-- isOnSale() covers the product's own sale price, flash sales and
                promotions, and leaves out contextual cross-sale/up-sale prices. --}}
            @if ($product->isOnSale())
                <span class="product-sale">{{ get_sale_percentage($product->price, $product->front_sale_price) }}</span>
            @endif
        @endif
    @endif
</div>
