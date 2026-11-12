{{-- Invoices | Transactions | Activity. Bootstrap's tab JS ships with the dashboard's
     core assets, so data-bs-toggle is enough — no page-specific script. --}}
<div class="card">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <a href="#subscription-invoices" class="nav-link active" data-bs-toggle="tab" role="tab">
                    {{ trans('plugins/marketplace::subscription.vendor.tab_invoices') }}
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a href="#subscription-transactions" class="nav-link" data-bs-toggle="tab" role="tab">
                    {{ trans('plugins/marketplace::subscription.vendor.tab_transactions') }}
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a href="#subscription-logs" class="nav-link" data-bs-toggle="tab" role="tab">
                    {{ trans('plugins/marketplace::subscription.vendor.tab_logs') }}
                </a>
            </li>
        </ul>
    </div>
    <div class="tab-content">
        <div class="tab-pane active show" id="subscription-invoices" role="tabpanel">
            @if ($invoices->isEmpty())
                <div class="card-body text-muted">
                    {{ trans('plugins/marketplace::subscription.invoices.empty') }}
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>{{ trans('plugins/marketplace::subscription.invoices.code') }}</th>
                                <th>{{ trans('plugins/marketplace::subscription.invoices.title') }}</th>
                                <th>{{ trans('plugins/marketplace::subscription.invoices.issued_at') }}</th>
                                <th>{{ trans('plugins/marketplace::subscription.invoices.amount') }}</th>
                                <th>{{ trans('core/base::tables.status') }}</th>
                                <th class="w-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoices as $invoice)
                                <tr>
                                    <td>{{ $invoice->code }}</td>
                                    <td>{{ $invoice->title }}</td>
                                    <td>{{ BaseHelper::formatDate($invoice->created_at) }}</td>
                                    <td>{{ format_price($invoice->amount) }}</td>
                                    <td>{!! $invoice->status->toHtml() !!}</td>
                                    <td>
                                        <a
                                            href="{{ route('marketplace.vendor.subscriptions.invoices.download', $invoice->getKey()) }}"
                                            class="btn btn-sm btn-outline-primary"
                                            title="{{ trans('plugins/marketplace::subscription.invoices.download') }}"
                                        >
                                            {{-- x-core::icon emits inline SVG; the vendor
                                                 dashboard never loads the ti webfont, so a
                                                 bare <i class="ti"> renders as an empty pill. --}}
                                            <x-core::icon name="ti ti-download" />
                                            <span class="visually-hidden">
                                                {{ trans('plugins/marketplace::subscription.invoices.download') }}
                                            </span>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="tab-pane" id="subscription-transactions" role="tabpanel">
            @if ($history->isEmpty())
                <div class="card-body text-muted">
                    {{ trans('plugins/marketplace::subscription.vendor.transactions_empty') }}
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>{{ trans('plugins/marketplace::subscription.subscriptions.plan') }}</th>
                                <th>{{ trans('plugins/marketplace::subscription.subscriptions.amount') }}</th>
                                <th>{{ trans('plugins/marketplace::subscription.subscriptions.starts_at') }}</th>
                                <th>{{ trans('plugins/marketplace::subscription.subscriptions.ends_at') }}</th>
                                <th>{{ trans('core/base::tables.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($history as $item)
                                <tr>
                                    <td>{{ $item->planName() }}</td>
                                    <td>{{ format_price($item->amount) }}</td>
                                    <td>{{ $item->starts_at ? BaseHelper::formatDate($item->starts_at) : '—' }}</td>
                                    <td>
                                        @if ($item->isLifetime())
                                            {{ trans('plugins/marketplace::subscription.subscriptions.lifetime') }}
                                        @elseif ($item->ends_at)
                                            {{ BaseHelper::formatDate($item->ends_at) }}
                                        @else
                                            &mdash;
                                        @endif
                                    </td>
                                    <td>{!! $item->status->toHtml() !!}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="tab-pane" id="subscription-logs" role="tabpanel">
            @if ($logs->isEmpty())
                <div class="card-body text-muted">
                    {{ trans('plugins/marketplace::subscription.vendor.logs_empty') }}
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>{{ trans('plugins/marketplace::subscription.logs.name') }}</th>
                                <th>{{ trans('plugins/marketplace::subscription.subscriptions.plan') }}</th>
                                <th>{{ trans('core/base::tables.created_at') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($logs as $log)
                                <tr>
                                    <td>{{ $log->label() }}</td>
                                    <td>{{ $log->subscription?->planName() ?: '—' }}</td>
                                    <td>{{ BaseHelper::formatDate($log->created_at) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
