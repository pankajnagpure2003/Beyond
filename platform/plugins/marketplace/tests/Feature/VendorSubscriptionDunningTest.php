<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Models\Customer;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Enums\StoreStatusEnum;
use Botble\Marketplace\Enums\SubscriptionStatusEnum;
use Botble\Marketplace\Models\Store;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\VendorInfo;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Models\VendorSubscriptionLog;
use Botble\Marketplace\Models\VendorSubscriptionNotification;
use Botble\Marketplace\Services\SubscribeVendorService;
use Botble\Marketplace\Services\SubscriptionNotifier;
use Botble\Marketplace\Services\SubscriptionRenewalService;
use Botble\Marketplace\Services\VendorSubscriptionService;
use Botble\Setting\Facades\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A failed automatic renewal used to be completely silent: the vendor learned nothing
 * until the plan lapsed days later with a generic expiry notice.
 */
class VendorSubscriptionDunningTest extends BaseTestCase
{
    use RefreshDatabase;

    protected int $renewalFailedSends = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('marketplace_mode', MarketplaceModeEnum::SUBSCRIPTION)->save();
        Setting::set('marketplace_verify_vendor', 0)->save();
        Setting::set('marketplace_subscription_grace_period_days', 3)->save();

        $this->renewalFailedSends = 0;
        $counter = function (): void {
            $this->renewalFailedSends++;
        };

