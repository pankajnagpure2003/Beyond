<?php

namespace Botble\Marketplace\Forms\Fronts;

use Botble\Base\Forms\FieldOptions\EmailFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\EmailField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\Ecommerce\Forms\Concerns\HasLocationFields;

/**
 * The billing block on the subscription checkout.
 *
 * Built on HasLocationFields so the country/state/city inputs behave exactly as they do
 * at storefront checkout — cascading selects when the location plugin supplies the data,
 * plain text otherwise — and so the values validate against the same rules
 * SubscriptionBillingRequest pulls from EcommerceHelper.
 *
 * Rendered fields-only inside the checkout form; it never emits a <form> of its own.
 */
class SubscriptionBillingForm extends FormAbstract
{
    use HasLocationFields;

    public function setup(): void
    {
        $billing = (array) $this->getModel();

        $this
            ->contentOnly()
            ->add(
                'billing_name',
                TextField::class,
                TextFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.billing.name'))
                    ->value($billing['name'] ?? null)
                    ->colspan(2)
            )
            ->add(
                'billing_email',
                EmailField::class,
                EmailFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.billing.email'))
                    ->value($billing['email'] ?? null)
                    ->colspan(2)
            )
            ->add(
                'billing_phone',
                TextField::class,
                TextFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.billing.phone'))
                    ->value($billing['phone'] ?? null)
                    ->colspan(2)
            )
            ->add(
                'billing_tax_id',
                TextField::class,
                TextFieldOption::make()
                    ->label(trans('plugins/marketplace::subscription.billing.tax_id'))
                    ->helperText(trans('plugins/marketplace::subscription.billing.tax_id_helper'))
                    ->value($billing['tax_id'] ?? null)
                    ->colspan(2)
            )
            ->addLocationFields(
                countryAttributes: ['name' => 'billing_country', 'value' => $billing['country'] ?? null],
                stateAttributes: ['name' => 'billing_state', 'value' => $billing['state'] ?? null],
                cityAttributes: ['name' => 'billing_city', 'value' => $billing['city'] ?? null],
                addressAttributes: ['name' => 'billing_address', 'value' => $billing['address'] ?? null],
                zipCodeAttributes: ['name' => 'billing_zip_code', 'value' => $billing['zip_code'] ?? null],
            );
    }
}
