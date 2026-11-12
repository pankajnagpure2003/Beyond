<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Models\Customer;
use Botble\Ecommerce\Models\Product;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Enums\StoreStatusEnum;
use Botble\Marketplace\Enums\SubscriptionStatusEnum;
use Botble\Marketplace\Models\Store;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Models\VendorSubscriptionLog;
use Botble\Marketplace\Services\CancelVendorSubscriptionService;
use Botble\Marketplace\Services\SubscribeVendorService;
use Botble\Setting\Facades\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

/**
 * Covers the two vendor-initiated changes to a live subscription: switching plan (which
 * forfeits the remaining time) and cancelling outright.
 */
class VendorSubscriptionChangePlanTest extends BaseTestCase
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
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();
        Setting::forget('marketplace_subscription_allow_vendor_cancel');
        Setting::forget('marketplace_subscription_unpublish_products_on_expired');
        Setting::save();

        parent::tearDown();
    }

    protected function createVendor(): Customer
    {
        $customer = Customer::query()->create([
            'name' => 'Change Vendor',
            'email' => 'change-vendor-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $customer->is_vendor = true;
        $customer->save();

        Store::query()->create([
            'name' => 'Change Store',
            'email' => 'change-store-' . uniqid() . '@example.com',
            'customer_id' => $customer->id,
            'status' => StoreStatusEnum::PUBLISHED,
        ]);

        return $customer->refresh();
    }

    protected function createPlan(string $name, float $price): SubscriptionPlan
    {
        $plan = new SubscriptionPlan([
            'name' => $name,
            'price' => $price,
            'duration_value' => 1,
            'duration_unit' => 'month',
            'status' => BaseStatusEnum::PUBLISHED,
        ]);

        $plan->fillOptions(SubscriptionPlan::defaultOptions());
        $plan->save();

        return $plan;
    }

    public function test_changing_plan_ends_the_old_one_and_starts_a_full_new_period(): void
    {
        $vendor = $this->createVendor();
        $starter = $this->createPlan('Starter', 10);
        $pro = $this->createPlan('Pro', 30);

        $service = app(SubscribeVendorService::class);

        $first = $service->activate($service->claim($vendor, $starter));
        $second = $service->activate($service->claim($vendor, $pro));

        $this->assertEquals(SubscriptionStatusEnum::ACTIVE, $second->refresh()->status);
        $this->assertNotEquals(SubscriptionStatusEnum::ACTIVE, $first->refresh()->status);

        // No proration: the new plan runs a whole period from today at its own price.
        $this->assertSame(30.0, (float) $second->amount);
        $this->assertTrue($second->ends_at->isSameDay($second->starts_at->copy()->addMonthNoOverflow()));
    }

    public function test_changing_plan_is_logged_as_a_change_not_a_first_purchase(): void
    {
        $vendor = $this->createVendor();
        $service = app(SubscribeVendorService::class);

        $first = $service->activate($service->claim($vendor, $this->createPlan('Starter', 10)));
        $second = $service->claim($vendor, $this->createPlan('Pro', 30));

        $this->assertDatabaseHas('mp_vendor_subscription_logs', [
            'vendor_subscription_id' => $first->getKey(),
            'type' => VendorSubscriptionLog::TYPE_CLAIMED,
        ]);

        $this->assertDatabaseHas('mp_vendor_subscription_logs', [
            'vendor_subscription_id' => $second->getKey(),
            'type' => VendorSubscriptionLog::TYPE_CHANGED_PLAN,
        ]);

        $this->assertDatabaseMissing('mp_vendor_subscription_logs', [
            'vendor_subscription_id' => $second->getKey(),
            'type' => VendorSubscriptionLog::TYPE_CLAIMED,
        ]);
    }

    public function test_a_vendor_can_cancel_their_subscription(): void
    {
        $vendor = $this->createVendor();
        $service = app(SubscribeVendorService::class);
        $subscription = $service->activate($service->claim($vendor, $this->createPlan('Pro', 30)));

        app(CancelVendorSubscriptionService::class)->handle($vendor);

        $subscription->refresh();

        $this->assertEquals(SubscriptionStatusEnum::CANCELLED, $subscription->status);
        $this->assertNotNull($subscription->cancelled_at);
        $this->assertFalse((bool) $subscription->auto_renew);

        $this->assertDatabaseHas('mp_vendor_subscription_logs', [
            'vendor_subscription_id' => $subscription->getKey(),
            'type' => VendorSubscriptionLog::TYPE_VENDOR_CANCELLED,
        ]);
    }

    public function test_cancelling_keeps_the_row_rather_than_deleting_it(): void
    {
        $vendor = $this->createVendor();
        $service = app(SubscribeVendorService::class);
        $subscription = $service->activate($service->claim($vendor, $this->createPlan('Pro', 30)));

        app(CancelVendorSubscriptionService::class)->handle($vendor);

        $this->assertDatabaseHas('mp_vendor_subscriptions', ['id' => $subscription->getKey()]);
        $this->assertSame(1, VendorSubscription::query()->count());
    }

    public function test_cancelling_unpublishes_products_when_the_setting_is_on(): void
    {
        Setting::set('marketplace_subscription_unpublish_products_on_expired', 1)->save();

        $vendor = $this->createVendor();
        $service = app(SubscribeVendorService::class);
        $service->activate($service->claim($vendor, $this->createPlan('Pro', 30)));

        $product = $this->createProduct($vendor);

        app(CancelVendorSubscriptionService::class)->handle($vendor);

        $this->assertNotEquals(BaseStatusEnum::PUBLISHED, $product->refresh()->status);
        $this->assertNotNull($product->unpublished_by_subscription_at);
    }

    public function test_cancelling_leaves_products_alone_when_the_setting_is_off(): void
    {
        Setting::set('marketplace_subscription_unpublish_products_on_expired', 0)->save();

        $vendor = $this->createVendor();
        $service = app(SubscribeVendorService::class);
        $service->activate($service->claim($vendor, $this->createPlan('Pro', 30)));

        $product = $this->createProduct($vendor);

        app(CancelVendorSubscriptionService::class)->handle($vendor);

        $this->assertEquals(BaseStatusEnum::PUBLISHED, $product->refresh()->status);
    }

    public function test_cancelling_is_refused_when_the_admin_disables_it(): void
    {
        Setting::set('marketplace_subscription_allow_vendor_cancel', 0)->save();

        $vendor = $this->createVendor();
        $service = app(SubscribeVendorService::class);
        $subscription = $service->activate($service->claim($vendor, $this->createPlan('Pro', 30)));

        $this->expectException(RuntimeException::class);

        try {
            app(CancelVendorSubscriptionService::class)->handle($vendor);
        } finally {
            $this->assertEquals(SubscriptionStatusEnum::ACTIVE, $subscription->refresh()->status);
        }
    }

    public function test_cancelling_without_an_active_subscription_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        app(CancelVendorSubscriptionService::class)->handle($this->createVendor());
    }

    public function test_the_cancel_route_requires_the_typed_confirmation(): void
    {
        $vendor = $this->createVendor();
        $service = app(SubscribeVendorService::class);
        $subscription = $service->activate($service->claim($vendor, $this->createPlan('Pro', 30)));

        $this->actingAs($vendor, 'customer')
            ->post(route('marketplace.vendor.subscriptions.cancel'), ['confirmation' => 'nope'])
            ->assertRedirect();

        $this->assertEquals(SubscriptionStatusEnum::ACTIVE, $subscription->refresh()->status);

        $this->actingAs($vendor, 'customer')
            ->post(route('marketplace.vendor.subscriptions.cancel'), [
                'confirmation' => trans('plugins/marketplace::subscription.vendor.cancel_confirm_word'),
            ])
            ->assertRedirect();

        $this->assertEquals(SubscriptionStatusEnum::CANCELLED, $subscription->refresh()->status);
    }

    protected function createProduct(Customer $vendor): Product
    {
        $store = $vendor->store;

        $product = Product::query()->create([
            'name' => 'Vendor Product',
            'status' => BaseStatusEnum::PUBLISHED,
            'is_variation' => 0,
        ]);

        // store_id is a marketplace-added column outside Product's $fillable.
        $product->store_id = $store->getKey();
        $product->save();

        return $product->refresh();
    }
}
