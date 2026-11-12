@php
    $style = in_array($shortcode->style, ['grid', 'compact-grid', 'slider', 'list', 'table']) ? $shortcode->style : 'grid';
    $imageField = $imageField ?? ($shortcode->image_type === 'icon_image' ? 'icon_image' : 'image');
@endphp

{!! Theme::partial("shortcodes.ecommerce-categories.$style", compact('shortcode', 'categories', 'imageField')) !!}
