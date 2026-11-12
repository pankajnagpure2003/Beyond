<div class="container py-5">
    <div class="text-center mb-4">
        <h1 class="h2 mb-2">{{ $title }}</h1>
        <p class="text-muted mb-0">{{ trans('plugins/marketplace::subscription.public.description') }}</p>
    </div>

    <div class="row g-4 justify-content-center">
        @forelse ($plans as $plan)
            @include('plugins/marketplace::themes.partials.subscription-plan-card', ['plan' => $plan])
        @empty
            <div class="col-12 text-center text-muted">
                {{ trans('plugins/marketplace::subscription.public.no_plans') }}
            </div>
        @endforelse
    </div>
</div>
