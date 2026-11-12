<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Models\OrderProduct;
use Botble\Ecommerce\Models\Product;
use Botble\Ecommerce\Models\ProductCategory;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Models\CategoryCommission;
use Botble\Marketplace\Providers\OrderSupportServiceProvider;
use Botble\Setting\Facades\Setting;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;

/**
 * Precedence between a per-category commission rate and a plan's commission override.
 *
 * The decision this encodes: a category rate the admin configured still wins; the plan
 * override only replaces the DEFAULT rate applied to everything else. Nothing else in the
 * suite covers this interaction, and getting it backwards would silently discard the
 * admin's category configuration for every subscribed vendor.
 */
class HybridCategoryCommissionTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSetting('marketplace_enable_commission_fee_for_each_category', 1);
        $this->setSetting('marketplace_fee_per_order', 4.5);
    }

    protected function tearDown(): void
    {
        $this->setSetting('marketplace_mode', MarketplaceModeEnum::COMMISSION);
        Setting::forget('marketplace_fee_per_order');
        Setting::forget('marketplace_enable_commission_fee_for_each_category');
        Setting::save();

        parent::tearDown();
    }

    protected function setSetting(string $key, mixed $value): void
    {
        Setting::load(true);
        Setting::set($key, $value);
        Setting::save();
        Setting::load(true);
    }

    protected function setMode(string $mode): void
    {
        $this->setSetting('marketplace_mode', $mode);
        $this->app->forgetScopedInstances();
    }

    /**
     * One order line for a product priced $price, optionally in a category carrying
     * $categoryRate percent commission.
     */
    protected function orderLine(float $price, ?float $categoryRate = null): OrderProduct
    {
        $product = Product::query()->create([
            'name' => 'Product ' . uniqid(),
            'sku' => 'sku-' . uniqid(),
            'price' => $price,
        ]);

        if ($categoryRate !== null) {
            $category = ProductCategory::query()->create(['name' => 'Cat ' . uniqid()]);
            $product->categories()->attach($category->getKey());

            CategoryCommission::query()->create([
                'product_category_id' => $category->getKey(),
                'commission_percentage' => $categoryRate,
            ]);
        }

        return OrderProduct::query()->create([
            'order_id' => 0,
            'product_id' => $product->getKey(),
            'product_name' => $product->name,
            'qty' => 1,
            'price' => $price,
        ]);
    }

    /**
     * Invoke the real protected calculator. $defaultPercentage is what Phase 03 added:
     * null keeps the global setting, a value is the vendor's resolved plan rate.
     */
    protected function calculate(EloquentCollection $lines, ?float $defaultPercentage = null): float
    {
        $reflection = new ReflectionClass(OrderSupportServiceProvider::class);
        $method = $reflection->getMethod('calculatorCommissionFeeByProduct');
        $method->setAccessible(true);

        return (float) $method->invoke(
            $reflection->newInstanceWithoutConstructor(),
            $lines,
            $defaultPercentage
        );
    }

    public function test_category_rate_wins_over_the_plan_override(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);

        // Product sits in a 10% category; the vendor's plan says 2%.
        $lines = new EloquentCollection([$this->orderLine(100.00, 10.0)]);

        // 10% of 100 - the category rate, not the plan's 2%.
        $this->assertEqualsWithDelta(10.00, $this->calculate($lines, 2.0), 0.0001);
    }

    public function test_plan_override_applies_where_no_category_rate_is_configured(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);

        // No category, so the default rate applies - and in hybrid the default is the plan's.
        $lines = new EloquentCollection([$this->orderLine(100.00)]);

        $this->assertEqualsWithDelta(2.00, $this->calculate($lines, 2.0), 0.0001);
    }

    public function test_global_rate_applies_when_no_plan_override_is_passed(): void
    {
        $this->setMode(MarketplaceModeEnum::COMMISSION);

        $lines = new EloquentCollection([$this->orderLine(100.00)]);

        // null => fall back to the global fee_per_order of 4.5%, exactly as before hybrid.
        $this->assertEqualsWithDelta(4.50, $this->calculate($lines), 0.0001);
    }

    public function test_mixed_basket_charges_each_line_on_its_own_rate(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);

        $lines = new EloquentCollection([
            $this->orderLine(100.00, 10.0),  // category rate: 10.00
            $this->orderLine(100.00),        // plan rate:      2.00
        ]);

        $this->assertEqualsWithDelta(12.00, $this->calculate($lines, 2.0), 0.0001);
    }

    /**
     * A plan that pays no percentage must still not escape a configured category rate.
     */
    public function test_zero_plan_override_does_not_defeat_a_category_rate(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);

        $lines = new EloquentCollection([$this->orderLine(100.00, 10.0)]);

        $this->assertEqualsWithDelta(10.00, $this->calculate($lines, 0.0), 0.0001);
    }

    public function test_zero_plan_override_charges_nothing_where_no_category_rate_exists(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);

        $lines = new EloquentCollection([$this->orderLine(100.00)]);

        $this->assertEqualsWithDelta(0.0, $this->calculate($lines, 0.0), 0.0001);
    }

    /**
     * The guard flipped from isSubscriptionMode() to ! shouldChargeCommission() in Phase 03;
     * subscription mode must still short-circuit to zero before touching category rates.
     */
    public function test_subscription_mode_returns_zero_despite_category_rates(): void
    {
        $this->setMode(MarketplaceModeEnum::SUBSCRIPTION);

        $lines = new EloquentCollection([$this->orderLine(100.00, 10.0)]);

        $this->assertEqualsWithDelta(0.0, $this->calculate($lines, 2.0), 0.0001);
    }

    public function test_hybrid_mode_does_not_short_circuit(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);

        $lines = new EloquentCollection([$this->orderLine(100.00, 10.0)]);

        $this->assertGreaterThan(0.0, $this->calculate($lines, 2.0));
    }
}
