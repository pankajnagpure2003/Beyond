<?php

namespace Botble\Marketplace\Forms;

use Botble\Base\Forms\FieldOptions\DatePickerFieldOption;
use Botble\Base\Forms\FieldOptions\OnOffFieldOption;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;
use Botble\Base\Forms\Fields\DatePickerField;
use Botble\Base\Forms\Fields\OnOffCheckboxField;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\FormAbstract;
use Botble\Marketplace\Http\Requests\VendorSubscriptionRequest;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\Vendor;
use Botble\Marketplace\Models\VendorSubscription;

/**
 * Manual assignment: the admin grants a plan to a vendor with no payment involved.
 */
class VendorSubscriptionForm extends FormAbstract
{
    public function setup(): void
    {
        // getModel() returns the raw form options array until ->model() is called.
        $model = $this->getModel();
        $subscription = $model instanceof VendorSubscription ? $model : null;

        $this
            ->model(VendorSubscription::class)
            ->setValidatorClass(VendorSubscriptionRequest::class)
            ->columns(12)
            ->add(
                'customer_id',
                SelectField::class,
                SelectFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.subscriptions.vendor'))
                    ->choices($this->vendorChoices())
                    ->selected($subscription?->customer_id)
                    ->searchable()
                    ->required()
                    ->colspan(6)
            )
            ->add(
                'subscription_plan_id',
                SelectField::class,
                SelectFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.subscriptions.plan'))
                    ->choices($this->planChoices())
                    ->selected($subscription?->subscription_plan_id)
                    ->required()
                    ->colspan(6)
            )
            ->add(
                'ends_at',
                DatePickerField::class,
                DatePickerFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.subscriptions.ends_at'))
                    ->helperText(trans('plugins/marketplace::subscription.subscriptions.ends_at_helper'))
                    ->value($subscription?->ends_at?->toDateString())
                    ->colspan(6)
            )
            ->add(
                'auto_renew',
                OnOffCheckboxField::class,
                OnOffFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.subscriptions.auto_renew'))
                    ->value((bool) $subscription?->auto_renew)
                    ->colspan(6)
            );
    }

    protected function vendorChoices(): array
    {
        return Vendor::query()
            ->select(['id', 'name', 'email'])
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Vendor $vendor) => [$vendor->getKey() => $vendor->name . ' (' . $vendor->email . ')'])
            ->all();
    }

    protected function planChoices(): array
    {
        return SubscriptionPlan::query()
            ->available()
            ->pluck('name', 'id')
            ->all();
    }
}
