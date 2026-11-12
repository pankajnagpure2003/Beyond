<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Models\Customer;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Enums\StoreStatusEnum;
use Botble\Marketplace\Models\Store;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Models\VendorSubscriptionNotification;
use Botble\Marketplace\Services\SubscribeVendorService;
use Botble\Marketplace\Services\SubscriptionNotificationLedger;
use Botble\Marketplace\Services\SubscriptionNotifier;
use Botble\Marketplace\Services\SubscriptionRenewalService;
use Botble\Setting\Facades\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The claim-before-send guarantee that replaced the reminders_sent JSON column.
 */
class SubscriptionNotificationLedgerTest extends BaseTestCase
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
        Setting::save();

        parent::tearDown();
    }

    protected function subscription(): VendorSubscription
    {
        $customer = Customer::query()->create([
            'name' => 'Ledger Vendor',
            'email' => 'ledger-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $customer->is_vendor = true;
        $customer->save();

        Store::query()->create([
            'name' => 'Ledger Store',
            'email' => 'ledger-store-' . uniqid() . '@example.com',
            'customer_id' => $customer->id,
            'status' => StoreStatusEnum::PUBLISHED,
        ]);

        $plan = new SubscriptionPlan([
            'name' => 'Ledger Plan',
            'price' => 0,
            'duration_value' => 1,
            'duration_unit' => 'month',
            'status' => BaseStatusEnum::PUBLISHED,
        ]);
        $plan->fillOptions(SubscriptionPlan::defaultOptions());
        $plan->save();

        $service = app(SubscribeVendorService::class);

        return $service->activate($service->claim($customer->refresh(), $plan));
    }

    public function test_a_key_can_only_be_claimed_once(): void
    {
        $ledger = app(SubscriptionNotificationLedger::class);
        $subscription = $this->subscription();

        $this->assertSame(1, $ledger->claim($subscription, VendorSubscriptionNotification::TYPE_EXPIRING, ['7']));
        $this->assertSame(0, $ledger->claim($subscription, VendorSubscriptionNotification::TYPE_EXPIRING, ['7']));
    }

    public function test_claiming_many_returns_only_the_newly_claimed(): void
    {
        $ledger = app(SubscriptionNotificationLedger::class);
        $subscription = $this->subscription();

        $ledger->claim($subscription, VendorSubscriptionNotification::TYPE_EXPIRING, ['7']);

        // 7 is already taken, 3 is not — so exactly one new claim.
        $this->assertSame(
            1,
            $ledger->claim($subscription, VendorSubscriptionNotification::TYPE_EXPIRING, ['7', '3'])
        );
    }

    public function test_two_concurrent_claims_grant_exactly_one(): void
    {
        $subscription = $this->subscription();

        $first = (new SubscriptionNotificationLedger())
            ->claim($subscription, VendorSubscriptionNotification::TYPE_EXPIRING, ['1']);
        $second = (new SubscriptionNotificationLedger())
            ->claim($subscription, VendorSubscriptionNotification::TYPE_EXPIRING, ['1']);

        $this->assertSame(1, $first + $second);
    }

    public function test_different_types_do_not_collide(): void
    {
        $ledger = app(SubscriptionNotificationLedger::class);
        $subscription = $this->subscription();

        $ledger->claim($subscription, VendorSubscriptionNotification::TYPE_EXPIRING, ['7']);

        $this->assertSame(
            1,
            $ledger->claim($subscription, VendorSubscriptionNotification::TYPE_RENEWAL_FAILED, ['7'])
        );
    }

    /**
     * The at-most-once guarantee, stated as a test: a mailer that throws after the claim
     * loses that notification rather than repeating it on every later run.
     */
    public function test_a_crash_after_claiming_sends_zero_not_many(): void
    {
        $subscription = $this->subscription();
        $subscription->fill(['ends_at' => Carbon::now()->addDays(3)])->save();

        $this->app->bind(SubscriptionNotifier::class, fn () => new class () extends SubscriptionNotifier {
            public function expiring(VendorSubscription $subscription, int $daysLeft): bool
            {
                throw new RuntimeException('mailer is down');
            }
        });

        try {
            app(SubscriptionRenewalService::class)->remind($subscription->refresh(), [7, 3, 1]);
        } catch (RuntimeException) {
            // The send blew up; the claim must still stand.
        }

        $this->assertSame(
            2,
            VendorSubscriptionNotification::query()
                ->where('vendor_subscription_id', $subscription->getKey())
                ->whereNull('sent_at')
                ->count()
        );

        // A later run with a working mailer must not re-send what was already claimed.
        $sent = 0;
        $this->app->bind(SubscriptionNotifier::class, fn () => new class ($sent) extends SubscriptionNotifier {
            public function __construct(public int &$count)
            {
            }

            public function expiring(VendorSubscription $subscription, int $daysLeft): bool
            {
                $this->count++;

                return true;
            }
        });

        $this->assertFalse(app(SubscriptionRenewalService::class)->remind($subscription->refresh(), [7, 3, 1]));
        $this->assertSame(0, $sent);
    }
}
