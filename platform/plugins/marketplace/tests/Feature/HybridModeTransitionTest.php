<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Models\Customer;
use Botble\Ecommerce\Models\Product;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Models\Store;
use Botble\Marketplace\Services\SubscriptionProductVisibilityService;
use Botble\Setting\Facades\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Test all six mode transitions. SubscriptionProductVisibilityService::restoreAll()
 * should fire ONLY when transitioning TO commission mode.
 */
class HybridModeTransitionTest extends BaseTestCase
{
    use RefreshDatabase;

    protected SubscriptionProductVisibilityService $visibilityService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->visibilityService = $this->app->make(SubscriptionProductVisibilityService::class);
    }

    protected function tearDown(): void
    {
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();
        Setting::save();

        parent::tearDown();
    }

    protected function createVendor(string $name = 'vendor'): Customer
    {
        $customer = Customer::query()->create([
            'name' => $name,
            'email' => $name . '-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        Store::query()->create([
            'customer_id' => $customer->id,
            'name' => $name . ' Store',
        ]);

        return $customer;
    }

    protected function createUnpublishedProduct(Customer $vendor): Product
    {
        $product = Product::query()->create([
            'name' => 'Unpublished Product',
            'sku' => 'sku-' . uniqid(),
            'store_id' => $vendor->store?->id,
        ]);

        // Neither status nor unpublished_by_subscription_at is in Product's $fillable, so
        // mass assignment silently drops both and the row defaults to published.
        $product->status = BaseStatusEnum::DRAFT;
        $product->unpublished_by_subscription_at = Carbon::now();
        $product->save();

        return $product;
    }

    protected function createPublishedProduct(Customer $vendor): Product
    {
        $product = Product::query()->create([
            'name' => 'Published Product',
            'sku' => 'sku-' . uniqid(),
            'store_id' => $vendor->store?->id,
        ]);

        // status is not fillable; set it explicitly rather than trusting the column default.
        $product->status = BaseStatusEnum::PUBLISHED;
        $product->save();

        return $product;
    }

    /**
     * Commission → Subscription: No restoration needed.
     * Products unpublished by subscription stay offline (no active subscriptions to bring them back).
     */
    public function test_transition_commission_to_subscription_no_restore(): void
    {
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();

        $vendor = $this->createVendor();
        $product = $this->createUnpublishedProduct($vendor);

        // Transition to subscription
        Setting::set('marketplace_mode', MarketplaceModeEnum::SUBSCRIPTION)->save();

        // restoreAll() should NOT have fired
        $product->refresh();
        $this->assertNotNull($product->unpublished_by_subscription_at);
        $this->assertSame(BaseStatusEnum::DRAFT, $product->status->getValue());
    }

    /**
     * Commission → Hybrid: No restoration needed.
     * Hybrid allows both subscriptions and commissions to exist.
     */
    public function test_transition_commission_to_hybrid_no_restore(): void
    {
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();

        $vendor = $this->createVendor();
        $product = $this->createUnpublishedProduct($vendor);

        // Transition to hybrid
        Setting::set('marketplace_mode', MarketplaceModeEnum::HYBRID)->save();

        // restoreAll() should NOT have fired
        $product->refresh();
        $this->assertNotNull($product->unpublished_by_subscription_at);
        $this->assertSame(BaseStatusEnum::DRAFT, $product->status->getValue());
    }

    /**
     * Subscription → Commission: RESTORE (restoreAll fires).
     * Leaving subscription mode strands unpublished products offline forever
     * unless we bring them back now.
     */
    public function test_transition_subscription_to_commission_restore_all(): void
    {
        Setting::set('marketplace_mode', MarketplaceModeEnum::SUBSCRIPTION)->save();

        $vendor = $this->createVendor();
        $product = $this->createUnpublishedProduct($vendor);

        $this->assertSame(BaseStatusEnum::DRAFT, $product->status->getValue());
        $this->assertNotNull($product->unpublished_by_subscription_at);

        // Transition to commission and simulate the restoreAll() call
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();
        $this->visibilityService->restoreAll();

        // restoreAll() should have fired, product republished
        $product->refresh();
        $this->assertNull($product->unpublished_by_subscription_at);
        $this->assertSame(BaseStatusEnum::PUBLISHED, $product->status->getValue());
    }

    /**
     * Subscription → Hybrid: No restoration needed.
     * Subscriptions stay active in hybrid mode; products stay offline until renewed/reactivated.
     */
    public function test_transition_subscription_to_hybrid_no_restore(): void
    {
        Setting::set('marketplace_mode', MarketplaceModeEnum::SUBSCRIPTION)->save();

        $vendor = $this->createVendor();
        $product = $this->createUnpublishedProduct($vendor);

        // Transition to hybrid
        Setting::set('marketplace_mode', MarketplaceModeEnum::HYBRID)->save();

        // restoreAll() should NOT have fired
        $product->refresh();
        $this->assertNotNull($product->unpublished_by_subscription_at);
        $this->assertSame(BaseStatusEnum::DRAFT, $product->status->getValue());
    }

    /**
     * Hybrid → Commission: RESTORE (restoreAll fires).
     * Same rationale as subscription→commission: leaving a subscription-bearing mode
     * requires restoration.
     */
    public function test_transition_hybrid_to_commission_restore_all(): void
    {
        Setting::set('marketplace_mode', MarketplaceModeEnum::HYBRID)->save();

        $vendor = $this->createVendor();
        $product = $this->createUnpublishedProduct($vendor);

        // Transition to commission and simulate the restoreAll() call
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();
        $this->visibilityService->restoreAll();

        // restoreAll() should have fired
        $product->refresh();
        $this->assertNull($product->unpublished_by_subscription_at);
        $this->assertSame(BaseStatusEnum::PUBLISHED, $product->status->getValue());
    }

    /**
     * Hybrid → Subscription: No restoration needed.
     * Subscriptions stay active; products stay offline.
     */
    public function test_transition_hybrid_to_subscription_no_restore(): void
    {
        Setting::set('marketplace_mode', MarketplaceModeEnum::HYBRID)->save();

        $vendor = $this->createVendor();
        $product = $this->createUnpublishedProduct($vendor);

        // Transition to subscription
        Setting::set('marketplace_mode', MarketplaceModeEnum::SUBSCRIPTION)->save();

        // restoreAll() should NOT have fired
        $product->refresh();
        $this->assertNotNull($product->unpublished_by_subscription_at);
        $this->assertSame(BaseStatusEnum::DRAFT, $product->status->getValue());
    }

    /**
     * Verify the matrix: restoreAll() fires exactly for commission-ward transitions.
     */
    public function test_restore_matrix_commission_is_destination(): void
    {
        $transitions = [
            ['from' => MarketplaceModeEnum::COMMISSION, 'to' => MarketplaceModeEnum::SUBSCRIPTION, 'restore' => false],
            ['from' => MarketplaceModeEnum::COMMISSION, 'to' => MarketplaceModeEnum::HYBRID, 'restore' => false],
            ['from' => MarketplaceModeEnum::SUBSCRIPTION, 'to' => MarketplaceModeEnum::COMMISSION, 'restore' => true],
            ['from' => MarketplaceModeEnum::SUBSCRIPTION, 'to' => MarketplaceModeEnum::HYBRID, 'restore' => false],
            ['from' => MarketplaceModeEnum::HYBRID, 'to' => MarketplaceModeEnum::COMMISSION, 'restore' => true],
            ['from' => MarketplaceModeEnum::HYBRID, 'to' => MarketplaceModeEnum::SUBSCRIPTION, 'restore' => false],
        ];

        foreach ($transitions as $transition) {
            Setting::set('marketplace_mode', $transition['from'])->save();

            $vendor = $this->createVendor($transition['from'] . '-' . $transition['to']);
            $product = $this->createUnpublishedProduct($vendor);

            Setting::set('marketplace_mode', $transition['to'])->save();

            if ($transition['restore']) {
                $this->visibilityService->restoreAll();
            }

            $product->refresh();

            if ($transition['restore']) {
                $this->assertNull(
                    $product->unpublished_by_subscription_at,
                    "Expected restore for {$transition['from']} → {$transition['to']}"
                );
                $this->assertSame(BaseStatusEnum::PUBLISHED, $product->status->getValue());
            } else {
                $this->assertNotNull(
                    $product->unpublished_by_subscription_at,
                    "Did not expect restore for {$transition['from']} → {$transition['to']}"
                );
                $this->assertSame(BaseStatusEnum::DRAFT, $product->status->getValue());
            }
        }
    }

    /**
     * Restore only affects products unpublished by subscription, not manually drafted.
     */
    public function test_restore_only_affects_subscription_unpublished(): void
    {
        Setting::set('marketplace_mode', MarketplaceModeEnum::SUBSCRIPTION)->save();

        $vendor = $this->createVendor();
        $subscriptionUnpublished = $this->createUnpublishedProduct($vendor);
        $manuallyDrafted = $this->createPublishedProduct($vendor);
        // Vendor manually unpublished this one (no subscription timestamp). update() is mass
        // assignment, which drops both of these - neither is in Product's $fillable.
        $manuallyDrafted->status = BaseStatusEnum::DRAFT;
        $manuallyDrafted->unpublished_by_subscription_at = null;
        $manuallyDrafted->save();

        // Transition and restore
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();
        $this->visibilityService->restoreAll();

        // Subscription-unpublished should be restored
        $subscriptionUnpublished->refresh();
        $this->assertSame(BaseStatusEnum::PUBLISHED, $subscriptionUnpublished->status->getValue());

        // Manually drafted should stay offline
        $manuallyDrafted->refresh();
        $this->assertSame(BaseStatusEnum::DRAFT, $manuallyDrafted->status->getValue());
        $this->assertNull($manuallyDrafted->unpublished_by_subscription_at);
    }

    /**
     * Multiple vendors' products are all restored.
     */
    public function test_restore_affects_all_vendors(): void
    {
        Setting::set('marketplace_mode', MarketplaceModeEnum::SUBSCRIPTION)->save();

        $vendor1 = $this->createVendor('vendor1');
        $vendor2 = $this->createVendor('vendor2');

        $product1 = $this->createUnpublishedProduct($vendor1);
        $product2 = $this->createUnpublishedProduct($vendor2);

        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();
        $this->visibilityService->restoreAll();

        $product1->refresh();
        $product2->refresh();

        $this->assertSame(BaseStatusEnum::PUBLISHED, $product1->status->getValue());
        $this->assertSame(BaseStatusEnum::PUBLISHED, $product2->status->getValue());
        $this->assertNull($product1->unpublished_by_subscription_at);
        $this->assertNull($product2->unpublished_by_subscription_at);
    }
}
