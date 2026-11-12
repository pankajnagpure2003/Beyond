@php
    use Botble\Marketplace\Enums\SubscriptionDurationUnitEnum;
    use Botble\Marketplace\Facades\MarketplaceHelper;

    // Same included/excluded checklist pattern as the vendor dashboard plans view, minus
    // the Tabler-only ribbon/display-5/text-green utilities a theme may not define.
    $features = ['allow_digital_products', 'allow_coupons', 'allow_product_import'];
    $limit = $plan->getOption('product_limit');
    $cta = MarketplaceHelper::subscriptionPlanCta($plan);
@endphp

<div class="col-12 col-md-6 col-lg-4 d-flex">
    <div class="card mp-plan-card w-100">
        <div class="card-body mp-plan-card__body">
            <div class="text-uppercase text-muted small fw-bold">{{ $plan->name }}</div>

            <div class="mp-plan-card__price">
                {{ $plan->isFree()
                    ? trans('plugins/marketplace::subscription.subscriptions.free')
                    : format_price($plan->price) }}
            </div>

            <div class="text-muted small">
                @if ($plan->isLifetime())
                    {{ trans('plugins/marketplace::subscription.duration_units.lifetime') }}
                @else
                    {{ $plan->duration_value }} {{ SubscriptionDurationUnitEnum::getLabel($plan->duration_unit) }}
                @endif
            </div>

            @if ($plan->description)
                <p class="text-muted small mt-3 mb-0">{{ $plan->description }}</p>
            @endif

            <ul class="mp-plan-card__features">
                <li>
                    @if ($limit < 0)
                        <strong>{{ trans('plugins/marketplace::subscription.subscriptions.unlimited_products') }}</strong>
                    @else
                        <strong>{{ $limit }}</strong> {{ Str::lower(trans('plugins/ecommerce::products.name')) }}
                    @endif
                </li>

                @foreach ($features as $feature)
                    @php($enabled = $plan->getOption($feature) > 0)
                    <li class="{{ $enabled ? '' : 'text-muted' }}">
                        <x-core::icon :name="$enabled ? 'ti ti-check' : 'ti ti-x'" />
                        {{ trans('plugins/marketplace::subscription.plans.form.' . $feature) }}
                    </li>
                @endforeach
            </ul>

            <div class="mp-plan-card__action">
                @if ($cta['disabled'] || ! $cta['url'])
                    <span class="btn btn-outline-secondary w-100 disabled">{{ $cta['label'] }}</span>
                @else
                    <a href="{{ $cta['url'] }}" class="btn btn-primary w-100">{{ $cta['label'] }}</a>
                @endif
            </div>
        </div>
    </div>
</div>

@once
    <style>
        .mp-plan-card { display: flex; flex-direction: column; height: 100%; text-align: center; }
        .mp-plan-card__body { display: flex; flex-direction: column; flex: 1 1 auto; }
        .mp-plan-card__price { font-size: 2rem; font-weight: 700; line-height: 1.2; }
        .mp-plan-card__features { list-style: none; padding: 0; margin: 1rem 0 0; }
        .mp-plan-card__features li { padding: .25rem 0; }
        .mp-plan-card__action { margin-top: auto; padding-top: 1.5rem; }
    </style>
@endonce
