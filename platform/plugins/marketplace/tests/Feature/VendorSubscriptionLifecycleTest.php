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
use Botble\Marketplace\Services\ManageVendorSubscriptionService;
use Botble\Marketplace\Services\SubscribeVendorService;
use Botble\Marketplace\Services\VendorSubscriptionService;
use Botble\Setting\Facades\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;

class VendorSubscriptionLifecycleTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setMode(MarketplaceModeEnum::SUBSCRIPTION);
    }

    protected function tearDown(): void
    {
        // The settings store is a singleton that outlives RefreshDatabase's rollback, so
        // a mode or fee left behind here leaks into unrelated tests in the same process.
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();
        Setting::forget('marketplace_fee_per_order');
        Setting::forget('marketplace_fee_per_order_fixed');
        Setting::forget('marketplace_subscription_unpublish_products_on_expired');
        Setting::forget('marketplace_subscription_grace_period_days');
        Setting::save();

        parent::tearDown();
    }

    protected function setMode(string $mode): void
    {
        Setting::set('marketplace_mode', $mode)->save();
    }

    protected function createVendor(): Customer
    {
        $customer = Customer::query()->create([
            'name' => 'Subscription Vendor',
            'email' => 'vendor-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        // is_vendor is a marketplace-added column and is not in Customer's $fillable,
        // so mass assignment silently drops it and the vendor guard rejects the user.
        $customer->is_vendor = true;
        $customer->save();

        Store::query()->create([
            'name' => 'Subscription Store',
            'email' => 'store-' . uniqid() . '@example.com',
            'customer_id' => $customer->id,
            'status' => StoreStatusEnum::PUBLISHED,
        ]);

        return $customer->refresh();
    }

    protected function createPlan(array $attributes = [], array $options = []): SubscriptionPlan
    {
        $plan = new SubscriptionPlan(array_merge([
            'name' => 'Starter',
            'price' => 19,
            'duration_value' => 1,
            'duration_unit' => 'month',
            'status' => BaseStatusEnum::PUBLISHED,
        ], $attributes));

        $plan->fillOptions(array_merge(SubscriptionPlan::defaultOptions(), $options));
        $plan->save();

        return $plan;
    }

    protected function createProductForVendor(Customer $vendor, string $status = BaseStatusEnum::PUBLISHED): Product
    {
        $product = Product::query()->create([
            'name' => 'Product ' . uniqid(),
            'price' => 10,
        ]);

        // Neither store_id (a marketplace-added column) nor status is in Product's
        // $fillable, so mass assignment silently drops them.
        $product->store_id = $vendor->store->id;
        $product->status = $status;
        $product->save();

        return $product;
    }

    public function test_claiming_a_plan_creates_a_pending_subscription(): void
    {
        $vendor = $this->createVendor();
        $plan = $this->createPlan();

        $subscription = app(SubscribeVendorService::class)->claim($vendor, $plan);

        $this->assertTrue($subscription->isPending());
        $this->assertNull($subscription->starts_at);
        $this->assertDatabaseHas('mp_vendor_subscription_logs', [
            'vendor_subscription_id' => $subscription->getKey(),
            'type' => VendorSubscriptionLog::TYPE_CLAIMED,
        ]);
    }

    public function test_admin_approval_activates_the_subscription_and_logs_it(): void
    {
        $vendor = $this->createVendor();
        $plan = $this->createPlan();

        $subscription = app(SubscribeVendorService::class)->claim($vendor, $plan);
        $subscription = app(ManageVendorSubscriptionService::class)->approve($subscription);

        $this->assertTrue($subscription->isActive());
        $this->assertNotNull($subscription->starts_at);
        $this->assertNotNull($subscription->ends_at);
        $this->assertDatabaseHas('mp_vendor_subscription_logs', [
            'vendor_subscription_id' => $subscription->getKey(),
            'type' => VendorSubscriptionLog::TYPE_ADMIN_APPROVED,
        ]);
    }

    public function test_rejection_requires_a_reason_and_blocks_later_approval(): void
    {
        $vendor = $this->createVendor();
        $plan = $this->createPlan();
        $manage = app(ManageVendorSubscriptionService::class);

        $subscription = app(SubscribeVendorService::class)->claim($vendor, $plan);
        $subscription = $manage->reject($subscription, 'Payment never arrived');

        $this->assertSame(SubscriptionStatusEnum::REJECTED, $subscription->status->getValue());
        $this->assertSame('Payment never arrived', $subscription->rejected_reason);

        $this->expectException(LogicException::class);
        $manage->approve($subscription->refresh());
    }

    public function test_activating_a_new_plan_expires_the_previous_one(): void
    {
        $vendor = $this->createVendor();
        $subscribe = app(SubscribeVendorService::class);

        $first = $subscribe->activate($subscribe->claim($vendor, $this->createPlan(['name' => 'First'])));
        $second = $subscribe->activate($subscribe->claim($vendor, $this->createPlan(['name' => 'Second'])));

        $this->assertSame(SubscriptionStatusEnum::EXPIRED, $first->refresh()->status->getValue());
        $this->assertTrue($second->isActive());
        $this->assertSame(
            1,
            VendorSubscription::query()
                ->where('customer_id', $vendor->getKey())
                ->where('status', SubscriptionStatusEnum::ACTIVE)
                ->count()
        );
    }

    public function test_a_vendor_with_no_subscription_lazily_receives_the_default_free_plan(): void
    {
        $vendor = $this->createVendor();
        $this->createPlan(['name' => 'Free', 'price' => 0, 'is_default' => true], ['product_limit' => 2]);

        $subscription = app(VendorSubscriptionService::class)->current($vendor);

        $this->assertNotNull($subscription);
        $this->assertSame('Free', $subscription->planName());
        $this->assertTrue($subscription->isActive());
    }

    public function test_no_subscription_rows_are_created_in_commission_mode(): void
    {
        $this->setMode(MarketplaceModeEnum::COMMISSION);

        $vendor = $this->createVendor();
        $this->createPlan(['name' => 'Free', 'price' => 0, 'is_default' => true]);

        $this->assertNull(app(VendorSubscriptionService::class)->current($vendor));
        $this->assertDatabaseCount('mp_vendor_subscriptions', 0);
    }

    public function test_editing_a_plan_does_not_change_an_active_subscription(): void
    {
        $vendor = $this->createVendor();
        $plan = $this->createPlan(['price' => 19], ['product_limit' => 5]);
        $subscribe = app(SubscribeVendorService::class);

        $subscription = $subscribe->activate($subscribe->claim($vendor, $plan));

        $plan->fillOptions(['product_limit' => 1]);
        $plan->price = 99;
        $plan->save();

        $this->assertSame(5, $subscription->refresh()->option('product_limit'));
        $this->assertSame(19.0, $subscription->amount);
    }

    public function test_only_one_plan_can_be_the_default(): void
    {
        $first = $this->createPlan(['name' => 'Free A', 'price' => 0, 'is_default' => true]);
        $second = $this->createPlan(['name' => 'Free B', 'price' => 0, 'is_default' => true]);

        $this->assertFalse($first->refresh()->is_default);
        $this->assertTrue($second->refresh()->is_default);
    }

    public function test_product_quota_blocks_creation_once_the_limit_is_reached(): void
    {
        $vendor = $this->createVendor();
        $plan = $this->createPlan([], ['product_limit' => 2]);
        $subscribe = app(SubscribeVendorService::class);
        $reader = app(VendorSubscriptionService::class);

        $subscribe->activate($subscribe->claim($vendor, $plan));

        $this->createProductForVendor($vendor);
        $this->assertTrue($reader->canCreateProduct($vendor));

        $this->createProductForVendor($vendor);
        $reader->forget($vendor);

        $this->assertSame(2, $reader->usedProductSlots($vendor));
        $this->assertSame(0, $reader->remainingProductSlots($vendor));
        $this->assertFalse($reader->canCreateProduct($vendor));
    }

    public function test_unlimited_plans_never_block_creation(): void
    {
        $vendor = $this->createVendor();
        $plan = $this->createPlan([], ['product_limit' => -1]);
        $subscribe = app(SubscribeVendorService::class);

        $subscribe->activate($subscribe->claim($vendor, $plan));
        $this->createProductForVendor($vendor);

        $reader = app(VendorSubscriptionService::class);

        $this->assertNull($reader->productLimit($vendor));
        $this->assertNull($reader->remainingProductSlots($vendor));
        $this->assertTrue($reader->canCreateProduct($vendor));
    }

    public function test_quota_is_not_enforced_in_commission_mode(): void
    {
        $vendor = $this->createVendor();
        $this->createProductForVendor($vendor);

        $this->setMode(MarketplaceModeEnum::COMMISSION);

        $this->assertTrue(app(VendorSubscriptionService::class)->canCreateProduct($vendor));
    }

    public function test_expiry_unpublishes_products_and_renewal_restores_exactly_those(): void
    {
        $vendor = $this->createVendor();
        $plan = $this->createPlan();
        $subscribe = app(SubscribeVendorService::class);

        $subscription = $subscribe->activate($subscribe->claim($vendor, $plan));

        $published = $this->createProductForVendor($vendor);
        $draft = $this->createProductForVendor($vendor, BaseStatusEnum::DRAFT);

        $subscribe->expire($subscription);

        $this->assertSame(BaseStatusEnum::DRAFT, $published->refresh()->status->getValue());
        $this->assertNotNull($published->unpublished_by_subscription_at);
        // The vendor's own draft must not be swept up in the restore later.
        $this->assertNull($draft->refresh()->unpublished_by_subscription_at);

        $subscribe->activate($subscribe->claim($vendor, $plan));

        $this->assertSame(BaseStatusEnum::PUBLISHED, $published->refresh()->status->getValue());
        $this->assertNull($published->unpublished_by_subscription_at);
        $this->assertSame(BaseStatusEnum::DRAFT, $draft->refresh()->status->getValue());
    }

    public function test_expiry_leaves_products_alone_when_the_setting_is_off(): void
    {
        Setting::set('marketplace_subscription_unpublish_products_on_expired', 0)->save();

        $vendor = $this->createVendor();
        $plan = $this->createPlan();
        $subscribe = app(SubscribeVendorService::class);

        $subscription = $subscribe->activate($subscribe->claim($vendor, $plan));
        $product = $this->createProductForVendor($vendor);

        $subscribe->expire($subscription);

        $this->assertSame(BaseStatusEnum::PUBLISHED, $product->refresh()->status->getValue());
    }

    public function test_renewal_continues_from_the_previous_end_date(): void
    {
        $vendor = $this->createVendor();
        $plan = $this->createPlan(['duration_value' => 1, 'duration_unit' => 'month']);
        $subscribe = app(SubscribeVendorService::class);

        $subscription = $subscribe->activate($subscribe->claim($vendor, $plan));
        $endsAt = Carbon::now()->addDays(5)->startOfSecond();
        $subscription->fill(['ends_at' => $endsAt])->save();

        // Renewing a week "late" must not cost the vendor the unused days.
        $renewal = $subscribe->createRenewal($subscription->refresh(), VendorSubscriptionLog::TYPE_RENEWED);

        $this->assertSame($endsAt->toDateString(), $renewal->starts_at->toDateString());
        $this->assertSame($endsAt->copy()->addMonthNoOverflow()->toDateString(), $renewal->ends_at->toDateString());
        $this->assertSame($subscription->getKey(), $renewal->renewed_from_id);
    }

    public function test_grace_period_keeps_an_expired_subscription_usable(): void
    {
        Setting::set('marketplace_subscription_grace_period_days', 3)->save();

        $vendor = $this->createVendor();
        $plan = $this->createPlan();
        $subscribe = app(SubscribeVendorService::class);

        $subscription = $subscribe->activate($subscribe->claim($vendor, $plan));
        $subscription->fill(['ends_at' => Carbon::now()->subDay()])->save();

        $reader = app(VendorSubscriptionService::class);

        $this->assertNotNull($reader->current($vendor));

        $subscription->fill(['ends_at' => Carbon::now()->subDays(5)])->save();
        $reader->forget($vendor);

        $this->assertNull($reader->current($vendor));
    }

    public function test_downgrading_only_republishes_what_the_smaller_plan_allows(): void
    {
        $vendor = $this->createVendor();
        $big = $this->createPlan(['name' => 'Big'], ['product_limit' => -1]);
        $small = $this->createPlan(['name' => 'Small'], ['product_limit' => 2]);
        $subscribe = app(SubscribeVendorService::class);

        $subscription = $subscribe->activate($subscribe->claim($vendor, $big));

        for ($i = 0; $i < 5; $i++) {
            $this->createProductForVendor($vendor);
        }

        $subscribe->expire($subscription);
        $this->assertSame(0, $this->publishedCount($vendor));

        // Dropping to a 2-product plan must not silently restore all five.
        $subscribe->activate($subscribe->claim($vendor, $small));

        $this->assertSame(2, $this->publishedCount($vendor));
        $this->assertSame(3, $this->stampedCount($vendor));
    }

    public function test_upgrading_again_restores_the_products_a_downgrade_held_back(): void
    {
        $vendor = $this->createVendor();
        $small = $this->createPlan(['name' => 'Small'], ['product_limit' => 2]);
        $unlimited = $this->createPlan(['name' => 'Unlimited'], ['product_limit' => -1]);
        $subscribe = app(SubscribeVendorService::class);

        $subscription = $subscribe->activate($subscribe->claim($vendor, $unlimited));

        for ($i = 0; $i < 5; $i++) {
            $this->createProductForVendor($vendor);
        }

        $subscribe->expire($subscription);
        $downgraded = $subscribe->activate($subscribe->claim($vendor, $small));
        $this->assertSame(2, $this->publishedCount($vendor));

        $subscribe->expire($downgraded);
        $subscribe->activate($subscribe->claim($vendor, $unlimited));

        $this->assertSame(5, $this->publishedCount($vendor));
        $this->assertSame(0, $this->stampedCount($vendor));
    }

    protected function publishedCount(Customer $vendor): int
    {
        return Product::query()
            ->where('store_id', $vendor->store->id)
            ->where('status', BaseStatusEnum::PUBLISHED)
            ->count();
    }

    protected function stampedCount(Customer $vendor): int
    {
        return Product::query()
            ->where('store_id', $vendor->store->id)
            ->whereNotNull('unpublished_by_subscription_at')
            ->count();
    }
}
