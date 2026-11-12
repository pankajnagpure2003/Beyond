<?php

namespace Botble\Ecommerce\Http\Requests;

use Botble\Ecommerce\Facades\EcommerceHelper;
use Botble\Location\Rules\CityRule;
use Illuminate\Support\Arr;

class SaveCheckoutInformationRequest extends CheckoutRequest
{
    /**
     * Address keys whose city was dropped by prepareForValidation() and must not be required.
     *
     * @var array<int, string>
     */
    protected array $addressKeysWithoutCity = [];

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->dropCityNotBelongingToSelectedState();
    }

    public function rules(): array
    {
        $rules = parent::rules();

        Arr::forget($rules, ['payment_method', 'shipping_method', 'shipping_option', 'amount', 'agree_terms_and_policy']);

        foreach ($rules as $key => $rule) {
            if (str_contains($key, 'shipping_method.') || str_contains($key, 'shipping_option.')) {
                unset($rules[$key]);
            }
        }

        foreach ($this->addressKeysWithoutCity as $key) {
            if (isset($rules[$key])) {
                $rules[$key] = ['nullable'];
            }
        }

        return $rules;
    }

    /**
     * This request saves the checkout address as the buyer fills it in, and it fires on every field
     * change - including while the state/city pair is mid-change. When a state is picked, the city
     * dropdown is reloaded and reset to its placeholder, so the city sent along with the new state
     * either belongs to the previous state or is the placeholder. Validating it with CityRule would
     * reject the whole request, the session would keep the previous state, and the shipping fee
     * would stay on the previous state's rate until a city is chosen (support ticket 4578506).
     *
     * A mismatched city is therefore dropped instead of rejected: the session picks up the new
     * state right away and the fee is recalculated from the state-level shipping rule, which is
     * what BASED_ON_LOCATION rules fall back to when no city matches. Nothing is finalised here -
     * placing the order still goes through CheckoutRequest, which requires a complete address.
     */
    protected function dropCityNotBelongingToSelectedState(): void
    {
        if (
            ! EcommerceHelper::loadCountriesStatesCitiesFromPluginLocation()
            || EcommerceHelper::useCityFieldAsTextField()
        ) {
            return;
        }

        foreach (['address', 'billing_address'] as $prefix) {
            $address = $this->input($prefix);

            if (! is_array($address) || ! array_key_exists('city', $address)) {
                continue;
            }

            $city = $address['city'];

            if ($city !== null && $city !== '' && $this->cityBelongsToSelectedState($prefix, $city)) {
                continue;
            }

            $address['city'] = null;

            $this->merge([$prefix => $address]);

            $this->addressKeysWithoutCity[] = "$prefix.city";
        }
    }

    protected function cityBelongsToSelectedState(string $prefix, mixed $city): bool
    {
        // Same rule the full checkout uses, so both agree on what a valid city for a state is.
        $rule = (new CityRule("$prefix.state"))->setData($this->all());

        return $rule->passes("$prefix.city", $city);
    }
}
