<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0"
    >
    <meta
        http-equiv="X-UA-Compatible"
        content="ie=edge"
    >
    <title>{{ trans('core/setting::setting.preview') }}</title>

    @php
        $faviconUrl = AdminHelper::getAdminFaviconUrl();
        $faviconType = rescue(fn() => RvMedia::getMimeType(AdminHelper::getAdminFavicon()), 'image/x-icon', false);
    @endphp
    <link
        href="{{ $faviconUrl }}"
        rel="icon shortcut"
        type="{{ $faviconType }}"
    >
    <meta
        property="og:image"
        content="{{ $faviconUrl }}"
    >

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f5f7fa;
            color: #374151;
            line-height: 1.6;
        }

        .container {
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        .preview-section {
            flex: 1;
            background-color: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            position: relative;
        }

        .preview-wrapper {
            width: 100%;
            max-width: 800px;
            height: 100%;
            max-height: 900px;
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            position: relative;
        }

        .device-header {
            height: 40px;
            background-color: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            padding: 0 1rem;
            gap: 0.5rem;
        }

        .device-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background-color: #d1d5db;
        }

        .device-dot:first-child {
            background-color: #ef4444;
        }

        .device-dot:nth-child(2) {
            background-color: #f59e0b;
        }

        .device-dot:nth-child(3) {
            background-color: #10b981;
        }

        .iframe-container {
            height: calc(100% - 40px);
            width: 100%;
            overflow: hidden;
        }

        .iframe-container iframe {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }

        .controls-section {
            width: 400px;
            background-color: #ffffff;
            border-left: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .icon {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }

        .controls-header {
            padding: 1.25rem 1.5rem 1.25rem;
            border-bottom: 1px solid #f3f4f6;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            font-size: 0.8125rem;
            font-weight: 500;
            color: #6b7280;
            text-decoration: none;
            margin-bottom: 1rem;
            transition: color 0.15s ease-in-out;
        }

        .back-link:hover,
        .back-link:focus-visible {
            color: #111827;
        }

        .controls-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.375rem;
        }

        .controls-title h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #111827;
        }

        .controls-header p {
            font-size: 0.8125rem;
            color: #6b7280;
            line-height: 1.5;
        }

        .preview-status {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.125rem 0.625rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 500;
            white-space: nowrap;
            color: #047857;
            background-color: #ecfdf5;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background-color: currentColor;
        }

        .preview-status.is-updating {
            color: #b45309;
            background-color: #fffbeb;
        }

        .preview-status.is-updating .status-dot {
            animation: status-pulse 1s ease-in-out infinite;
        }

        @keyframes status-pulse {
            50% {
                opacity: 0.3;
            }
        }

        .controls-body {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            padding: 1.25rem 1.5rem;
        }

        .form-group + .form-group {
            margin-top: 1rem;
        }

        .form-label-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.5rem;
            margin-bottom: 0.375rem;
        }

        .form-label {
            font-size: 0.8125rem;
            font-weight: 600;
            color: #374151;
        }

        .variable-name {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.6875rem;
            color: #9ca3af;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .variable-name::before {
            /* Two opening braces + space, as CSS escapes so Blade does not parse them as an echo */
            content: '\007B\007B\0020';
        }

        .variable-name::after {
            content: '\0020\007D\007D';
        }

        .form-control {
            display: block;
            width: 100%;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            line-height: 1.5;
            color: #111827;
            background-color: #ffffff;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .form-control:hover {
            border-color: #9ca3af;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .empty-state {
            padding: 1.5rem 1rem;
            text-align: center;
            font-size: 0.875rem;
            color: #6b7280;
            background-color: #f9fafb;
            border: 1px dashed #d1d5db;
            border-radius: 0.5rem;
        }

        .controls-footer {
            display: flex;
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            border-top: 1px solid #e5e7eb;
            background-color: #ffffff;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            font-family: inherit;
            font-weight: 500;
            font-size: 0.875rem;
            line-height: 1.25rem;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            transition: background-color 0.15s ease-in-out, border-color 0.15s ease-in-out, color 0.15s ease-in-out;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            white-space: nowrap;
        }

        .btn:focus-visible {
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-primary {
            flex: 1;
            background-color: #3b82f6;
            color: #ffffff;
        }

        .btn-primary:hover {
            background-color: #2563eb;
        }

        .btn-primary.is-copied {
            background-color: #059669;
        }

        .btn-light {
            background-color: #ffffff;
            border-color: #d1d5db;
            color: #374151;
        }

        .btn-light:hover:not(:disabled) {
            background-color: #f9fafb;
            border-color: #9ca3af;
        }

        @media (max-width: 1024px) {
            .controls-section {
                width: 340px;
            }
        }

        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }

            .preview-section {
                height: 55vh;
                padding: 1rem;
            }

            .controls-section {
                width: 100%;
                height: 45vh;
                border-left: none;
                border-top: 1px solid #e5e7eb;
            }

            .device-header {
                display: none;
            }

            .iframe-container {
                height: 100%;
            }

            .preview-wrapper {
                border-radius: 8px;
            }

            .controls-header p {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="preview-section">
            <div class="preview-wrapper">
                <div class="device-header">
                    <div class="device-dot"></div>
                    <div class="device-dot"></div>
                    <div class="device-dot"></div>
                </div>
                <div class="iframe-container">
                    <iframe
                        id="preview-iframe"
                        src="{{ $iframeUrl . ($inputData ? (str_contains($iframeUrl, '?') ? '&' : '?') . http_build_query($inputData) : '') }}"
                        width="100%"
                        height="100%"
                    ></iframe>
                </div>
            </div>
        </div>
        <div class="controls-section">
            <div class="controls-header">
                <a
                    class="back-link"
                    href="{{ $backUrl }}"
                >
                    <x-core::icon name="ti ti-arrow-left" />
                    {{ trans('core/setting::setting.back') }}
                </a>
                <div class="controls-title">
                    <h2>{{ trans('core/setting::setting.preview') }}</h2>
                    <span
                        class="preview-status"
                        id="preview-status"
                        role="status"
                        data-live="{{ trans('core/setting::setting.preview_live') }}"
                        data-updating="{{ trans('core/setting::setting.preview_updating') }}"
                    >
                        <span class="status-dot"></span>
                        <span class="status-text">{{ trans('core/setting::setting.preview_live') }}</span>
                    </span>
                </div>
                @if ($variables)
                    <p>{{ trans('core/setting::setting.preview_live_hint') }}</p>
                @endif
            </div>
            <form
                class="controls-body"
                id="preview-form"
                method="GET"
                autocomplete="off"
            >
                @forelse ($variables as $key => $variable)
                    <div class="form-group">
                        <div class="form-label-row">
                            <label
                                class="form-label"
                                for="txt-{{ $key }}"
                            >{{ trans($variable) }}</label>
                            <code
                                class="variable-name"
                                title="{{ $key }}"
                            >{{ $key }}</code>
                        </div>
                        <input
                            class="form-control preview-input"
                            id="txt-{{ $key }}"
                            name="{{ $key }}"
                            type="text"
                            value="{{ Arr::get($inputData, $key) }}"
                        >
                    </div>
                @empty
                    <p class="empty-state">{{ trans('core/setting::setting.preview_no_variables') }}</p>
                @endforelse
            </form>
            <div class="controls-footer">
                @if ($variables)
                    <button
                        class="btn btn-light"
                        id="preview-clear"
                        type="button"
                    >
                        <x-core::icon name="ti ti-eraser" />
                        {{ trans('core/setting::setting.preview_clear_values') }}
                    </button>
                @endif
                <button
                    class="btn btn-primary"
                    id="preview-copy-link"
                    type="button"
                    data-copied="{{ trans('core/setting::setting.preview_link_copied') }}"
                >
                    <x-core::icon name="ti ti-link" />
                    <span class="btn-text">{{ trans('core/setting::setting.preview_copy_link') }}</span>
                </button>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const iframe = document.getElementById('preview-iframe');
            const form = document.getElementById('preview-form');
            const inputs = document.querySelectorAll('.preview-input');
            const status = document.getElementById('preview-status');
            const statusText = status.querySelector('.status-text');
            const copyButton = document.getElementById('preview-copy-link');
            const clearButton = document.getElementById('preview-clear');
            const baseIframeUrl = @json($iframeUrl);
            let reloadTimeout = null;
            let copiedTimeout = null;

            function setUpdating(updating) {
                status.classList.toggle('is-updating', updating);
                statusText.textContent = updating ? status.dataset.updating : status.dataset.live;
            }

            // Apply the sample values to a URL: set filled ones, drop empty ones, keep everything else (e.g. ref_lang)
            function withSampleValues(url) {
                inputs.forEach(function(input) {
                    if (input.value.trim() !== '') {
                        url.searchParams.set(input.name, input.value);
                    } else {
                        url.searchParams.delete(input.name);
                    }
                });

                return url;
            }

            // Keep the sample values in the page URL, so it can be shared or refreshed without losing them
            function syncPageUrl() {
                const pageUrl = withSampleValues(new URL(window.location.href));

                if (pageUrl.toString() !== window.location.href) {
                    window.history.replaceState(window.history.state, '', pageUrl.toString());
                }
            }

            function reloadPreview(delay) {
                if (reloadTimeout) {
                    clearTimeout(reloadTimeout);
                }

                reloadTimeout = setTimeout(function() {
                    reloadTimeout = null;

                    // Base URL already carries ?ref_lang=..., so merge params instead of appending a second "?"
                    const iframeUrl = withSampleValues(new URL(baseIframeUrl, window.location.href)).toString();

                    if (iframe.src !== iframeUrl) {
                        iframe.src = iframeUrl;
                    } else {
                        setUpdating(false);
                    }
                }, delay);
            }

            function onValuesChanged(delay) {
                setUpdating(true);
                syncPageUrl();
                reloadPreview(delay);
            }

            // navigator.clipboard is only available on HTTPS/localhost - fall back to execCommand elsewhere
            function copyText(text) {
                if (navigator.clipboard && window.isSecureContext) {
                    return navigator.clipboard.writeText(text);
                }

                return new Promise(function(resolve, reject) {
                    const textarea = document.createElement('textarea');
                    textarea.value = text;
                    textarea.setAttribute('readonly', '');
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    textarea.select();

                    const copied = document.execCommand('copy');
                    document.body.removeChild(textarea);

                    copied ? resolve() : reject(new Error('Copy failed'));
                });
            }

            // Back to "live" once the latest preview has loaded (not while the user is still typing)
            iframe.addEventListener('load', function() {
                if (! reloadTimeout) {
                    setUpdating(false);
                }
            });

            inputs.forEach(function(input) {
                input.addEventListener('input', function() {
                    onValuesChanged(500);
                });
            });

            // Enter in a field refreshes right away instead of submitting the form
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                onValuesChanged(0);
            });

            if (clearButton) {
                clearButton.addEventListener('click', function() {
                    inputs.forEach(function(input) {
                        input.value = '';
                    });

                    onValuesChanged(0);
                    inputs[0].focus();
                });
            }

            copyButton.addEventListener('click', function() {
                const buttonText = copyButton.querySelector('.btn-text');

                copyText(window.location.href).then(function() {
                    if (! copyButton.dataset.label) {
                        copyButton.dataset.label = buttonText.textContent;
                    }

                    buttonText.textContent = copyButton.dataset.copied;
                    copyButton.classList.add('is-copied');

                    clearTimeout(copiedTimeout);
                    copiedTimeout = setTimeout(function() {
                        buttonText.textContent = copyButton.dataset.label;
                        copyButton.classList.remove('is-copied');
                    }, 2000);
                }).catch(function() {
                    // Nothing else to do - the link is still available in the address bar
                });
            });
        })();
    </script>
</body>

</html>