        // Counting sends directly is more honest than inspecting the mail transport, and
        // the notifier is constructor-injected so a binding is all it takes.
        $this->app->bind(SubscriptionNotifier::class, fn () => new class ($counter) extends SubscriptionNotifier {
            public function __construct(protected $counter)
            {
            }

            public function renewalFailed(VendorSubscription $subscription): bool
            {
                ($this->counter)();

                return true;
            }

            public function renewed(VendorSubscription $subscription): void
            {
            }

            public function activated(VendorSubscription $subscription): void
            {
            }

            public function expired(VendorSubscription $subscription): void
            {
            }
        });
    }

    protected function tearDown(): void
    {
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();
        Setting::forget('marketplace_subscription_grace_period_days');
        Setting::save();

        parent::tearDown();
    }

    protected function createVendor(float $balance = 0): Customer
    {
        $customer = Customer::query()->create([
            'name' => 'Dunning Vendor',
            'email' => 'dunning-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $customer->is_vendor = true;
        $customer->save();

        Store::query()->create([
            'name' => 'Dunning Store',
            'email' => 'dunning-store-' . uniqid() . '@example.com',
            'customer_id' => $customer->id,
            'status' => StoreStatusEnum::PUBLISHED,
        ]);

        $info = VendorInfo::query()->firstOrNew(['customer_id' => $customer->id]);
        $info->customer_id = $customer->id;
        $info->balance = $balance;
        $info->save();

        return $customer->refresh();
    }

    protected function createPlan(float $price = 50): SubscriptionPlan
    {
        $plan = new SubscriptionPlan([
            'name' => 'Dunning Plan',
            'price' => $price,
            'duration_value' => 1,
            'duration_unit' => 'month',
            'status' => BaseStatusEnum::PUBLISHED,
        ]);

        $plan->fillOptions(SubscriptionPlan::defaultOptions());
        $plan->save();

        return $plan;
    }

    protected function dueSubscription(Customer $vendor, SubscriptionPlan $plan): VendorSubscription
    {
        $service = app(SubscribeVendorService::class);
        $subscription = $service->activate($service->claim($vendor, $plan));

        $subscription->fill([
            'auto_renew' => true,
            'ends_at' => Carbon::now()->addDay(),
        ])->save();

        return $subscription->refresh();
    }

    public function test_a_failed_renewal_notifies_the_vendor_once_per_period(): void
    {
        // Balance covers nothing; the plan costs 50.
        $vendor = $this->createVendor(5);
        $subscription = $this->dueSubscription($vendor, $this->createPlan(50));

        $renewal = app(SubscriptionRenewalService::class);

        foreach (range(1, 3) as $ignored) {
            $this->assertFalse($renewal->autoRenew($subscription->refresh()));
        }

        $this->assertSame(1, $this->renewalFailedSends);

        $this->assertSame(1, VendorSubscriptionLog::query()
            ->where('vendor_subscription_id', $subscription->getKey())
            ->where('type', VendorSubscriptionLog::TYPE_RENEWAL_FAILED)
            ->count());

        $this->assertSame(1, VendorSubscriptionNotification::query()
            ->where('vendor_subscription_id', $subscription->getKey())
            ->where('type', VendorSubscriptionNotification::TYPE_RENEWAL_FAILED)
            ->count());
    }

    public function test_a_new_billing_period_re_arms_the_notification(): void
    {
        $vendor = $this->createVendor(5);
        $subscription = $this->dueSubscription($vendor, $this->createPlan(50));

        $renewal = app(SubscriptionRenewalService::class);
        $renewal->autoRenew($subscription->refresh());

        // An admin extending the period should let the vendor be warned again.
        $subscription->fill(['ends_at' => Carbon::now()->addDays(31)])->save();
        $renewal->autoRenew($subscription->refresh());

        $this->assertSame(2, $this->renewalFailedSends);
    }

    public function test_a_failed_renewal_leaves_the_plan_active_inside_the_grace_period(): void
    {
        $vendor = $this->createVendor(5);
        $subscription = $this->dueSubscription($vendor, $this->createPlan(50));

        app(SubscriptionRenewalService::class)->autoRenew($subscription->refresh());

        $this->assertEquals(SubscriptionStatusEnum::ACTIVE, $subscription->refresh()->status);
        $this->assertNotNull(app(VendorSubscriptionService::class)->current($vendor->refresh()));
    }

    public function test_lifetime_plans_are_never_dunned(): void
    {
        $vendor = $this->createVendor(0);

        $plan = new SubscriptionPlan([
            'name' => 'Lifetime',
            'price' => 500,
            'duration_value' => 1,
            'duration_unit' => 'lifetime',
            'status' => BaseStatusEnum::PUBLISHED,
        ]);
        $plan->fillOptions(SubscriptionPlan::defaultOptions());
        $plan->save();

        $service = app(SubscribeVendorService::class);
        $subscription = $service->activate($service->claim($vendor, $plan));
        $subscription->fill(['auto_renew' => true])->save();

        $this->assertFalse(app(SubscriptionRenewalService::class)->autoRenew($subscription->refresh()));
        $this->assertSame(0, $this->renewalFailedSends);
    }

    public function test_a_successful_renewal_sends_no_failure_notice(): void
    {
        // Enough for the 50 charge.
        $vendor = $this->createVendor(500);
        $subscription = $this->dueSubscription($vendor, $this->createPlan(50));

        $this->assertTrue(app(SubscriptionRenewalService::class)->autoRenew($subscription->refresh()));
        $this->assertSame(0, $this->renewalFailedSends);
    }

    /**
     * failRenewal() runs inside autoRenew()'s catch block and the command drives it from a
     * chunkById loop, so an exception escaping it would abandon every remaining vendor's
     * renewal for that night.
     */
    public function test_a_broken_notifier_does_not_abort_the_sweep(): void
    {
        $vendor = $this->createVendor(5);
        $subscription = $this->dueSubscription($vendor, $this->createPlan(50));

        $this->app->bind(SubscriptionNotifier::class, fn () => new class () extends SubscriptionNotifier {
            public function __construct()
            {
            }

            public function renewalFailed(VendorSubscription $subscription): bool
            {
                throw new RuntimeException('notifier exploded');
            }
        });

        // Must swallow, not propagate.
        $this->assertFalse(app(SubscriptionRenewalService::class)->autoRenew($subscription->refresh()));

        $this->artisan('cms:marketplace:subscriptions:expire')->assertSuccessful();
    }

    /**
     * The balance service commits its own debit, so without a transaction spanning
     * claim -> pay -> activate a failure after payment left the vendor charged, with no
     * active plan, reading an email that said we could not take their money.
     */
    public function test_a_failure_after_payment_rolls_the_debit_back(): void
    {
        $vendor = $this->createVendor(500);
        $subscription = $this->dueSubscription($vendor, $this->createPlan(50));

        $balanceBefore = (float) $vendor->vendorInfo->balance;

        // Make activation blow up after the debit has been taken.
        $this->app->bind(SubscribeVendorService::class, fn ($app) => new class (
            $app->make(\Botble\Marketplace\Services\SubscriptionProductVisibilityService::class),
            $app->make(SubscriptionNotifier::class),
            $app->make(\Botble\Marketplace\Services\SubscriptionTaxService::class),
            $app->make(\Botble\Marketplace\Services\CreateSubscriptionInvoiceService::class),
        ) extends SubscribeVendorService {
            public function activate(
                VendorSubscription $subscription,
                ?\Illuminate\Support\Carbon $from = null,
                bool $issueInvoice = true
            ): VendorSubscription {
                throw new RuntimeException('activation exploded after payment');
            }
        });

        $this->assertFalse(app(SubscriptionRenewalService::class)->autoRenew($subscription->refresh()));

        // Money back, no orphaned PENDING row.
        $this->assertSame($balanceBefore, (float) $vendor->refresh()->vendorInfo->balance);
        $this->assertSame(0, VendorSubscription::query()
            ->where('customer_id', $vendor->getKey())
            ->where('status', SubscriptionStatusEnum::PENDING)
            ->count());
    }

    public function test_the_nightly_command_runs_with_an_underfunded_subscription(): void
    {
        $vendor = $this->createVendor(5);
        $this->dueSubscription($vendor, $this->createPlan(50));

        $this->artisan('cms:marketplace:subscriptions:expire')->assertSuccessful();

        $this->assertSame(1, $this->renewalFailedSends);
    }
}
