<?php

namespace Botble\Marketplace\Http\Requests\Fronts;

use Botble\Ecommerce\Facades\EcommerceHelper;
use Botble\Marketplace\Services\SubscriptionTaxService;
use Botble\Support\Http\Requests\Request;

/**
 * Validates the billing block a vendor submits with a subscription checkout.
 *
 * The rules come from EcommerceHelper so the fields honour the store's own address
 * configuration — multi-country, state/city sources, zip code, and the hidden or
 * optional-field settings — instead of a second, drifting set of rules.
 *
 * Billing is only mandatory when subscription tax is on, because that is the only case
 * where an address changes the amount charged. With tax off the vendor may still fill it
 * in for the invoice, so the same rules apply but nothing is required.
 */
class SubscriptionBillingRequest extends Request
{
    public function rules(): array
    {
        $rules = [
            'payment_method' => ['nullable', 'string', 'max:60'],
            'auto_renew' => ['nullable'],
            'billing_save_address' => ['nullable'],
            'billing_tax_id' => ['nullable', 'string', 'max:60'],
        ];

        $addressRules = EcommerceHelper::getCustomerAddressValidationRules('billing_');

        if (! $this->billingIsMandatory()) {
            $addressRules = array_map([$this, 'relax'], $addressRules);
        }

        return array_merge($rules, $addressRules);
    }

    public function attributes(): array
    {
        return [
            'billing_name' => trans('plugins/marketplace::subscription.billing.name'),
            'billing_email' => trans('plugins/marketplace::subscription.billing.email'),
            'billing_phone' => trans('plugins/marketplace::subscription.billing.phone'),
            'billing_address' => trans('plugins/marketplace::subscription.billing.address'),
            'billing_country' => trans('plugins/marketplace::subscription.billing.country'),
            'billing_state' => trans('plugins/marketplace::subscription.billing.state'),
            'billing_city' => trans('plugins/marketplace::subscription.billing.city'),
            'billing_zip_code' => trans('plugins/marketplace::subscription.billing.zip_code'),
            'billing_tax_id' => trans('plugins/marketplace::subscription.billing.tax_id'),
        ];
    }

    protected function billingIsMandatory(): bool
    {
        return app(SubscriptionTaxService::class)->isEnabled();
    }

    /**
     * Turn a rule set into its optional equivalent, keeping the format constraints so a
     * value the vendor does supply is still checked.
     *
     * String rule sets ('required|in:...') are normalised to arrays first so both shapes
     * EcommerceHelper can return are handled.
     */
    protected function relax(array|string $rules): array
    {
        $rules = is_string($rules) ? explode('|', $rules) : $rules;

        $rules = array_values(array_filter(
            $rules,
            fn ($rule) => ! (is_string($rule) && in_array($rule, ['required', 'sometimes'], true))
        ));

        array_unshift($rules, 'nullable');

        return $rules;
    }
}
