<?php

namespace Botble\Marketplace\Providers;

use Botble\Ecommerce\Tax\TaxEngineManager;
use Botble\Marketplace\Tax\SubscriptionTaxCalculator;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the subscription tax calculator on the ecommerce tax engine.
 *
 * Priority sits above DefaultTaxCalculator, whose supports() returns true for
 * everything — without a higher priority the default would swallow our contexts and
 * return a product-based rate of zero.
 */
class SubscriptionTaxServiceProvider extends ServiceProvider
{
    protected const PRIORITY = 100;

    public function boot(): void
    {
        if (! is_plugin_active('ecommerce') || ! class_exists(TaxEngineManager::class)) {
            return;
        }

        $this->app->booted(function (): void {
            $this->app->make(TaxEngineManager::class)->register(
                SubscriptionTaxCalculator::CONTEXT_TYPE,
                $this->app->make(SubscriptionTaxCalculator::class),
                self::PRIORITY,
            );
        });
    }
}
