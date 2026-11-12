@once
    <style>
        .shortcode-lazy-loading {
            position: relative;
            min-height: 12rem;
        }

        .loading-spinner {
            align-items: center;
            background: hsla(0, 0%, 100%, 0.5);
            display: flex;
            height: 100%;
            inset-inline-start: 0;
            justify-content: center;
            position: absolute;
            top: 0;
            width: 100%;
            z-index: 1;

            &:after {
                animation: loading-spinner-rotation 0.5s linear infinite;
                border-color: var(--primary-color) transparent var(--primary-color) transparent;
                border-radius: 50%;
                border-style: solid;
                border-width: 1px;
                content: ' ';
                display: block;
                height: 40px;
                position: absolute;
                top: calc(50% - 20px);
                width: 40px;
                z-index: 1;
            }
        }

        @keyframes loading-spinner-rotation {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        {{--
            Shared skeleton shimmer, used by every skeleton view a theme supplies through
            $loadingView. It sits inside the once-block above, so a page with several lazy
            blocks ships it a single time - each theme skeleton partial used to carry its
            own copy. Keep prose out of CSS comments here: Blade compiles a bare @-word
            even inside /* ... */, so writing the directive name literally would open a
            second conditional and break the view.
        --}}
        @keyframes skeleton-loading {
            0% {
                background-position: -200% 0;
            }

            100% {
                background-position: 200% 0;
            }
        }

        .skeleton-loading-bg {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: skeleton-loading 1.5s infinite;
        }
    </style>
@endonce

<div
    class="shortcode-lazy-loading"
    data-name="{{ $name }}"
    data-attributes="{{ json_encode($attributes) }}"
    @if(!empty($shortcodeId)) data-shortcode-id="{{ $shortcodeId }}" data-shortcode-name="{{ $name }}" @endif
>
    @if (!empty($loadingView) && view()->exists($loadingView))
        {!! view($loadingView)->render() !!}
    @else
        <div class="loading-spinner"></div>
    @endif
</div>
