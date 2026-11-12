@php
    Theme::layout('full-width');
@endphp

<section class="tp-order-area pb-160 @if (! theme_option('theme_breadcrumb_enabled', true)) pt-100 @else pt-60 @endif">
    @include('plugins/ecommerce::themes.order-tracking')
</section>
