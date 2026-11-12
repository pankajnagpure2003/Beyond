<?php

namespace Botble\Marketplace\Tax;

use Botble\Ecommerce\Models\Tax;
use Botble\Ecommerce\Tax\Contracts\TaxCalculatorInterface;
use Botble\Ecommerce\Tax\DTOs\TaxComponent;
use Botble\Ecommerce\Tax\DTOs\TaxContext;
use Botble\Ecommerce\Tax\DTOs\TaxResult;
use Botble\Ecommerce\Tax\TaxZoneResolver;

/**
 * Prices a vendor subscription charge using the marketplace's real tax rules.
 *
 * The stock DefaultTaxCalculator reads rates off $context->product->taxes, which a
 * subscription has no equivalent of — a plan is not a product. This calculator ignores
 * the product entirely (SubscriptionTaxService only supplies one because TaxContext
 * demands a non-nullable Product) and resolves the configured default tax by the
 * vendor's billing address instead.
 */
class SubscriptionTaxCalculator implements TaxCalculatorInterface
{
    public const CONTEXT_TYPE = 'vendor_subscription';

    public function __construct(protected TaxZoneResolver $zoneResolver)
    {
    }

    public function supports(TaxContext $context): bool
    {
        return ($context->metadata['type'] ?? null) === self::CONTEXT_TYPE;
    }

    public function calculate(TaxContext $context): TaxResult
    {
        $tax = $this->resolveTax();

        if (! $tax) {
            return TaxResult::zero();
        }

        $rate = $this->resolveRate($tax, $context);

        if ($rate <= 0) {
            return TaxResult::zero();
        }

        $amount = round($context->price * $context->quantity * $rate / 100, 2);

        return new TaxResult(
            total_tax: $amount,
            tax_rate: $rate,
            components: [
                new TaxComponent(
                    name: $tax->title,
                    code: 'subscription_tax',
                    rate: $rate,
                    amount: $amount,
                    jurisdiction: $context->country,
                ),
            ],
        );
    }

    /**
     * Subscriptions have no per-product tax attachment, so the marketplace-wide default
     * tax is the only sensible starting point.
     */
    protected function resolveTax(): ?Tax
    {
        $taxId = get_ecommerce_setting('default_tax_rate');

        if (! $taxId) {
            return null;
        }

        return Tax::query()->where('id', $taxId)->first();
    }

    /**
     * Zone rules win over the tax's flat percentage when one matches the billing address.
     *
     * Uses resolveFromRules() rather than TaxZoneResolver::resolve(), which is a stub
     * that unconditionally returns null.
     */
    protected function resolveRate(Tax $tax, TaxContext $context): float
    {
        $rules = $tax->rules()->where('is_enabled', true)->orderBy('priority')->get();

        $rule = $this->zoneResolver->resolveFromRules(
            $rules,
            $context->country,
            $context->state,
            $context->city,
            $context->zip_code,
        );

        if ($rule && $rule->percentage !== null) {
            return (float) $rule->percentage;
        }

        return (float) $tax->percentage;
    }
}
