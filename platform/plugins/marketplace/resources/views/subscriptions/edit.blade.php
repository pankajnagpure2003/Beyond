@extends('core/base::layouts.master')

@php
    // Expired, cancelled and rejected subscriptions have no action left to take, and an
    // empty Actions card next to a narrowed detail pane just looks broken.
    $hasActions = $vendorSubscription->hasAdminActions();
@endphp

@section('content')
    <div class="row">
        <div class="{{ $hasActions ? 'col-lg-8' : 'col-lg-12' }}">
            <x-core::card>
                <x-core::card.header>
                    <x-core::card.title>
                        {{ trans('plugins/marketplace::subscription.subscriptions.name') }}
                    </x-core::card.title>
                </x-core::card.header>
                <x-core::card.body>
                    <x-core::datagrid>
                        <x-core::datagrid.item>
                            <x-slot:title>{{ trans('plugins/marketplace::subscription.subscriptions.vendor') }}</x-slot:title>
                            @if ($vendorSubscription->customer)
                                <a href="{{ route('customers.edit', $vendorSubscription->customer->id) }}">{{ $vendorSubscription->customer->name }}</a>
                            @else
                                &mdash;
                            @endif
                        </x-core::datagrid.item>

                        <x-core::datagrid.item>
                            <x-slot:title>{{ trans('plugins/marketplace::subscription.subscriptions.plan') }}</x-slot:title>
                            {{ $vendorSubscription->planName() }}
                        </x-core::datagrid.item>

                        {{-- Only worth splitting out when tax was actually charged; with tax
                             off every subscription would otherwise carry two dead rows. --}}
                        @if ($vendorSubscription->tax_amount > 0)
                            <x-core::datagrid.item>
                                <x-slot:title>{{ trans('plugins/marketplace::subscription.invoices.sub_total') }}</x-slot:title>
                                {{ format_price($vendorSubscription->sub_total) }}
                            </x-core::datagrid.item>

                            <x-core::datagrid.item>
                                <x-slot:title>
                                    {{ trans('plugins/marketplace::subscription.invoices.tax') }}
                                    ({{ rtrim(rtrim(number_format($vendorSubscription->tax_rate, 2), '0'), '.') }}%)
                                </x-slot:title>
                                {{ format_price($vendorSubscription->tax_amount) }}
                            </x-core::datagrid.item>
                        @endif

                        <x-core::datagrid.item>
                            <x-slot:title>{{ trans('plugins/marketplace::subscription.subscriptions.amount') }}</x-slot:title>
                            {{ format_price($vendorSubscription->amount) }}
                        </x-core::datagrid.item>

                        <x-core::datagrid.item>
                            <x-slot:title>{{ trans('core/base::tables.status') }}</x-slot:title>
                            {!! $vendorSubscription->status->toHtml() !!}
                        </x-core::datagrid.item>

                        <x-core::datagrid.item>
                            <x-slot:title>{{ trans('plugins/marketplace::subscription.subscriptions.starts_at') }}</x-slot:title>
                            {{ $vendorSubscription->starts_at ? BaseHelper::formatDateTime($vendorSubscription->starts_at) : '—' }}
                        </x-core::datagrid.item>

                        <x-core::datagrid.item>
                            <x-slot:title>{{ trans('plugins/marketplace::subscription.subscriptions.ends_at') }}</x-slot:title>
                            @if ($vendorSubscription->isLifetime())
                                {{ trans('plugins/marketplace::subscription.subscriptions.lifetime') }}
                            @elseif ($vendorSubscription->ends_at)
                                {{ BaseHelper::formatDateTime($vendorSubscription->ends_at) }}
                            @else
                                &mdash;
                            @endif
                        </x-core::datagrid.item>

                        <x-core::datagrid.item>
                            <x-slot:title>{{ trans('plugins/marketplace::subscription.subscriptions.payment_channel') }}</x-slot:title>
                            {{ $vendorSubscription->payment_channel ?: '—' }}
                        </x-core::datagrid.item>

                        <x-core::datagrid.item>
                            <x-slot:title>{{ trans('plugins/marketplace::subscription.subscriptions.auto_renew') }}</x-slot:title>
                            {{ $vendorSubscription->auto_renew ? trans('core/base::base.yes') : trans('core/base::base.no') }}
                        </x-core::datagrid.item>

                        @if ($vendorSubscription->rejected_reason)
                            <x-core::datagrid.item>
                                <x-slot:title>{{ trans('plugins/marketplace::subscription.actions.reason') }}</x-slot:title>
                                {{ $vendorSubscription->rejected_reason }}
                            </x-core::datagrid.item>
                        @endif
                    </x-core::datagrid>
                </x-core::card.body>
            </x-core::card>

            @if ($vendorSubscription->invoices->isNotEmpty())
                <x-core::card class="mt-3">
                    <x-core::card.header>
                        <x-core::card.title>
                            {{ trans('plugins/marketplace::subscription.invoices.menu') }}
                        </x-core::card.title>
                    </x-core::card.header>
                    <x-core::table class="table-vcenter">
                        <x-core::table.header>
                            <x-core::table.header.cell>
                                {{ trans('plugins/marketplace::subscription.invoices.code') }}
                            </x-core::table.header.cell>
                            <x-core::table.header.cell>
                                {{ trans('plugins/marketplace::subscription.invoices.issued_at') }}
                            </x-core::table.header.cell>
                            <x-core::table.header.cell>
                                {{ trans('plugins/marketplace::subscription.invoices.amount') }}
                            </x-core::table.header.cell>
                            <x-core::table.header.cell>
                                {{ trans('core/base::tables.status') }}
                            </x-core::table.header.cell>
                            <x-core::table.header.cell class="w-1"></x-core::table.header.cell>
                        </x-core::table.header>
                        <x-core::table.body>
                            @foreach ($vendorSubscription->invoices as $invoice)
                                <x-core::table.body.row>
                                    <x-core::table.body.cell>{{ $invoice->code }}</x-core::table.body.cell>
                                    <x-core::table.body.cell>
                                        {{ BaseHelper::formatDate($invoice->created_at) }}
                                    </x-core::table.body.cell>
                                    <x-core::table.body.cell>{{ format_price($invoice->amount) }}</x-core::table.body.cell>
                                    <x-core::table.body.cell>{!! $invoice->status->toHtml() !!}</x-core::table.body.cell>
                                    <x-core::table.body.cell>
                                        <a
                                            href="{{ route('marketplace.vendor-subscriptions.invoices.download', $invoice->getKey()) }}"
                                            class="btn btn-sm btn-outline-primary"
                                            title="{{ trans('plugins/marketplace::subscription.invoices.download') }}"
                                        >
                                            <x-core::icon name="ti ti-download" />
                                        </a>
                                    </x-core::table.body.cell>
                                </x-core::table.body.row>
                            @endforeach
                        </x-core::table.body>
                    </x-core::table>
                </x-core::card>
            @endif

            <x-core::card class="mt-3">
                <x-core::card.header>
                    <x-core::card.title>
                        {{ trans('plugins/marketplace::subscription.subscriptions.history') }}
                    </x-core::card.title>
                </x-core::card.header>
                <x-core::card.body>
                    @forelse ($vendorSubscription->logs()->latest()->get() as $log)
                        <div class="d-flex justify-content-between border-bottom py-2">
                            <div>
                                <strong>{{ $log->label() }}</strong>
                                @if ($log->user)
                                    <span class="text-muted">— {{ $log->user->name }}</span>
                                @endif
                                @if (! empty($log->data['reason']))
                                    <div class="text-muted small">{{ $log->data['reason'] }}</div>
                                @endif
                            </div>
                            <div class="text-muted small">{{ BaseHelper::formatDateTime($log->created_at) }}</div>
                        </div>
                    @empty
                        <p class="text-muted mb-0">{{ trans('core/base::tables.no_data') }}</p>
                    @endforelse
                </x-core::card.body>
            </x-core::card>
        </div>

        @if ($hasActions)
            <div class="col-lg-4">
                @include('plugins/marketplace::subscriptions.partials.actions', [
                    'vendorSubscription' => $vendorSubscription,
                ])
            </div>
        @endif
    </div>
@endsection
