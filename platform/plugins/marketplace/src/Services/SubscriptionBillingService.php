<?php

namespace Botble\Marketplace\Services;

use Botble\Ecommerce\Models\Address;
use Botble\Ecommerce\Models\Customer;
use Botble\Marketplace\Models\VendorSubscription;
use Illuminate\Http\Request;

/**
 * The vendor's billing block for a subscription charge: where it is read from, how a
 * submitted one is completed, and how it flows back to their address book.
 *
 * Kept apart from SubscriptionCheckoutService because the invoice and tax paths need the
 * same block without any of the payment machinery.
 */
class SubscriptionBillingService
{
    /**
     * The billing block for the checkout form.
     *
     * What the vendor entered on their last subscription wins, so a renewal does not ask
     * them to retype a VAT number that has not changed — the address book has no field
     * for one. Their default address fills whatever that leaves blank.
     *
     * @return array<string, string|null>
     */
    public function prefilled(Customer $vendor): array
    {
        $address = $vendor->addresses()->orderByDesc('is_default')->first();

        $previous = (array) VendorSubscription::query()
            ->where('customer_id', $vendor->getKey())
            ->whereNotNull('billing_data')
            ->latest('id')
            ->value('billing_data');

        // billing_data is a JSON column; a raw value read never passes through the cast.
        $previous = is_string($previous) ? (array) json_decode($previous, true) : $previous;

        $fallback = [
            'name' => $address?->name ?: $vendor->name,
            'email' => $address?->email ?: $vendor->email,
            'phone' => $address?->phone ?: $vendor->phone,
            'address' => $address?->address,
            'country' => $address?->country,
            'state' => $address?->state,
            'city' => $address?->city,
            'zip_code' => $address?->zip_code,
            'tax_id' => null,
        ];

        return array_map(
            fn ($key) => filled($previous[$key] ?? null) ? $previous[$key] : $fallback[$key],
            array_combine(array_keys($fallback), array_keys($fallback))
        );
    }

    /**
     * Fall back to the vendor's default address for anything left blank, so an install
     * with tax off — where every billing field is optional — still snapshots something
     * usable onto the invoice.
     *
     * @return array<string, string|null>
     */
    public function fromRequest(Request $request, Customer $vendor): array
    {
        $prefilled = $this->prefilled($vendor);

        $billing = [];

        foreach (array_keys($prefilled) as $key) {
            $value = $request->input("billing_$key");
            $billing[$key] = filled($value) ? $value : $prefilled[$key];
        }

        return $billing;
    }

    /**
     * Mirror the submitted billing block onto the vendor's default address, so the next
     * checkout — and the storefront — start from what they just typed.
     */
    public function saveAsDefaultAddress(Customer $vendor, array $billing): void
    {
        $attributes = [
            'name' => $billing['name'],
            'email' => $billing['email'],
            'phone' => $billing['phone'],
            'address' => $billing['address'],
            'country' => $billing['country'],
            'state' => $billing['state'],
            'city' => $billing['city'],
            'zip_code' => $billing['zip_code'],
        ];

        $address = $vendor->addresses()->where('is_default', true)->first();

        if ($address) {
            $address->fill($attributes)->save();

            return;
        }

        Address::query()->create($attributes + [
            'customer_id' => $vendor->getKey(),
            'is_default' => true,
        ]);
    }
}
