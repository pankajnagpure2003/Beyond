<div class="container py-5 mp-plan-shortcode">
    @if ($shortcode->title || $shortcode->description)
        <div class="text-center mb-4">
            @if ($shortcode->title)
                <h2 class="h3 mb-2">{{ $shortcode->title }}</h2>
            @endif
            @if ($shortcode->description)
                <p class="text-muted mb-0">{{ $shortcode->description }}</p>
            @endif
        </div>
    @endif

    <div class="row g-4 justify-content-center">
        @foreach ($plans as $plan)
            @include('plugins/marketplace::themes.partials.subscription-plan-card', ['plan' => $plan])
        @endforeach
    </div>
</div>
