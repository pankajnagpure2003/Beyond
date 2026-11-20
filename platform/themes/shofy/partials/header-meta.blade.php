<style>
    :root {
        --primary-color: {{ $primaryColor = theme_option('primary_color', '#0989ff') }};
        --secondary-color: {{ $secondaryColor = theme_option('secondary_color', '#821f40') }};
        --primary-color-rgb: {{ implode(',', BaseHelper::hexToRgb($primaryColor)) }};
        --tp-theme-secondary: {{ $secondaryColor }};
        --footer-background-color: {{ theme_option('footer_background_color', '#fff') }};
        --footer-text-color: {{ theme_option('footer_text_color', '#010f1c') }};
        --footer-title-color: {{ theme_option('footer_title_color', '#010f1c') }};
        --footer-link-color: {{ theme_option('footer_link_color', '#010f1c') }};
        --footer-link-hover-color: {{ theme_option('footer_link_hover_color', '#0989ff') }};
        --footer-border-color: {{ theme_option('footer_border_color', '#e5e6e8') }};
        --header-menu-text-hover-color: {{ theme_option('header_menu_text_hover_color', '#0989ff') }};
        --header-main-text-hover-color: {{ theme_option('header_main_text_hover_color', '#0989ff') }};
        --header-sticky-background-color: {{ theme_option('header_sticky_background_color', '#fff') }};
        --header-sticky-text-color: {{ theme_option('header_sticky_text_color', '#010f1c') }};
        --header-sticky-text-hover-color: {{ theme_option('header_sticky_text_hover_color', '#0989ff') }};
    }

    /* Comprehensive Product Details Page Styling */
    .breadcrumb__style-2 {
        background-color: #f8fafc !important;
        border-bottom: 1px solid #e2e8f0;
        padding: 14px 0 !important;
    }
    .breadcrumb__style-2 .breadcrumb__list a {
        color: #64748b;
        font-size: 13.5px;
        transition: color 0.2s ease;
    }
    .breadcrumb__style-2 .breadcrumb__list a:hover {
        color: var(--primary-color);
    }
    .breadcrumb__style-2 .breadcrumb__list span:last-child {
        color: #0f172a;
        font-weight: 600;
        font-size: 13.5px;
    }
    .tp-product-details-top {
        padding-top: 30px;
        margin-bottom: 50px;
    }
    .tp-product-details-thumb-wrapper {
        background: #ffffff;
        border-radius: 12px;
    }
    .bb-product-gallery-thumbnails img {
        border-radius: 6px;
        transition: all 0.2s ease;
    }
    .bb-product-gallery-thumbnails .slick-slide.slick-current img {
        border-color: var(--primary-color) !important;
        box-shadow: 0 0 0 2px var(--primary-color);
    }
    .tp-product-details-category {
        margin-bottom: 8px;
    }
    .tp-product-details-category a {
        font-size: 13px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
        transition: color 0.2s ease;
    }
    .tp-product-details-category a:hover {
        color: var(--primary-color);
    }
    .tp-product-details-title {
        font-size: 28px !important;
        line-height: 1.35 !important;
        word-break: break-word;
        font-weight: 700 !important;
        color: #0f172a !important;
        margin-bottom: 12px;
    }
    .tp-product-details-inventory {
        gap: 15px;
        margin-bottom: 16px !important;
    }
    .tp-product-details-price-wrapper {
        display: flex;
        align-items: baseline;
        gap: 12px;
        margin-bottom: 16px;
    }
    .tp-product-details-price.new-price {
        font-size: 30px !important;
        font-weight: 700 !important;
        color: var(--primary-color) !important;
        letter-spacing: -0.5px;
    }
    .tp-product-details-price.old-price {
        font-size: 18px !important;
        font-weight: 400;
        color: #94a3b8 !important;
        text-decoration: line-through;
    }
    .tp-product-details-description {
        font-size: 15px;
        line-height: 1.65;
        color: #475569;
        margin-bottom: 24px;
    }
    .tp-product-details-action-item-wrapper {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 12px;
    }
    .tp-product-details-action-title {
        font-size: 14px;
        font-weight: 600;
        color: #334155;
        margin-bottom: 8px;
    }
    .tp-product-details-quantity .tp-product-quantity {
        display: inline-flex !important;
        align-items: center !important;
        height: 48px;
        min-width: 130px;
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 8px !important;
        background-color: #f8fafc !important;
        margin-right: 0 !important;
        margin-bottom: 0 !important;
        padding: 0 8px;
        box-sizing: border-box;
    }
    .tp-product-quantity .tp-cart-input {
        width: 50px !important;
        height: 100% !important;
        border: none !important;
        background: transparent !important;
        text-align: center;
        font-weight: 700;
        font-size: 16px;
        color: #0f172a;
    }
    .tp-product-quantity .tp-cart-minus,
    .tp-product-quantity .tp-cart-plus {
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 6px;
        color: #334155;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .tp-product-quantity .tp-cart-minus:hover,
    .tp-product-quantity .tp-cart-plus:hover {
        background-color: #e2e8f0;
        color: #0f172a;
    }
    .tp-product-details-add-to-cart {
        margin-bottom: 0 !important;
    }
    .tp-product-details-add-to-cart-btn {
        height: 48px !important;
        line-height: 48px !important;
        padding: 0 28px !important;
        border-radius: 8px !important;
        font-weight: 600 !important;
        font-size: 15px !important;
        letter-spacing: 0.3px;
        background-color: #0f172a !important;
        color: #ffffff !important;
        border: 1.5px solid #0f172a !important;
        box-shadow: 0 2px 6px rgba(15, 23, 42, 0.12);
        transition: all 0.25s ease !important;
    }
    .tp-product-details-add-to-cart-btn:hover {
        background-color: var(--primary-color) !important;
        border-color: var(--primary-color) !important;
        color: #ffffff !important;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(9, 137, 255, 0.25);
    }
    .tp-product-details-buy-now-btn {
        height: 48px !important;
        line-height: 48px !important;
        padding: 0 28px !important;
        border-radius: 8px !important;
        font-weight: 600 !important;
        font-size: 15px !important;
        letter-spacing: 0.3px;
        background-color: var(--primary-color) !important;
        color: #ffffff !important;
        border: 1.5px solid var(--primary-color) !important;
        box-shadow: 0 4px 14px rgba(9, 137, 255, 0.25);
        transition: all 0.25s ease !important;
        margin-top: 10px;
    }
    .tp-product-details-buy-now-btn:hover {
        filter: brightness(0.92);
        color: #ffffff !important;
        transform: translateY(-1px);
        box-shadow: 0 6px 18px rgba(9, 137, 255, 0.35);
    }
    .tp-product-details-action-sm {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-top: 20px;
        padding-top: 15px;
        border-top: 1px solid #f1f5f9;
    }
    .tp-product-details-action-sm-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        font-weight: 500;
        color: #64748b;
        background: none;
        border: none;
        cursor: pointer;
        transition: color 0.2s ease;
    }
    .tp-product-details-action-sm-btn:hover {
        color: var(--primary-color);
    }
    .tp-product-details-query {
        margin-top: 18px;
        padding-top: 15px;
        border-top: 1px solid #f1f5f9;
        font-size: 14px;
    }
    .tp-product-details-query-item {
        margin-bottom: 8px;
        color: #64748b;
    }
    .tp-product-details-query-item span:first-child {
        font-weight: 600;
        color: #1e293b;
        min-width: 80px;
        display: inline-block;
    }
    .tp-product-details-query-item a {
        color: var(--primary-color);
        font-weight: 500;
        text-decoration: none;
    }
    .tp-product-details-query-item a:hover {
        text-decoration: underline;
    }
    .tp-product-details-tab-nav {
        margin-top: 40px;
    }
    .tp-product-details-tab-nav .nav-tabs {
        border-bottom: 2px solid #e2e8f0;
        column-gap: 35px;
    }
    .tp-product-details-tab-nav .nav-tabs .nav-link {
        font-size: 18px;
        font-weight: 600;
        color: #64748b;
        padding: 14px 4px;
        border: none !important;
        background: transparent !important;
        position: relative;
    }
    .tp-product-details-tab-nav .nav-tabs .nav-link.active {
        color: var(--primary-color) !important;
        font-weight: 700;
    }
    .tp-product-details-tab-nav .nav-tabs .nav-link.active:after {
        content: "";
        position: absolute;
        bottom: -2px;
        left: 0;
        right: 0;
        height: 3px;
        background-color: var(--primary-color);
        border-radius: 3px;
    }
    .tp-product-details-additional-info table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        overflow: hidden;
    }
    .tp-product-details-additional-info table th,
    .tp-product-details-additional-info table td {
        padding: 12px 18px;
        border-bottom: 1px solid #e2e8f0;
        font-size: 14px;
    }
    .tp-product-details-additional-info table tr:last-child th,
    .tp-product-details-additional-info table tr:last-child td {
        border-bottom: none;
    }
    .tp-product-details-additional-info table th {
        background-color: #f8fafc;
        font-weight: 600;
        color: #1e293b;
        width: 30%;
    }
    .tp-product-details-additional-info table td {
        color: #475569;
    }
    .tp-product-details-sticky-actions {
        box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.08);
        background: rgba(255, 255, 255, 0.98);
        backdrop-filter: blur(8px);
        padding: 12px 0;
    }

    /* Categories Grid Page Text Positioning */
    .tp-category-main-content {
        bottom: 6px !important;
        left: 15px !important;
        right: 15px !important;
        padding: 0 10px 4px 10px;
    }
    .tp-category-main-title {
        font-size: 21px;
        line-height: 1.25 !important;
        margin-bottom: 3px !important;
    }
    .tp-category-main-title a {
        color: #0f172a;
        transition: color 0.2s ease;
    }
    .tp-category-main-title a:hover {
        color: var(--primary-color);
    }
    .tp-category-main-item {
        display: inline-block;
        font-size: 13px;
        color: #64748b;
        font-weight: 500;
        margin-bottom: 0 !important;
    }
    .tp-category-main-thumb img {
        max-width: 80% !important;
        max-height: 70% !important;
        transform: translateY(-16px);
    }

    /* Category & Shop Banner Typography - Beyond Box Logo Theme Blue */
    .category-banner .breadcrumb__title {
        color: #012d84 !important;
        font-weight: 800 !important;
        text-shadow: 0 1px 2px rgba(255, 255, 255, 0.8) !important;
        letter-spacing: -0.5px;
    }
    .category-banner .breadcrumb__list {
        color: #0141aa !important;
        font-weight: 600 !important;
        text-shadow: 0 1px 2px rgba(255, 255, 255, 0.8) !important;
    }
    .category-banner .breadcrumb__list a {
        color: #0141aa !important;
        font-weight: 600 !important;
        transition: color 0.2s ease;
    }
    .category-banner .breadcrumb__list a:hover {
        color: #0989ff !important;
    }
    .category-banner .breadcrumb__list span {
        color: #0141aa !important;
    }
    .category-banner .breadcrumb__list span:last-child,
    .category-banner .breadcrumb__list span span {
        color: #0989ff !important;
        font-weight: 700 !important;
    }
</style>
