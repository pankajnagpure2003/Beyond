@php
    $cardTitle = theme_option('cookie_consent_title', trans('plugins/cookie-consent::cookie-consent.title'));
    $cardMessage = theme_option('cookie_consent_message', trans('plugins/cookie-consent::cookie-consent.message'));
    $learnMoreUrl = theme_option('cookie_consent_learn_more_url');
    $learnMoreText = theme_option('cookie_consent_learn_more_text');
    $hasCategories = ! empty($cookieConsentConfig['cookie_categories']);
    $primaryColor = theme_option('primary_color', '#f97316');
    $primaryColorHover = theme_option('primary_color_hover', '#d66313');
    $accentTextColor = \Botble\CookieConsent\Supports\ConsentColor::accentTextColorFor($primaryColor);
@endphp

<style>
    .site-notice {
        position: fixed;
        right: 1rem;
        bottom: 1rem;
        left: 1rem;
        z-index: 99999;
        display: none;
    }

    @media (min-width: 640px) {
        .site-notice {
            left: auto;
            width: 22rem;
        }
    }

    [dir="rtl"] .site-notice {
        right: 1rem;
        left: 1rem;
    }

    @media (min-width: 640px) {
        [dir="rtl"] .site-notice {
            right: auto;
            left: 1rem;
            width: 22rem;
        }
    }

    .site-notice.site-notice--visible {
        display: block;
        animation: site-notice-pop 0.35s ease;
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
        overflow: hidden;
        background-color: #ffffff;
        border: 1px solid rgba(228, 228, 231, 0.9);
        border-radius: 16px;
        box-shadow: 0 20px 25px -5px rgba(24, 24, 27, 0.1), 0 8px 10px -6px rgba(24, 24, 27, 0.1);
    }

    .site-notice .site-notice__header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 14px 16px;
        border-bottom: 1px solid #f4f4f5;
        background: linear-gradient(to bottom right, #fafafa, #ffffff);
    }

    .site-notice .site-notice__header-left {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }

    .site-notice .site-notice__icon {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border-radius: 12px;
        background-color: color-mix(in srgb, var(--cc-primary, #f97316) 12%, #ffffff);
        color: var(--cc-primary, #f97316);
    }

    .site-notice .site-notice__icon svg {
        width: 20px;
        height: 20px;
    }

    .site-notice .site-notice__title {
        margin: 0;
        font-size: 14px;
        font-weight: 600;
        line-height: 1.375;
        color: #18181b;
    }

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

    .site-notice .site-notice__content {
        padding: 16px;
    }

    .site-notice .site-notice__message {
        margin: 0;
        font-size: 13px;
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
        margin-top: 16px;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .site-notice .site-notice__actions button {
        width: 100%;
        padding: 8px 14px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 12px;
        line-height: 1.2;
        text-align: center;
        transition: all 0.2s ease;
    }

    .site-notice .site-notice__accept-all {
        background-color: var(--cc-primary, #f97316);
        color: var(--cc-accent-text, #ffffff);
        border: 1px solid var(--cc-primary, #f97316);
        font-weight: 600;
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
        font-weight: 500;
    }

    .site-notice .site-notice__secondary:hover {
        background-color: #fafafa;
        border-color: #d4d4d8;
    }

    @media (max-width: 639px) {
        .site-notice {
            right: 1rem;
            left: 1rem;
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
        <div class="site-notice__header">
            <div class="site-notice__header-left">
                <span class="site-notice__icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                    </svg>
                </span>
                <p class="site-notice__title" id="js-site-notice-title">{{ $cardTitle }}</p>
            </div>
            <button
                type="button"
                class="js-site-notice-essential site-notice__close"
                aria-label="{{ trans('plugins/cookie-consent::cookie-consent.essential_only_text') }}"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 6 6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="site-notice__content">
            <p class="site-notice__message">
                {!! BaseHelper::clean($cardMessage) !!}
                @if ($learnMoreUrl && $learnMoreText)
                    <a href="{{ Str::startsWith($learnMoreUrl, ['http://', 'https://']) ? $learnMoreUrl : BaseHelper::getHomepageUrl() . '/' . ltrim($learnMoreUrl, '/') }}">{{ $learnMoreText }}</a>
                @endif
            </p>

            <div class="site-notice__actions">
                <button type="button" class="js-site-notice-accept-all site-notice__accept-all">
                    {{ trans('plugins/cookie-consent::cookie-consent.accept_all_text') }}
                </button>
                @if ($hasCategories)
                    <button type="button" class="js-cookie-consent-customize site-notice__secondary">
                        {{ trans('plugins/cookie-consent::cookie-consent.customize_text') }}
                    </button>
                @endif
                <button type="button" class="js-site-notice-essential site-notice__secondary">
                    {{ trans('plugins/cookie-consent::cookie-consent.essential_only_text') }}
                </button>
            </div>
        </div>
    </div>

    @if ($hasCategories)
        @include('plugins/cookie-consent::partials.preferences-modal')
    @endif
</div>

@include('plugins/cookie-consent::partials.scripts')
