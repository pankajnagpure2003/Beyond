@php
    use Botble\Marketplace\Enums\SubscriptionStatusEnum;

    $vendorName = $vendorSubscription->customer?->name ?: trans('plugins/marketplace::subscription.subscriptions.vendor');
    $isActive = $vendorSubscription->status == SubscriptionStatusEnum::ACTIVE;
@endphp

<x-core::card>
    <x-core::card.header>
        <x-core::card.title>{{ trans('core/base::forms.actions') }}</x-core::card.title>
    </x-core::card.header>
    <x-core::card.body>
        {{-- Every action opens a confirmation modal rather than firing straight from the
             sidebar: approve, reject and cancel are all one-way transitions that email the
             vendor, and reject/extend need an input that has no business sitting in a
             permanently visible panel.

             The states handled below must stay in step with
             VendorSubscription::hasAdminActions(), which decides whether this card is
             rendered at all — a button added for a state that method excludes is dead. --}}
        <div class="d-grid gap-2">
            @if ($vendorSubscription->isPending())
                <x-core::button
                    type="button"
                    color="success"
                    icon="ti ti-check"
                    data-bs-toggle="modal"
                    data-bs-target="#approve-subscription-modal"
                >
                    {{ trans('plugins/marketplace::subscription.actions.approve') }}
                </x-core::button>

                <x-core::button
                    type="button"
                    color="danger"
                    icon="ti ti-x"
                    data-bs-toggle="modal"
                    data-bs-target="#reject-subscription-modal"
                >
                    {{ trans('plugins/marketplace::subscription.actions.reject') }}
                </x-core::button>
            @endif

            @if ($isActive && ! $vendorSubscription->isLifetime())
                <x-core::button
                    type="button"
                    color="primary"
                    icon="ti ti-calendar-plus"
                    data-bs-toggle="modal"
                    data-bs-target="#extend-subscription-modal"
                >
                    {{ trans('plugins/marketplace::subscription.actions.extend') }}
                </x-core::button>
            @endif

            @if ($isActive)
                <x-core::button
                    type="button"
                    color="warning"
                    icon="ti ti-ban"
                    data-bs-toggle="modal"
                    data-bs-target="#cancel-subscription-modal"
                >
                    {{ trans('plugins/marketplace::subscription.actions.cancel') }}
                </x-core::button>
            @endif
        </div>
    </x-core::card.body>
</x-core::card>

@push('footer')
    @if ($vendorSubscription->isPending())
        <x-core::modal
            id="approve-subscription-modal"
            type="success"
            :title="trans('plugins/marketplace::subscription.actions.approve_confirmation')"
            :form-action="route('marketplace.vendor-subscriptions.approve', $vendorSubscription->getKey())"
        >
            <p class="mb-0">
                {!! BaseHelper::clean(trans('plugins/marketplace::subscription.actions.approve_confirmation_description', [
                    'vendor' => Html::tag('strong', $vendorName)->toHtml(),
                    'plan' => Html::tag('strong', $vendorSubscription->planName())->toHtml(),
                ])) !!}
            </p>

            <x-slot:footer>
                <x-core::button type="button" data-bs-dismiss="modal">
                    {{ trans('core/base::tables.cancel') }}
                </x-core::button>
                <x-core::button type="submit" color="success" class="ms-auto">
                    {{ trans('plugins/marketplace::subscription.actions.approve') }}
                </x-core::button>
            </x-slot:footer>
        </x-core::modal>

        <x-core::modal
            id="reject-subscription-modal"
            type="danger"
            :title="trans('plugins/marketplace::subscription.actions.reject_confirmation')"
            :form-action="route('marketplace.vendor-subscriptions.reject', $vendorSubscription->getKey())"
        >
            <p>
                {!! BaseHelper::clean(trans('plugins/marketplace::subscription.actions.reject_confirmation_description', [
                    'vendor' => Html::tag('strong', $vendorName)->toHtml(),
                ])) !!}
            </p>

            {{-- Laid out by hand rather than via x-core::form.textarea: that component has
                 no way to mark its label required, and this field is the whole point of
                 the modal. --}}
            <x-core::form-group>
                <x-core::form.label
                    :label="trans('plugins/marketplace::subscription.actions.reason')"
                    for="reject-subscription-reason"
                    class="required"
                />
                <textarea
                    id="reject-subscription-reason"
                    name="reason"
                    class="form-control"
                    rows="3"
                    required
                    maxlength="400"
                    placeholder="{{ trans('plugins/marketplace::subscription.actions.reason_placeholder') }}"
                >{{ old('reason') }}</textarea>
                <x-core::form.helper-text>
                    {{ trans('plugins/marketplace::subscription.actions.reason_helper') }}
                </x-core::form.helper-text>
                <x-core::form.error key="reason" />
            </x-core::form-group>

            <x-slot:footer>
                <x-core::button type="button" data-bs-dismiss="modal">
                    {{ trans('core/base::tables.cancel') }}
                </x-core::button>
                <x-core::button type="submit" color="danger" class="ms-auto">
                    {{ trans('plugins/marketplace::subscription.actions.reject') }}
                </x-core::button>
            </x-slot:footer>
        </x-core::modal>
    @endif

    @if ($isActive && ! $vendorSubscription->isLifetime())
        <x-core::modal
            id="extend-subscription-modal"
            type="info"
            :title="trans('plugins/marketplace::subscription.actions.extend_confirmation')"
            :form-action="route('marketplace.vendor-subscriptions.extend', $vendorSubscription->getKey())"
        >
            <p>
                {!! BaseHelper::clean(trans('plugins/marketplace::subscription.actions.extend_confirmation_description', [
                    'date' => Html::tag('strong', $vendorSubscription->ends_at ? BaseHelper::formatDate($vendorSubscription->ends_at) : '—')->toHtml(),
                ])) !!}
            </p>

            <x-core::form.text-input
                type="number"
                name="days"
                value="30"
                min="1"
                max="3650"
                :label="trans('plugins/marketplace::subscription.actions.extend_days')"
                :required="true"
            />

            <x-slot:footer>
                <x-core::button type="button" data-bs-dismiss="modal">
                    {{ trans('core/base::tables.cancel') }}
                </x-core::button>
                <x-core::button type="submit" color="primary" class="ms-auto">
                    {{ trans('plugins/marketplace::subscription.actions.extend') }}
                </x-core::button>
            </x-slot:footer>
        </x-core::modal>
    @endif

    @if ($isActive)
        <x-core::modal
            id="cancel-subscription-modal"
            type="warning"
            :title="trans('plugins/marketplace::subscription.actions.cancel_confirmation')"
            :form-action="route('marketplace.vendor-subscriptions.cancel', $vendorSubscription->getKey())"
        >
            <p class="mb-0">
                {!! BaseHelper::clean(trans('plugins/marketplace::subscription.actions.cancel_confirmation_description', [
                    'vendor' => Html::tag('strong', $vendorName)->toHtml(),
                ])) !!}
            </p>

            {{-- Both buttons would otherwise read "Cancel" — one dismissing the dialog, one
                 ending the vendor's subscription. Spell both out. --}}
            <x-slot:footer>
                <x-core::button type="button" data-bs-dismiss="modal">
                    {{ trans('plugins/marketplace::subscription.actions.cancel_confirmation_keep') }}
                </x-core::button>
                <x-core::button type="submit" color="warning" class="ms-auto">
                    {{ trans('plugins/marketplace::subscription.actions.cancel_confirmation_confirm') }}
                </x-core::button>
            </x-slot:footer>
        </x-core::modal>
    @endif
@endpush
