<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Models\Customer;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Services\SubscribeVendorService;
use Botble\Marketplace\Services\SubscriptionPaymentSession;
use Botble\Setting\Facades\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * The checkout marker decides whether a payment in flight belongs to a subscription or to
 * an ordinary storefront order. A stale marker would rewrite an order's amount and return
 * URLs, so these guards matter more than they look.
 */
class SubscriptionPaymentSessionTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('marketplace_mode', MarketplaceModeEnum::SUBSCRIPTION)->save();
    }

    protected function tearDown(): void
    {
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();
        Setting::save();

        parent::tearDown();
    }

    protected function createVendor(): Customer
    {
        $customer = Customer::query()->create([
            'name' => 'Session Vendor',
            'email' => 'session-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $customer->is_vendor = true;
        $customer->save();

        return $customer->refresh();
    }

    protected function createPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::query()->create([
            'name' => 'Starter',
            'price' => 19,
            'duration_value' => 1,
            'duration_unit' => 'month',
            'status' => BaseStatusEnum::PUBLISHED,
        ]);
    }

    public function test_a_started_checkout_is_active(): void
    {
        $session = app(SubscriptionPaymentSession::class);
        $subscription = app(SubscribeVendorService::class)->claim($this->createVendor(), $this->createPlan());

        $session->start($subscription);

        $this->assertTrue($session->isActive());
        $this->assertSame($subscription->getKey(), $session->current()?->getKey());
    }

    public function test_no_marker_means_no_checkout_in_flight(): void
    {
        $this->assertFalse(app(SubscriptionPaymentSession::class)->isActive());
        $this->assertNull(app(SubscriptionPaymentSession::class)->current());
    }

    public function test_an_expired_marker_is_dropped(): void
    {
        $session = app(SubscriptionPaymentSession::class);
        $subscription = app(SubscribeVendorService::class)->claim($this->createVendor(), $this->createPlan());

        $session->start($subscription);

        Carbon::setTestNow(Carbon::now()->addMinutes(SubscriptionPaymentSession::TTL_MINUTES + 1));

        $this->assertNull($session->current());
        $this->assertFalse($session->isActive());

        Carbon::setTestNow();
    }

    public function test_the_marker_stops_counting_once_the_subscription_is_no_longer_pending(): void
    {
        $session = app(SubscriptionPaymentSession::class);
        $subscribe = app(SubscribeVendorService::class);
        $subscription = $subscribe->claim($this->createVendor(), $this->createPlan());

        $session->start($subscription);
        $subscribe->activate($subscription);

        // Vendor wanders off to the storefront after paying: their order checkout must
        // not be hijacked by the finished subscription round trip.
        $this->assertNull($session->current());
        $this->assertFalse($session->isActive());
    }

    public function test_a_deleted_subscription_leaves_no_usable_marker(): void
    {
        $session = app(SubscriptionPaymentSession::class);
        $subscription = app(SubscribeVendorService::class)->claim($this->createVendor(), $this->createPlan());

        $session->start($subscription);
        $subscription->delete();

        $this->assertNull($session->current());
    }

    public function test_deleting_a_subscription_removes_its_audit_log(): void
    {
        $subscription = app(SubscribeVendorService::class)->claim($this->createVendor(), $this->createPlan());
        $id = $subscription->getKey();

        $this->assertDatabaseHas('mp_vendor_subscription_logs', ['vendor_subscription_id' => $id]);

        $subscription->delete();

        $this->assertDatabaseMissing('mp_vendor_subscription_logs', ['vendor_subscription_id' => $id]);
    }

    public function test_payment_data_carries_no_order_id_and_points_at_the_vendor_routes(): void
    {
        $vendor = $this->createVendor();
        $subscription = app(SubscribeVendorService::class)->claim($vendor, $this->createPlan());

        $data = app(SubscriptionPaymentSession::class)->paymentData($subscription);

        // An order id here would make the ecommerce listeners treat the charge as an
        // order payment and could mark an unrelated order paid.
        $this->assertSame([], $data['order_id']);
        $this->assertSame(19.0, $data['amount']);
        $this->assertSame($vendor->getKey(), $data['customer_id']);
        $this->assertSame(route('marketplace.vendor.subscriptions.callback'), $data['callback_url']);
        $this->assertSame(route('marketplace.vendor.subscriptions.cancel-payment'), $data['return_url']);
    }
}
