@php
    /** Quota options render as a number (or "Unlimited"); flags render as yes/no. */
    $quotas = ['product_limit', 'featured_product_limit', 'listing_priority'];
@endphp

<div class="card mb-3">
    <div class="card-header">
        <h4 class="card-title mb-0">{{ trans('plugins/marketplace::subscription.vendor.plan_details') }}</h4>
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <tbody>
                @foreach (\Botble\Marketplace\Models\SubscriptionPlan::defaultOptions() as $option => $default)
                    @php($value = $subscription->option($option))
                    <tr>
                        <td>{{ trans('plugins/marketplace::subscription.plans.form.' . $option) }}</td>
                        <td class="text-end">
                            @if (in_array($option, $quotas, true))
                                {{ $value < 0 ? trans('plugins/marketplace::subscription.vendor.unlimited') : $value }}
                            @elseif ($value)
                                <span class="badge bg-green text-green-fg">
                                    {{ trans('plugins/marketplace::subscription.vendor.included') }}
                                </span>
                            @else
                                <span class="badge bg-secondary text-secondary-fg">
                                    {{ trans('plugins/marketplace::subscription.vendor.not_included') }}
                                </span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
