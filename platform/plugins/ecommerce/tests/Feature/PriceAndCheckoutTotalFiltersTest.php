<?php

namespace Botble\Ecommerce\Tests\Feature;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Facades\Cart;
use Botble\Ecommerce\Models\Product;
use Botble\Ecommerce\Services\HandleCheckoutOrderData;
use Botble\Ecommerce\Services\Products\ProductPriceHandlerService;
use Botble\Setting\Facades\Setting;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Extension points requested by plugin developers who otherwise have to fork the
 * ecommerce plugin to adjust prices or checkout totals:
 *
 *   - ecommerce_product_price_handlers   register a pipeline handler
 *   - ecommerce_product_final_price      adjust the resolved sale price
 *   - ecommerce_product_original_price   adjust the resolved original price
 *   - ecommerce_checkout_order_total     adjust the total shipping is calculated from
 *   - ecommerce_order_amount             adjust the final payable amount
 */
class PriceAndCheckoutTotalFiltersTest extends BaseTestCase
{
    use RefreshDatabase;

    protected array $registeredFilters = [];

    protected ?string $originalActivatedPlugins = null;

    protected function setUp(): void
    {
        parent::setUp();

        Cart::instance('cart')->destroy();
    }

    protected function tearDown(): void
    {
        foreach ($this->registeredFilters as $hook) {
            remove_filter($hook);
        }

        $this->registeredFilters = [];

        $this->restoreActivatedPlugins();

        Cart::instance('cart')->destroy();

        parent::tearDown();
    }

    protected function filter(string $hook, Closure $callback, int $priority = 10, int $arguments = 2): void
    {
        $this->registeredFilters[] = $hook;

        add_filter($hook, $callback, $priority, $arguments);
    }

