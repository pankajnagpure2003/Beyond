@php
    $popupTitle = theme_option('cookie_consent_title', trans('plugins/cookie-consent::cookie-consent.title'));
    $popupMessage = theme_option('cookie_consent_message', trans('plugins/cookie-consent::cookie-consent.message'));
    $learnMoreUrl = theme_option('cookie_consent_learn_more_url');
    $learnMoreText = theme_option('cookie_consent_learn_more_text');
    $maxWidth = (int) theme_option('cookie_consent_max_width', 1170) ?: 1170;
    $hasCategories = ! empty($cookieConsentConfig['cookie_categories']);
    $showCustomize = $hasCategories && theme_option('cookie_consent_show_customize_button', 'no') == 'yes';
    $primaryColor = theme_option('primary_color', '#f97316');
    $primaryColorHover = theme_option('primary_color_hover', '#d66313');
    $accentTextColor = \Botble\CookieConsent\Supports\ConsentColor::accentTextColorFor($primaryColor);
@endphp

<style>
    /*
     * The wrapper spans the whole viewport and dims the page behind it, so the
     * panel reads as a dialog rather than as another page element. It is not
     * dismissible by clicking the backdrop - the visitor must make a choice.
     */
    .site-notice {
        position: fixed;
        inset: 0;
        z-index: 99999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        background-color: rgba(24, 24, 27, 0.5);
    }

    .site-notice.site-notice--visible {
        display: flex;
    }

    @keyframes site-notice-pop {
        from {
            opacity: 0;
            transform: translateY(12px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .site-notice .site-notice-body {
        width: 100%;
        /* Capped well below the shared max-width default (1170) - a centered
           popup reads as a dialog, not a full-bleed bar. Lowering the theme
           option still narrows it further. */
        max-width: min({{ $maxWidth }}px, 640px);
        max-height: 90vh;
        overflow-y: auto;
        padding: 24px 32px;
        background-color: #ffffff;
        border: 1px solid rgba(228, 228, 231, 0.9);
        border-radius: 4px;
        box-shadow: 0 20px 25px -5px rgba(24, 24, 27, 0.1), 0 8px 10px -6px rgba(24, 24, 27, 0.1);
        animation: site-notice-pop 0.35s ease;
    }

    .site-notice .site-notice__visually-hidden {
        position: absolute;
        width: 1px;
        height: 1px;
        margin: -1px;
        padding: 0;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    .site-notice .site-notice__top {
        display: flex;
        justify-content: flex-end;
        margin-bottom: 16px;
    }

    /* dir is set both on <html> by RTL themes and on the banner itself, so match either. */
    [dir="rtl"] .site-notice .site-notice__top,
    .site-notice[dir="rtl"] .site-notice__top {
        justify-content: flex-start;
    }

    .site-notice .site-notice__link {
        padding: 0;
        margin: 0;
        border: none;
        background: transparent;
        color: #18181b;
        font-size: 12px;
        font-weight: 500;
        line-height: 1.4;
        letter-spacing: 0.02em;
        text-transform: uppercase;
        text-decoration: underline;
        cursor: pointer;
        transition: color 0.2s ease;
    }

    .site-notice .site-notice__link:hover {
        color: var(--cc-primary, #f97316);
    }

    .site-notice .site-notice__message {
        margin: 0;
        font-size: 14px;
        line-height: 1.6;
        color: #52525b;
    }

    .site-notice .site-notice__message a {
        color: var(--cc-primary, #f97316);
        text-decoration: underline;
    }

    .site-notice .site-notice__message a:hover {
        text-decoration: none;
    }

    .site-notice .site-notice__actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 12px;
        margin-top: 20px;
    }

    [dir="rtl"] .site-notice .site-notice__actions,
    .site-notice[dir="rtl"] .site-notice__actions {
        justify-content: flex-start;
    }

    .site-notice .site-notice__actions button {
        padding: 12px 16px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 11px;
        font-weight: 600;
        line-height: 1.2;
        letter-spacing: 0.04em;
        text-align: center;
        text-transform: uppercase;
        transition: all 0.2s ease;
    }

    .site-notice .site-notice__accept-all {
        background-color: var(--cc-primary, #f97316);
        color: var(--cc-accent-text, #ffffff);
        border: 1px solid var(--cc-primary, #f97316);
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
    }

    .site-notice .site-notice__accept-all:hover {
        background-color: var(--cc-primary-hover, #d66313);
        border-color: var(--cc-primary-hover, #d66313);
    }

    .site-notice .site-notice__secondary {
        background-color: #ffffff;
        color: #3f3f46;
        border: 1px solid #e4e4e7;
    }

    .site-notice .site-notice__secondary:hover {
        background-color: #fafafa;
        border-color: #d4d4d8;
    }

    /* Used by the shared preferences modal partial. */
    .site-notice .site-notice__close {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        padding: 0;
        margin: 0;
        border: none;
        background: transparent;
        color: #a1a1aa;
        cursor: pointer;
        border-radius: 8px;
        transition: color 0.2s ease, background-color 0.2s ease;
    }

    .site-notice .site-notice__close:hover {
        color: #3f3f46;
        background-color: #f4f4f5;
    }

    .site-notice .site-notice__close svg {
        width: 16px;
        height: 16px;
    }

    @media (max-width: 767px) {
        .site-notice .site-notice-body {
            padding: 20px;
        }

        .site-notice .site-notice__message {
            font-size: 13px;
        }

        .site-notice .site-notice__actions {
            flex-direction: column;
            gap: 8px;
        }

        .site-notice .site-notice__actions button {
            width: 100%;
        }
    }
</style>

<div
    class="js-site-notice site-notice"
    dir="{{ BaseHelper::siteLanguageDirection() }}"
    role="dialog"
    aria-labelledby="js-site-notice-title"
    aria-live="polite"
    style="--cc-primary: {{ $primaryColor }}; --cc-primary-hover: {{ $primaryColorHover }}; --cc-accent-text: {{ $accentTextColor }};"
    data-nosnippet
>
    <div class="site-notice-body">
        {{-- This style shows no visible heading, but the dialog still needs an accessible name. --}}
        <p class="site-notice__visually-hidden" id="js-site-notice-title">{{ $popupTitle }}</p>

        <div class="site-notice__top">
            <button type="button" class="js-site-notice-reject site-notice__link">
                {{ trans('plugins/cookie-consent::cookie-consent.continue_without_accepting_text') }}
            </button>
        </div>

        <p class="site-notice__message">
            {!! BaseHelper::clean($popupMessage) !!}
            @if ($learnMoreUrl && $learnMoreText)
                <a href="{{ Str::startsWith($learnMoreUrl, ['http://', 'https://']) ? $learnMoreUrl : BaseHelper::getHomepageUrl() . '/' . ltrim($learnMoreUrl, '/') }}">{{ $learnMoreText }}</a>
            @endif
        </p>

        <div class="site-notice__actions">
            @if ($showCustomize)
                <button type="button" class="js-cookie-consent-customize site-notice__secondary">
                    {{ trans('plugins/cookie-consent::cookie-consent.customize_text') }}
                </button>
            @endif
            <button type="button" class="js-site-notice-essential site-notice__secondary">
                {{ trans('plugins/cookie-consent::cookie-consent.essential_only_text') }}
            </button>
            <button type="button" class="js-site-notice-accept-all site-notice__accept-all">
                {{ trans('plugins/cookie-consent::cookie-consent.accept_all_text') }}
            </button>
        </div>
    </div>

    @if ($hasCategories)
        @include('plugins/cookie-consent::partials.preferences-modal')
    @endif
</div>

@include('plugins/cookie-consent::partials.scripts')
