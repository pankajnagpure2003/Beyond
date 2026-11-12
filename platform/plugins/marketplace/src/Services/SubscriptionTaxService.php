<?php

namespace Botble\Marketplace\Services;

use Botble\Ecommerce\Facades\EcommerceHelper;
use Botble\Ecommerce\Models\Product;
use Botble\Ecommerce\Tax\DTOs\TaxContext;
use Botble\Ecommerce\Tax\TaxEngineManager;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Tax\SubscriptionTaxCalculator;
use Throwable;

/**
 * The single place that knows how to tax a subscription charge.
 *
 * Tax is exclusive: the plan price is net, and the returned amount is added on top.
 */
class SubscriptionTaxService
{
    public function __construct(protected TaxEngineManager $taxEngine)
    {
    }

    public function isEnabled(): bool
    {
        return EcommerceHelper::isTaxEnabled()
            && (bool) MarketplaceHelper::getSetting('subscription_tax_enabled', false);
    }

    /**
     * @param  array{country?: ?string, state?: ?string, city?: ?string, zip_code?: ?string, tax_id?: ?string}  $billing
     * @return array{rate: float, amount: float}
     */
    public function calculate(float $amount, array $billing = []): array
    {
        if (! $this->isEnabled() || $amount <= 0) {
            return ['rate' => 0.0, 'amount' => 0.0];
        }

        try {
            $result = $this->taxEngine->calculate($this->buildContext($amount, $billing));
        } catch (Throwable) {
            // A misconfigured tax must never block a vendor from paying.
            return ['rate' => 0.0, 'amount' => 0.0];
        }

        return [
            'rate' => round($result->tax_rate, 4),
            'amount' => round($result->total_tax, 2),
        ];
    }

    /**
     * TaxContext requires a non-nullable Product, but a subscription has none. The
     * unsaved instance below exists only to satisfy that signature — the metadata type
     * routes the context to SubscriptionTaxCalculator, which never reads the product.
     */
    protected function buildContext(float $amount, array $billing): TaxContext
    {
        return new TaxContext(
            product: new Product(),
            country: $billing['country'] ?? null,
            state: $billing['state'] ?? null,
            city: $billing['city'] ?? null,
            zip_code: $billing['zip_code'] ?? null,
            customer_tax_id: $billing['tax_id'] ?? null,
            quantity: 1,
            price: $amount,
            metadata: ['type' => SubscriptionTaxCalculator::CONTEXT_TYPE],
        );
    }
}
