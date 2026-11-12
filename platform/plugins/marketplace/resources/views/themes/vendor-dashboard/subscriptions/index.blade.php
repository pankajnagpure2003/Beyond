@extends(MarketplaceHelper::viewPath('vendor-dashboard.layouts.master'))

@push('header')
    <style>
        /* The vendor dashboard theme never defines Bootstrap's .table-responsive, so the
           wrapper computes to overflow-x: visible and a wide table pushes the whole page
           sideways instead of scrolling inside its own box. The page then clips content at
           the right edge — most visibly once the browser is zoomed in. Scope the missing
           rule here rather than shipping a global one. */
        .vendor-subscription-page .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
    </style>
@endpush

@section('content')
    <div class="vendor-subscription-page">
    @if ($pending)
        <div class="card mb-3">
            <div class="card-body text-center">
                <h3>{{ trans('plugins/marketplace::subscription.vendor.pending_title') }}</h3>
                <p class="text-muted mb-0">
                    {{ trans('plugins/marketplace::subscription.vendor.pending_description') }}
                </p>
            </div>
        </div>
    @elseif ($latest && $latest->status == \Botble\Marketplace\Enums\SubscriptionStatusEnum::REJECTED)
        <div class="alert alert-danger">
            {{ trans('plugins/marketplace::subscription.vendor.rejected_reason', ['reason' => $latest->rejected_reason]) }}
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4 class="card-title mb-0">{{ trans('plugins/marketplace::subscription.vendor.current_plan') }}</h4>
            <a href="{{ route('marketplace.vendor.subscriptions.plans') }}" class="btn btn-primary btn-sm">
                {{ $subscription ? trans('plugins/marketplace::subscription.vendor.change_plan') : trans('plugins/marketplace::subscription.vendor.choose_plan') }}
            </a>
        </div>
        <div class="card-body">
            @if ($subscription)
                <dl class="row mb-0">
                    <dt class="col-sm-4">{{ trans('plugins/marketplace::subscription.subscriptions.plan') }}</dt>
                    <dd class="col-sm-8">{{ $subscription->planName() }}</dd>

                    <dt class="col-sm-4">{{ trans('plugins/marketplace::subscription.subscriptions.ends_at') }}</dt>
                    <dd class="col-sm-8">
                        @if ($subscription->isLifetime())
                            {{ trans('plugins/marketplace::subscription.vendor.never_expires') }}
                        @else
                            <span title="{{ BaseHelper::formatDate($subscription->ends_at) }}">
                                {{ trans('plugins/marketplace::subscription.vendor.expires_on', ['date' => BaseHelper::formatDate($subscription->ends_at)]) }}
                            </span>
                            <span class="text-muted">
                                ({{ trans(
                                    $subscription->auto_renew
                                        ? 'plugins/marketplace::subscription.vendor.renews_in'
                                        : 'plugins/marketplace::subscription.vendor.expires_in',
                                    ['time' => $subscription->ends_at->diffForHumans()]
                                ) }})
                            </span>
                        @endif
                    </dd>

                    <dt class="col-sm-4">{{ trans('plugins/ecommerce::products.name') }}</dt>
                    <dd class="col-sm-8">
                        @if ($productLimit === null)
                            {{ trans('plugins/marketplace::subscription.vendor.products_used_unlimited', ['used' => $usedSlots]) }}
                        @else
                            {{ trans('plugins/marketplace::subscription.vendor.products_used', ['used' => $usedSlots, 'total' => $productLimit]) }}
                            <div class="progress mt-1" style="height: 6px;">
                                <div
                                    class="progress-bar {{ $usedSlots >= $productLimit ? 'bg-danger' : 'bg-primary' }}"
                                    role="progressbar"
                                    style="width: {{ $productLimit > 0 ? min(100, round($usedSlots / $productLimit * 100)) : 100 }}%"
                                ></div>
                            </div>
                        @endif
                    </dd>
                </dl>

                @if (! $subscription->isLifetime())
                    <form method="POST" action="{{ route('marketplace.vendor.subscriptions.auto-renew') }}" class="mt-3">
                        @csrf
                        <div class="form-check">
                            <input type="hidden" name="auto_renew" value="0">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="auto-renew"
                                name="auto_renew"
                                value="1"
                                onchange="this.form.submit()"
                                @checked($subscription->auto_renew)
                            >
                            <label class="form-check-label" for="auto-renew">
                                {{ trans('plugins/marketplace::subscription.vendor.auto_renew_label') }}
                            </label>
                        </div>
                    </form>
                @endif
            @else
                <p class="mb-0 text-muted">{{ trans('plugins/marketplace::subscription.vendor.no_plan') }}</p>
            @endif
        </div>
    </div>

    @if ($subscription)
        @include(MarketplaceHelper::viewPath('vendor-dashboard.subscriptions.partials.plan-details'))
    @endif

    @include(MarketplaceHelper::viewPath('vendor-dashboard.subscriptions.partials.history-tabs'))

    @if ($canCancel)
        @include(MarketplaceHelper::viewPath('vendor-dashboard.subscriptions.partials.cancel'))
    @endif
    </div>
@stop
