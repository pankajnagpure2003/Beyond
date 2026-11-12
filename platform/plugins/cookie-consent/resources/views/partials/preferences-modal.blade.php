{{--
    Per-category preferences modal, shared by the "card" and "popup" styles.

    Must be included INSIDE the .js-site-notice root element: it inherits the
    --cc-primary custom property from there, and relies on the shared engine
    hiding the whole banner (and therefore this modal) once a choice is made.

    The host style is expected to define: @keyframes site-notice-pop,
    .site-notice__close, .site-notice__accept-all and .site-notice__secondary.
--}}
<style>
    .cookie-consent-modal {
        position: fixed;
        inset: 0;
        z-index: 100000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        background-color: rgba(24, 24, 27, 0.5);
    }

    .cookie-consent-modal.is-open {
        display: flex;
    }

    .cookie-consent-modal__dialog {
        width: 100%;
        max-width: 440px;
        max-height: 85vh;
        display: flex;
        flex-direction: column;
        background-color: #ffffff;
        border-radius: 16px;
        box-shadow: 0 25px 50px -12px rgba(24, 24, 27, 0.35);
        overflow: hidden;
        animation: site-notice-pop 0.3s ease;
    }

    .cookie-consent-modal__header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 16px 20px;
        border-bottom: 1px solid #f4f4f5;
    }

    .cookie-consent-modal__title {
        margin: 0;
        font-size: 16px;
        font-weight: 600;
        color: #18181b;
    }

    .cookie-consent-modal__body {
        padding: 16px 20px;
        overflow-y: auto;
    }

    .cookie-consent-modal__intro {
        margin: 0 0 16px;
        font-size: 13px;
        line-height: 1.6;
        color: #52525b;
    }

    .cookie-consent-modal__item {
        display: block;
        padding: 12px 0;
        border-top: 1px solid #f4f4f5;
    }

    .cookie-consent-modal__item-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 4px;
    }

    .cookie-consent-modal__item-name {
        font-size: 14px;
        font-weight: 600;
        color: #18181b;
    }

    .cookie-consent-modal__item-desc {
        margin: 0;
        font-size: 12px;
        line-height: 1.55;
        color: #71717a;
    }

    .cookie-consent-modal__badge {
        flex: 0 0 auto;
        font-size: 11px;
        font-weight: 600;
        color: var(--cc-primary, #f97316);
    }

    .cookie-consent-modal__switch {
        position: relative;
        display: inline-block;
        flex: 0 0 auto;
        width: 40px;
        height: 22px;
        cursor: pointer;
    }

    .cookie-consent-modal__switch input {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
    }

    .cookie-consent-modal__slider {
        position: absolute;
        inset: 0;
        background-color: #d4d4d8;
        border-radius: 999px;
        transition: background-color 0.2s ease;
    }

    .cookie-consent-modal__slider::before {
        content: '';
        position: absolute;
        top: 3px;
        left: 3px;
        width: 16px;
        height: 16px;
        background-color: #ffffff;
        border-radius: 50%;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        transition: transform 0.2s ease;
    }

    .cookie-consent-modal__switch input:checked + .cookie-consent-modal__slider {
        background-color: var(--cc-primary, #f97316);
    }

    .cookie-consent-modal__switch input:checked + .cookie-consent-modal__slider::before {
        transform: translateX(18px);
    }

    .cookie-consent-modal__footer {
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding: 16px 20px;
        border-top: 1px solid #f4f4f5;
    }

    .cookie-consent-modal__footer button {
        width: 100%;
        padding: 8px 14px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 12px;
        line-height: 1.2;
        text-align: center;
        transition: all 0.2s ease;
    }
</style>

{{-- Category toggles live here. Visible inside the modal; also power accept-all / essential-only. --}}
<div class="js-cookie-consent-modal cookie-consent-modal" role="dialog" aria-modal="true" aria-labelledby="js-cookie-consent-modal-title">
    <div class="cookie-consent-modal__dialog">
        <div class="cookie-consent-modal__header">
            <p class="cookie-consent-modal__title" id="js-cookie-consent-modal-title">{{ trans('plugins/cookie-consent::cookie-consent.customize_text') }}</p>
            <button
                type="button"
                class="js-cookie-consent-modal-close site-notice__close"
                aria-label="{{ trans('core/base::base.close') }}"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 6 6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="cookie-consent-modal__body">
            <p class="cookie-consent-modal__intro">{!! BaseHelper::clean(theme_option('cookie_consent_message', trans('plugins/cookie-consent::cookie-consent.message'))) !!}</p>

            @foreach ($cookieConsentConfig['cookie_categories'] as $key => $category)
                <label class="cookie-consent-modal__item">
                    <span class="cookie-consent-modal__item-head">
                        <span class="cookie-consent-modal__item-name">
                            {{ trans('plugins/cookie-consent::cookie-consent.cookie_categories.' . $key . '.name') }}
                        </span>
                        @if ($category['required'])
                            <span class="cookie-consent-modal__badge">
                                {{ trans('plugins/cookie-consent::cookie-consent.always_active') }}
                            </span>
                            <input type="checkbox" class="js-cookie-category" value="{{ $key }}" checked disabled hidden>
                        @else
                            <span class="cookie-consent-modal__switch">
                                <input type="checkbox" class="js-cookie-category" value="{{ $key }}">
                                <span class="cookie-consent-modal__slider"></span>
                            </span>
                        @endif
                    </span>
                    <span class="cookie-consent-modal__item-desc">
                        {{ trans('plugins/cookie-consent::cookie-consent.cookie_categories.' . $key . '.description') }}
                    </span>
                </label>
            @endforeach
        </div>

        <div class="cookie-consent-modal__footer">
            <button type="button" class="js-site-notice-accept-all site-notice__accept-all">
                {{ trans('plugins/cookie-consent::cookie-consent.accept_all_text') }}
            </button>
            <button type="button" class="js-site-notice-save site-notice__secondary">
                {{ trans('plugins/cookie-consent::cookie-consent.save_text') }}
            </button>
        </div>
    </div>
</div>

<script>
    (function() {
        const banner = document.querySelector('.js-site-notice');
        const modal = banner ? banner.querySelector('.js-cookie-consent-modal') : null;

        if (!banner || !modal) {
            return;
        }

        banner.addEventListener('click', function(event) {
            if (event.target.closest('.js-cookie-consent-customize')) {
                modal.classList.add('is-open');
            } else if (event.target.closest('.js-cookie-consent-modal-close') || event.target === modal) {
                modal.classList.remove('is-open');
            }
        });
    })();
</script>
