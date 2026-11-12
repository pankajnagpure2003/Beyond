@extends(MarketplaceHelper::viewPath('vendor-dashboard.layouts.master'))

@php
    use Botble\Marketplace\Enums\SubscriptionDurationUnitEnum;

    // Included/excluded checklist, following Tabler's pricing-card pattern: a green check
    // for what the plan grants and a red cross for what it withholds, rather than
    // strikethrough text, which reads as damaged copy.
    $features = ['allow_digital_products', 'allow_coupons', 'allow_product_import'];
@endphp

@section('content')
    @if ($pending)
        <div class="alert alert-warning d-flex align-items-center gap-2">
            <x-core::icon name="ti ti-clock" />
            <span>{{ trans('plugins/marketplace::subscription.vendor.pending_description') }}</span>
        </div>
    @endif

    @if ($subscription && ! $pending)
        <div class="alert alert-info d-flex align-items-center gap-2">
            <x-core::icon name="ti ti-info-circle" />
            <span>{{ trans('plugins/marketplace::subscription.vendor.change_plan_warning') }}</span>
        </div>
    @endif

    <div class="row row-cards g-3 align-items-stretch">
        @forelse ($plans as $plan)
            @php
                $isCurrent = $subscription && $subscription->subscription_plan_id === $plan->getKey();
                $limit = $plan->getOption('product_limit');
            @endphp

            <div class="col-md-6 col-xl-4 d-flex">
                {{-- h-100 + flex column keeps every card the same height with the action pinned to the bottom. --}}
                <div class="card h-100 w-100 text-center {{ $isCurrent ? 'border-primary' : '' }}">
                    @if ($isCurrent)
                        <div class="ribbon ribbon-top ribbon-bookmark bg-primary">
                            <x-core::icon name="ti ti-check" />
                        </div>
                    @endif

                    <div class="card-body d-flex flex-column">
                        <div class="card-title text-uppercase text-muted small fw-bold">{{ $plan->name }}</div>

                        <div class="display-5 fw-bold">
                            {{ $plan->isFree()
                                ? trans('plugins/marketplace::subscription.subscriptions.free')
                                : format_price($plan->price) }}
                        </div>

                        <div class="text-muted small">
                            @if ($plan->isLifetime())
                                {{ trans('plugins/marketplace::subscription.duration_units.lifetime') }}
                            @else
                                {{ trans('plugins/marketplace::subscription.plans.form.duration_value') }}:
                                {{ $plan->duration_value }}
                                {{ SubscriptionDurationUnitEnum::getLabel($plan->duration_unit) }}
                            @endif
                        </div>

                        @if ($plan->description)
                            <p class="text-muted small mt-3 mb-0">{{ $plan->description }}</p>
                        @endif

                        <ul class="list-unstyled lh-lg mt-3 mb-0">
                            <li>
                                @if ($limit < 0)
                                    <strong>{{ trans('plugins/marketplace::subscription.subscriptions.unlimited_products') }}</strong>
                                @else
                                    <strong>{{ $limit }}</strong>
                                    {{ Str::lower(trans('plugins/ecommerce::products.name')) }}
                                @endif
                            </li>

                            @foreach ($features as $feature)
                                @php($enabled = $plan->getOption($feature) > 0)
                                <li class="{{ $enabled ? '' : 'text-muted' }}">
                                    <span class="{{ $enabled ? 'text-green' : 'text-danger' }}">
                                        <x-core::icon :name="$enabled ? 'ti ti-check' : 'ti ti-x'" />
                                    </span>
                                    {{ trans('plugins/marketplace::subscription.plans.form.' . $feature) }}
                                </li>
                            @endforeach
                        </ul>

                        {{-- mt-auto pushes the action to the bottom so buttons line up across cards. --}}
                        <div class="mt-auto pt-4">
                            @if ($isCurrent)
                                <span class="btn btn-outline-primary w-100 disabled">
                                    {{ trans('plugins/marketplace::subscription.vendor.current_plan') }}
                                </span>
                            @elseif ($pending)
                                <span class="btn btn-primary w-100 disabled">
                                    {{ trans('plugins/marketplace::subscription.vendor.subscribe') }}
                                </span>
                            @else
                                <a
                                    href="{{ route('marketplace.vendor.subscriptions.checkout', $plan->getKey()) }}"
                                    class="btn btn-primary w-100"
                                >
                                    {{ $subscription
                                        ? trans('plugins/marketplace::subscription.vendor.change_plan')
                                        : trans('plugins/marketplace::subscription.vendor.subscribe') }}
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="alert alert-info mb-0">{{ trans('core/base::tables.no_data') }}</div>
            </div>
        @endforelse
    </div>

    @if ($subscription)
        <div class="card mt-3">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h3 class="mb-1">{{ trans('plugins/marketplace::subscription.vendor.current_plan') }}:
                        {{ $subscription->planName() }}</h3>
                    <div class="text-muted">
                        @if ($productLimit === null)
                            {{ trans('plugins/marketplace::subscription.vendor.products_used_unlimited', ['used' => $usedSlots]) }}
                        @else
                            {{ trans('plugins/marketplace::subscription.vendor.products_used', [
                                'used' => $usedSlots,
                                'total' => $productLimit,
                            ]) }}
                        @endif
                    </div>
                </div>
                <a href="{{ route('marketplace.vendor.subscriptions.index') }}" class="btn btn-outline-secondary">
                    {{ trans('plugins/marketplace::subscription.vendor.menu') }}
                </a>
            </div>
        </div>
    @endif
@stop