    protected function createProduct(array $attributes = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => 'Filter Test Product',
            'price' => 100,
            'status' => BaseStatusEnum::PUBLISHED,
            'is_variation' => false,
        ], $attributes));
    }

    public function test_final_price_filter_adjusts_resolved_price(): void
    {
        $product = $this->createProduct();

        $baseline = (float) Product::query()->find($product->getKey())->front_sale_price;

        $this->filter('ecommerce_product_final_price', fn (float $price) => $price - 25);

        $this->assertEquals(
            $baseline - 25,
            (float) Product::query()->find($product->getKey())->front_sale_price
        );
    }

    public function test_original_price_filter_adjusts_resolved_price(): void
    {
        $product = $this->createProduct();

        $baseline = (float) Product::query()->find($product->getKey())->original_price;

        $this->filter('ecommerce_product_original_price', fn (float $price) => $price + 10);

        $this->assertEquals(
            $baseline + 10,
            (float) Product::query()->find($product->getKey())->original_price
        );
    }

    public function test_price_handlers_filter_registers_an_extra_pipeline_handler(): void
    {
        $product = $this->createProduct();

        $this->filter('ecommerce_product_price_handlers', function (array $handlers) {
            $handlers[] = new class () extends ProductPriceHandlerService {
                public function handle(Product $product, Closure $next)
                {
                    $product->setFinalPrice($product->getFinalPrice() / 2);

                    return $next($product);
                }
            };

            return $handlers;
        });

        $this->assertEquals(
            50,
            (float) Product::query()->find($product->getKey())->front_sale_price
        );
    }

    public function test_price_filters_receive_the_product(): void
    {
        $product = $this->createProduct();
        $received = null;

        $this->filter('ecommerce_product_final_price', function (float $price, Product $filtered) use (&$received) {
            $received = $filtered->getKey();

            return $price;
        });

        Product::query()->find($product->getKey())->front_sale_price;

        $this->assertSame($product->getKey(), $received);
    }

    public function test_order_amount_filter_adjusts_the_payable_amount(): void
    {
        $product = $this->createProduct(['price' => 200]);

        Cart::instance('cart')->add($product->getKey(), $product->name, 1, 200, ['product' => $product]);

        $unfiltered = $this->runCheckout()->orderAmount;

        $this->assertGreaterThan(0, $unfiltered);

        $this->filter('ecommerce_order_amount', fn (float $amount) => $amount - 30);

        $this->assertEquals($unfiltered - 30, $this->runCheckout()->orderAmount);
    }

    public function test_order_amount_filter_cannot_push_the_amount_below_zero(): void
    {
        $product = $this->createProduct(['price' => 50]);

        Cart::instance('cart')->add($product->getKey(), $product->name, 1, 50, ['product' => $product]);

        $this->filter('ecommerce_order_amount', fn (float $amount) => $amount - 10000);

        $this->assertEquals(0, $this->runCheckout()->orderAmount);
    }

    public function test_order_amount_filter_receives_the_documented_context(): void
    {
        $product = $this->createProduct(['price' => 120]);

        Cart::instance('cart')->add($product->getKey(), $product->name, 1, 120, ['product' => $product]);

        $context = null;

        $this->filter('ecommerce_order_amount', function (float $amount, array $received) use (&$context) {
            $context = $received;

            return $amount;
        });

        $this->runCheckout();

        $this->assertIsArray($context);

        foreach ([
            'raw_total',
            'promotion_discount_amount',
            'coupon_discount_amount',
            'shipping_amount',
            'shipping_tax_amount',
            'payment_fee',
            'token',
        ] as $key) {
            $this->assertArrayHasKey($key, $context);
        }
    }

    public function test_checkout_order_total_filter_receives_the_documented_context(): void
    {
        $product = $this->createProduct(['price' => 90]);

        Cart::instance('cart')->add($product->getKey(), $product->name, 1, 90, ['product' => $product]);

        $context = null;

        $this->filter('ecommerce_checkout_order_total', function (float $total, array $received) use (&$context) {
            $context = $received;

            return $total;
        });

        $this->runCheckout();

        $this->assertIsArray($context, 'ecommerce_checkout_order_total did not fire');

        foreach ([
            'raw_total',
            'promotion_discount_amount',
            'coupon_discount_amount',
            'token',
            'store_id',
        ] as $key) {
            $this->assertArrayHasKey($key, $context);
        }
    }

    public function test_checkout_order_total_filter_fires_on_the_single_vendor_checkout(): void
    {
        $this->deactivateMarketplace();

        $product = $this->createProduct(['price' => 90]);

        Cart::instance('cart')->add($product->getKey(), $product->name, 1, 90, ['product' => $product]);

        $context = null;

        $this->filter('ecommerce_checkout_order_total', function (float $total, array $received) use (&$context) {
            $context = $received;

            return $total;
        });

        $this->runCheckout();

        $this->assertIsArray($context, 'ecommerce_checkout_order_total did not fire without marketplace');
        $this->assertNull($context['store_id'], 'store_id must be null outside the marketplace checkout');
        $this->assertEquals(90, $context['raw_total']);
    }

    public function test_checkout_order_total_filter_adjusts_the_total_shipping_is_based_on(): void
    {
        $this->deactivateMarketplace();

        $product = $this->createProduct(['price' => 90]);

        Cart::instance('cart')->add($product->getKey(), $product->name, 1, 90, ['product' => $product]);

        $seen = [];

        $this->filter('ecommerce_checkout_order_total', function (float $total) use (&$seen) {
            $seen[] = $total;

            return $total - 40;
        });

        $this->runCheckout();

        $this->assertSame([90.0], $seen);
    }

    /**
     * PluginService::getActivatedPlugins() skips the cache when READING in console but still
     * WRITES `core_installed_plugins` on every call. Leaving the shortened list behind would
     * make every later test in the process see marketplace as deactivated, so the original
     * value and the cache are both restored in tearDown.
     */
    protected function deactivateMarketplace(): void
    {
        $this->originalActivatedPlugins = (string) Setting::get('activated_plugins');

        $activated = json_decode($this->originalActivatedPlugins ?: '[]', true) ?: [];

        // `activated_plugins` is a guarded key, so the force flag is required.
        Setting::set(
            'activated_plugins',
            json_encode(array_values(array_diff($activated, ['marketplace']))),
            true
        )->save();

        Cache::forget('core_installed_plugins');

        $this->assertFalse(is_plugin_active('marketplace'));
    }

    protected function restoreActivatedPlugins(): void
    {
        if ($this->originalActivatedPlugins === null) {
            return;
        }

        Setting::set('activated_plugins', $this->originalActivatedPlugins, true)->save();

        Cache::forget('core_installed_plugins');

        $this->originalActivatedPlugins = null;

        // Re-populate the static and the cache from the restored setting.
        get_active_plugins();
    }

    protected function runCheckout(): object
    {
        $sessionCheckoutData = [];

        return app(HandleCheckoutOrderData::class)->execute(
            new Request(),
            Cart::instance('cart')->products(),
            'filter-test-token',
            $sessionCheckoutData
        );
    }
}
