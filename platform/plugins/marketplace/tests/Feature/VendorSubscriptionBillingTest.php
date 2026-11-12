<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Models\Customer;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Enums\RevenueTypeEnum;
use Botble\Marketplace\Enums\StoreStatusEnum;
use Botble\Marketplace\Enums\SubscriptionStatusEnum;
use Botble\Marketplace\Models\Revenue;
use Botble\Marketplace\Models\Store;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\VendorInfo;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Models\VendorSubscriptionNotification;
use Botble\Marketplace\Services\SubscribeVendorService;
use Botble\Marketplace\Services\SubscriptionBalancePaymentService;
use Botble\Marketplace\Services\SubscriptionRenewalService;
use Botble\Setting\Facades\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

class VendorSubscriptionBillingTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('marketplace_mode', MarketplaceModeEnum::SUBSCRIPTION)->save();

        // The seeded database ships a non-zero grace period (5 days), which would keep a
        // subscription that ended 2 days ago ACTIVE and silently break the expiry tests.
        // tearDown already forgets this key; setUp has to pin it, or the assertions depend
        // on whatever the database happened to be seeded with.
        Setting::set('marketplace_subscription_grace_period_days', 0)->save();
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

    protected function createVendor(float $balance = 0): Customer
    {
        $customer = Customer::query()->create([
            'name' => 'Billing Vendor',
            'email' => 'billing-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        // is_vendor is a marketplace-added column and is not in Customer's $fillable,
        // so mass assignment silently drops it and the vendor guard rejects the user.
        $customer->is_vendor = true;
        $customer->save();

        Store::query()->create([
            'name' => 'Billing Store',
            'email' => 'store-' . uniqid() . '@example.com',
            'customer_id' => $customer->id,
            'status' => StoreStatusEnum::PUBLISHED,
        ]);

        VendorInfo::query()->create([
            'customer_id' => $customer->id,
            'balance' => $balance,
        ]);

        return $customer->refresh();
    }

    protected function createPlan(float $price = 19, array $attributes = []): SubscriptionPlan
    {
        return SubscriptionPlan::query()->create(array_merge([
            'name' => 'Starter',
            'price' => $price,
            'duration_value' => 1,
            'duration_unit' => 'month',
            'status' => BaseStatusEnum::PUBLISHED,
        ], $attributes));
    }

    public function test_paying_from_balance_debits_the_wallet_and_records_a_revenue_row(): void
    {
        $vendor = $this->createVendor(100);
        $plan = $this->createPlan(19);

        $subscription = app(SubscribeVendorService::class)->claim($vendor, $plan);

        $this->assertTrue(app(SubscriptionBalancePaymentService::class)->pay($subscription));

        $this->assertSame(81.0, (float) VendorInfo::query()->where('customer_id', $vendor->getKey())->value('balance'));

        $revenue = Revenue::query()->where('customer_id', $vendor->getKey())->latest('id')->first();

        $this->assertSame(RevenueTypeEnum::SUBSCRIPTION_FEE, $revenue->type->getValue());
        $this->assertSame(-19.0, (float) $revenue->amount);
    }

    public function test_paying_from_balance_fails_without_touching_the_wallet(): void
    {
        $vendor = $this->createVendor(5);
        $plan = $this->createPlan(19);

        $subscription = app(SubscribeVendorService::class)->claim($vendor, $plan);

        $this->assertFalse(app(SubscriptionBalancePaymentService::class)->pay($subscription));
        $this->assertSame(5.0, (float) VendorInfo::query()->where('customer_id', $vendor->getKey())->value('balance'));
        $this->assertSame(0, Revenue::query()->where('customer_id', $vendor->getKey())->count());
    }

    public function test_auto_renew_charges_the_balance_and_creates_the_successor(): void
    {
        $vendor = $this->createVendor(100);
        $plan = $this->createPlan(19);
        $subscribe = app(SubscribeVendorService::class);

        $subscription = $subscribe->activate($subscribe->claim($vendor, $plan, ['auto_renew' => true]));
        $subscription->fill(['ends_at' => Carbon::now()->addDay()])->save();

        $this->assertTrue(app(SubscriptionRenewalService::class)->autoRenew($subscription->refresh()));

        $renewal = VendorSubscription::query()->where('renewed_from_id', $subscription->getKey())->first();

        $this->assertNotNull($renewal);
        $this->assertTrue($renewal->isActive());
        $this->assertTrue($renewal->auto_renew);
        $this->assertSame(81.0, (float) VendorInfo::query()->where('customer_id', $vendor->getKey())->value('balance'));
        $this->assertSame(SubscriptionStatusEnum::EXPIRED, $subscription->refresh()->status->getValue());
    }

    public function test_auto_renew_is_skipped_when_the_balance_is_short(): void
    {
        $vendor = $this->createVendor(5);
        $plan = $this->createPlan(19);
        $subscribe = app(SubscribeVendorService::class);

        $subscription = $subscribe->activate($subscribe->claim($vendor, $plan, ['auto_renew' => true]));
        $subscription->fill(['ends_at' => Carbon::now()->addDay()])->save();

        $this->assertFalse(app(SubscriptionRenewalService::class)->autoRenew($subscription->refresh()));
        $this->assertSame(1, VendorSubscription::query()->where('customer_id', $vendor->getKey())->count());
        $this->assertSame(5.0, (float) VendorInfo::query()->where('customer_id', $vendor->getKey())->value('balance'));
    }

    public function test_reminders_fire_once_per_window(): void
    {
        $vendor = $this->createVendor();
        $plan = $this->createPlan(0);
        $subscribe = app(SubscribeVendorService::class);
        $renewal = app(SubscriptionRenewalService::class);

        $subscription = $subscribe->activate($subscribe->claim($vendor, $plan));

        $subscription->fill(['ends_at' => Carbon::now()->addDays(7)])->save();
        $this->assertTrue($renewal->remind($subscription->refresh(), [7, 3, 1]));
        $this->assertFalse($renewal->remind($subscription->refresh(), [7, 3, 1]));

        // Crossing into a tighter window sends one more, and never re-opens the wider one.
        $subscription->refresh()->fill(['ends_at' => Carbon::now()->addDays(3)])->save();
        $this->assertTrue($renewal->remind($subscription->refresh(), [7, 3, 1]));
        $this->assertFalse($renewal->remind($subscription->refresh(), [7, 3, 1]));

        // Dedupe now lives in the notification ledger, not the reminders_sent JSON column.
        $this->assertEqualsCanonicalizing(
            ['7', '3'],
            VendorSubscriptionNotification::query()
                ->where('vendor_subscription_id', $subscription->getKey())
                ->where('type', VendorSubscriptionNotification::TYPE_EXPIRING)
                ->pluck('dedupe_key')
                ->all()
        );
    }

    public function test_no_reminder_before_the_first_window(): void
    {
        $vendor = $this->createVendor();
        $subscribe = app(SubscribeVendorService::class);

        $subscription = $subscribe->activate($subscribe->claim($vendor, $this->createPlan(0)));
        $subscription->fill(['ends_at' => Carbon::now()->addDays(30)])->save();

        $this->assertFalse(app(SubscriptionRenewalService::class)->remind($subscription->refresh(), [7, 3, 1]));
    }

    public function test_the_scheduled_command_expires_overdue_subscriptions(): void
    {
        $vendor = $this->createVendor();
        $subscribe = app(SubscribeVendorService::class);

        $subscription = $subscribe->activate($subscribe->claim($vendor, $this->createPlan(0)));
        $subscription->fill(['ends_at' => Carbon::now()->subDays(2)])->save();

        $this->artisan('cms:marketplace:subscriptions:expire')->assertSuccessful();

        $this->assertSame(SubscriptionStatusEnum::EXPIRED, $subscription->refresh()->status->getValue());
    }

    public function test_the_scheduled_command_does_nothing_in_commission_mode(): void
    {
        $vendor = $this->createVendor();
        $subscribe = app(SubscribeVendorService::class);

        $subscription = $subscribe->activate($subscribe->claim($vendor, $this->createPlan(0)));
        $subscription->fill(['ends_at' => Carbon::now()->subDays(2)])->save();

        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();

        $this->artisan('cms:marketplace:subscriptions:expire')->assertSuccessful();

        $this->assertSame(SubscriptionStatusEnum::ACTIVE, $subscription->refresh()->status->getValue());
    }
}
