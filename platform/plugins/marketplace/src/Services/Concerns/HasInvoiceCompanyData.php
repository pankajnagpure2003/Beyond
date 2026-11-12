<?php

namespace Botble\Marketplace\Services\Concerns;

use Botble\Media\Facades\RvMedia;
use Botble\Theme\Facades\Theme;

/**
 * The seller block shared by every PDF the marketplace issues.
 *
 * Payouts and subscription invoices both represent the platform billing or paying a
 * vendor, so they render the same company identity from the ecommerce invoicing
 * settings, each falling back to the general store setting.
 */
trait HasInvoiceCompanyData
{
    /** @return array<string, string|null> */
    protected function companyData(): array
    {
        $country = $this->getCompanyCountry();
        $state = $this->getCompanyState();
        $city = $this->getCompanyCity();

        $logo = get_ecommerce_setting('company_logo_for_invoicing') ?: (theme_option('logo_in_invoices') ?: Theme::getLogo());

        $address = get_ecommerce_setting('company_address_for_invoicing');

        if (! $address) {
            $address = implode(', ', array_filter([
                get_ecommerce_setting('company_address_for_invoicing', get_ecommerce_setting('store_address')),
                $city,
                $state,
                $country,
            ]));
        }

        return [
            'logo' => $logo ? RvMedia::getRealPath($logo) : null,
            'name' => get_ecommerce_setting('company_name_for_invoicing') ?: get_ecommerce_setting('store_name'),
            'address' => $address,
            'state' => $state,
            'city' => $city,
            'zipcode' => $this->getCompanyZipCode(),
            'phone' => get_ecommerce_setting('company_phone_for_invoicing') ?: get_ecommerce_setting('store_phone'),
            'email' => get_ecommerce_setting('company_email_for_invoicing') ?: get_ecommerce_setting('store_email'),
            'tax_id' => get_ecommerce_setting('company_tax_id_for_invoicing') ?: get_ecommerce_setting('store_vat_number'),
        ];
    }

    public function getCompanyCountry(): ?string
    {
        return get_ecommerce_setting('company_country_for_invoicing', get_ecommerce_setting('store_country'));
    }

    public function getCompanyState(): ?string
    {
        return get_ecommerce_setting('company_state_for_invoicing', get_ecommerce_setting('store_state'));
    }

    public function getCompanyCity(): ?string
    {
        return get_ecommerce_setting('company_city_for_invoicing', get_ecommerce_setting('store_city'));
    }

    public function getCompanyZipCode(): ?string
    {
        return get_ecommerce_setting('company_zipcode_for_invoicing', get_ecommerce_setting('store_zip_code'));
    }
}
