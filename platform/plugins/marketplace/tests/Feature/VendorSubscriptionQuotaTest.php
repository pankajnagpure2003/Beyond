<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Models\Customer;
use Botble\Ecommerce\Models\Product;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Enums\StoreStatusEnum;
use Botble\Marketplace\Exceptions\ProductLimitExceededException;
use Botble\Marketplace\Models\Scopes\HideProductsByLockedVendorScope;
use Botble\Marketplace\Models\Store;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Services\SubscribeVendorService;
use Botble\Marketplace\Services\VendorSubscriptionService;
use Botble\Setting\Facades\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The plan's product allowance, enforced at the point every creation path converges on.
 *
 * Before this guard the CSV importer had no quota check at all — its route gates the
 * allow_product_import feature flag, not the count — so a vendor on an import-enabled plan
 * could exceed their limit at will.
 */
class VendorSubscriptionQuotaTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('marketplace_mode', MarketplaceModeEnum::SUBSCRIPTION)->save();
        Setting::set('marketplace_verify_vendor', 0)->save();
    }

    protected function tearDown(): void
    {
        // Settings are a process-wide singleton RefreshDatabase does not roll back.
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();
        Setting::save();

        parent::tearDown();
    }

    protected function createVendor(): Customer
    {
        $customer = Customer::query()->create([
            'name' => 'Quota Vendor',
            'email' => 'quota-vendor-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        // is_vendor is a marketplace-added column outside Customer's $fillable.
        $customer->is_vendor = true;
        $customer->save();

        Store::query()->create([
            'name' => 'Quota Store',
            'email' => 'quota-store-' . uniqid() . '@example.com',
            'customer_id' => $customer->id,
            'status' => StoreStatusEnum::PUBLISHED,
        ]);

        return $customer->refresh();
    }

    protected function subscribe(Customer $vendor, int $productLimit): void
    {
        $plan = new SubscriptionPlan([
            'name' => 'Quota Plan',
            'price' => 0,
            'duration_value' => 1,
            'duration_unit' => 'month',
            'status' => BaseStatusEnum::PUBLISHED,
        ]);

        $plan->fillOptions(array_merge(SubscriptionPlan::defaultOptions(), [
            'product_limit' => $productLimit,
        ]));
        $plan->save();

        $service = app(SubscribeVendorService::class);
        $service->activate($service->claim($vendor, $plan));
    }

    protected function createProduct(Customer $vendor, array $attributes = []): Product
    {
        $product = new Product(array_merge([
            'name' => 'Product ' . uniqid(),
            'status' => BaseStatusEnum::PUBLISHED,
            'is_variation' => false,
        ], $attributes));

        // store_id is a marketplace-added column outside Product's $fillable.
        $product->store_id = $vendor->store?->getKey();
        $product->save();

        return $product;
    }

    public function test_creating_beyond_the_plan_limit_is_refused(): void
    {
        $vendor = $this->createVendor();
        $this->subscribe($vendor, 2);

        $this->createProduct($vendor);
        $this->createProduct($vendor);

        $this->expectException(ProductLimitExceededException::class);

        $this->createProduct($vendor);
    }

    public function test_variations_do_not_consume_product_slots(): void
    {
        $vendor = $this->createVendor();
        $this->subscribe($vendor, 1);

        $parent = $this->createProduct($vendor);

        // Three variations of an at-limit product must all save.
        foreach (range(1, 3) as $ignored) {
            $this->createProduct($vendor, ['is_variation' => true]);
        }

        $this->assertSame(1, app(VendorSubscriptionService::class)->usedProductSlots($vendor));
        $this->assertTrue($parent->exists);
    }

    public function test_a_product_without_a_store_is_never_blocked(): void
    {
        $vendor = $this->createVendor();
        $this->subscribe($vendor, 1);
        $this->createProduct($vendor);

        // The fence protecting admin-created products, seeders and the rest of the suite:
        // both the admin screen and the vendor UI save before assigning store_id.
        $product = Product::query()->create([
            'name' => 'Admin Product',
            'status' => BaseStatusEnum::PUBLISHED,
            'is_variation' => false,
        ]);

        $this->assertTrue($product->exists);
    }

    public function test_the_guard_is_inert_in_commission_mode(): void
    {
        $vendor = $this->createVendor();
        $this->subscribe($vendor, 1);
        $this->createProduct($vendor);

        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();

        $this->assertTrue($this->createProduct($vendor)->exists);
    }

    public function test_unlimited_plans_never_block(): void
    {
        $vendor = $this->createVendor();
        $this->subscribe($vendor, -1);

        foreach (range(1, 5) as $ignored) {
            $this->createProduct($vendor);
        }

        $this->assertSame(5, app(VendorSubscriptionService::class)->usedProductSlots($vendor));
    }

    /**
     * The second bypass: HideProductsByLockedVendorScope filters to published stores, so
     * an unpublished vendor counted 0 used slots and had an effectively unlimited quota.
     *
     * The scope is registered only when ! runningInConsole(), and phpunit *is* console —
     * so it must be added by hand here. Without that this test passes with or without the
     * fix and proves nothing.
     */
    public function test_quota_counts_products_of_an_unpublished_store(): void
    {
        $vendor = $this->createVendor();
        $this->subscribe($vendor, 2);

        $this->createProduct($vendor);
        $this->createProduct($vendor);

        $store = $vendor->store;
        $store->status = StoreStatusEnum::PENDING;
        $store->save();

        Product::addGlobalScope(HideProductsByLockedVendorScope::class);

        try {
            $service = app(VendorSubscriptionService::class);
            $service->forget($vendor);

            $this->assertSame(2, $service->usedProductSlots($vendor->refresh()));
            $this->assertFalse($service->canCreateProduct($vendor));
        } finally {
            // Global scopes live on the model class, not the container.
            Product::clearBootedModels();
        }
    }
}
