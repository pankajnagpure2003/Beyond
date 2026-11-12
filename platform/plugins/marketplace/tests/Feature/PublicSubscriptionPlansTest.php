<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Models\Customer;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Enums\StoreStatusEnum;
use Botble\Marketplace\Enums\SubscriptionStatusEnum;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Models\Store;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Shortcodes\SubscriptionPlansShortcode;
use Botble\Setting\Facades\Setting;
use Botble\Shortcode\Compilers\Shortcode;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Public vendor subscription plans: route gating, plan listing, CTA resolution.
 * Tests the [marketplace-subscription-plans] shortcode and /vendor-plans page.
 */
class PublicSubscriptionPlansTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Settings singleton persists through RefreshDatabase rollback; leak prevention.
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();
        Setting::forget('marketplace_enable_public_subscription_plans');
        Setting::forget('marketplace_enabled_vendor_registration');
        Setting::forget('ecommerce_enable_customer_registration');
        Setting::forget('marketplace_fee_per_order');
        Setting::forget('marketplace_fee_per_order_fixed');
        Setting::save();

        parent::tearDown();
    }

    protected function enablePublicPlans(string $mode = MarketplaceModeEnum::SUBSCRIPTION): void
    {
        Setting::set('marketplace_mode', $mode)
            ->set('marketplace_enable_public_subscription_plans', 1)
            ->save();
    }

    protected function disablePublicPlans(): void
    {
        Setting::forget('marketplace_enable_public_subscription_plans')->save();
    }

    protected function enableVendorRegistration(): void
    {
        Setting::set('marketplace_enabled_vendor_registration', 1)->save();
    }

    protected function disableVendorRegistration(): void
    {
        Setting::set('marketplace_enabled_vendor_registration', 0)->save();
    }

    protected function enableCustomerRegistration(): void
    {
        Setting::set('ecommerce_enable_customer_registration', 1)->save();
    }

    protected function disableCustomerRegistration(): void
    {
        Setting::set('ecommerce_enable_customer_registration', 0)->save();
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

    protected function createCustomer(bool $isVendor = false, bool $verified = true): Customer
    {
        $customer = Customer::query()->create([
            'name' => 'Test Customer',
            'email' => 'customer-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        if ($isVendor) {
            $customer->is_vendor = true;
            $customer->vendor_verified_at = $verified ? now() : null;
            $customer->save();

            Store::query()->create([
                'name' => 'Test Store',
                'email' => 'store-' . uniqid() . '@example.com',
                'customer_id' => $customer->id,
                'status' => StoreStatusEnum::PUBLISHED,
            ]);
        }

        return $customer;
    }

    /**
     * Test 1: mode=Subscription, toggle off → 404
     */
    public function test_vendor_plans_404_when_setting_disabled(): void
    {
        Setting::set('marketplace_mode', MarketplaceModeEnum::SUBSCRIPTION)->save();
        $this->disablePublicPlans();

        $this->get(route('public.marketplace.subscription-plans'))
            ->assertNotFound();
    }

    /**
     * Test 2: mode=Commission, toggle on → 404
     */
    public function test_vendor_plans_404_when_mode_commission(): void
    {
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)
            ->set('marketplace_enable_public_subscription_plans', 1)
            ->save();

        $this->get(route('public.marketplace.subscription-plans'))
            ->assertNotFound();
    }

    /**
     * Test 3: mode=Hybrid, toggle on → 200
     */
    public function test_vendor_plans_200_when_mode_hybrid_and_enabled(): void
    {
        $this->enablePublicPlans(MarketplaceModeEnum::HYBRID);

        $this->get(route('public.marketplace.subscription-plans'))
            ->assertOk();
    }

    /**
     * Test 4: mode=Subscription, toggle on, 2 published + 1 draft → 200, both published names, draft absent
     */
    public function test_vendor_plans_lists_published_plans_only(): void
    {
        $this->enablePublicPlans(MarketplaceModeEnum::SUBSCRIPTION);

        $plan1 = $this->createPlan(['name' => 'Basic', 'price' => 10]);
        $plan2 = $this->createPlan(['name' => 'Pro', 'price' => 29]);
        $draft = $this->createPlan(['name' => 'Draft Plan', 'status' => BaseStatusEnum::DRAFT]);

        $response = $this->get(route('public.marketplace.subscription-plans'))
            ->assertOk();

        $response->assertSee($plan1->name);
        $response->assertSee($plan2->name);
        $response->assertDontSee($draft->name);
    }

    /**
     * Test 5: guest, customer registration enabled → contains register route
     */
    public function test_guest_cta_register_route_when_registration_enabled(): void
    {
        $this->enablePublicPlans();
        $this->enableCustomerRegistration();

        $plan = $this->createPlan();

        $response = $this->get(route('public.marketplace.subscription-plans'))
            ->assertOk();

        $registerUrl = route('customer.register');
        $response->assertSee($registerUrl);
    }

    /**
     * Test 6: guest, customer registration disabled → contains login route, no register url
     */
    public function test_guest_cta_login_route_when_registration_disabled(): void
    {
        $this->enablePublicPlans();
        $this->disableCustomerRegistration();

        $plan = $this->createPlan();

        $response = $this->get(route('public.marketplace.subscription-plans'))
            ->assertOk();

        $loginUrl = route('customer.login');
        $response->assertSee($loginUrl);
        $response->assertDontSee(route('customer.register'));
    }

    /**
     * Test 7: logged-in customer, is_vendor=false, vendor reg on → contains become-vendor url
     */
    public function test_logged_in_non_vendor_cta_become_vendor_when_enabled(): void
    {
        $this->enablePublicPlans();
        $this->enableVendorRegistration();

        $customer = $this->createCustomer(isVendor: false);
        $plan = $this->createPlan();

        $response = $this->actingAs($customer, 'customer')
            ->get(route('public.marketplace.subscription-plans'))
            ->assertOk();

        $response->assertSee(route('marketplace.vendor.become-vendor'));
    }

    /**
     * Test 8: logged-in customer, vendor reg off → become-vendor url absent, disabled CTA
     */
    public function test_logged_in_non_vendor_cta_disabled_when_vendor_registration_disabled(): void
    {
        $this->enablePublicPlans();
        $this->disableVendorRegistration();

        $customer = $this->createCustomer(isVendor: false);
        $plan = $this->createPlan();

        $response = $this->actingAs($customer, 'customer')
            ->get(route('public.marketplace.subscription-plans'))
            ->assertOk();

        $response->assertDontSee(route('marketplace.vendor.become-vendor'));
    }

    /**
     * Test 9: vendor, no active subscription → contains checkout url
     */
    public function test_vendor_cta_checkout_url_when_no_active_subscription(): void
    {
        $this->enablePublicPlans();

        $vendor = $this->createCustomer(isVendor: true);
        $plan = $this->createPlan();

        $response = $this->actingAs($vendor, 'customer')
            ->get(route('public.marketplace.subscription-plans'))
            ->assertOk();

        $checkoutUrl = route('marketplace.vendor.subscriptions.checkout', $plan->getKey());
        $response->assertSee($checkoutUrl);
    }

    /**
     * Test 10: vendor with ACTIVE subscription on plan A → plan A shows current_plan, plan B shows checkout
     */
    public function test_vendor_cta_current_plan_when_subscribed(): void
    {
        $this->enablePublicPlans();

        $vendor = $this->createCustomer(isVendor: true);
        $planA = $this->createPlan(['name' => 'Plan A', 'price' => 19]);
        $planB = $this->createPlan(['name' => 'Plan B', 'price' => 49]);

        // Active subscription on planA
        VendorSubscription::query()->create([
            'customer_id' => $vendor->id,
            'subscription_plan_id' => $planA->id,
            'amount' => 19,
            'sub_total' => 19,
            'currency' => 'USD',
            'status' => SubscriptionStatusEnum::ACTIVE,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        $response = $this->actingAs($vendor, 'customer')
            ->get(route('public.marketplace.subscription-plans'))
            ->assertOk();

        // Plan A should show current_plan label (disabled button)
        $currentPlanLabel = trans('plugins/marketplace::subscription.vendor.current_plan');
        $response->assertSee($currentPlanLabel);

        // Plan B should show checkout url
        $checkoutUrlB = route('marketplace.vendor.subscriptions.checkout', $planB->getKey());
        $response->assertSee($checkoutUrlB);
    }

    public function test_unverified_vendor_cta_become_vendor(): void
    {
        $this->enablePublicPlans();

        $vendor = $this->createCustomer(isVendor: true, verified: false);
        $plan = $this->createPlan();

        $response = $this->actingAs($vendor, 'customer')
            ->get(route('public.marketplace.subscription-plans'))
            ->assertOk();

        $response->assertSee(route('marketplace.vendor.become-vendor'));
        $response->assertDontSee(route('marketplace.vendor.subscriptions.checkout', $plan->getKey()));
    }

    public function test_vendor_with_pending_subscription_cta_points_to_subscriptions_index(): void
    {
        $this->enablePublicPlans();

        $vendor = $this->createCustomer(isVendor: true);
        $plan = $this->createPlan();

        VendorSubscription::query()->create([
            'customer_id' => $vendor->id,
            'subscription_plan_id' => $plan->id,
            'amount' => 19,
            'sub_total' => 19,
            'currency' => 'USD',
            'status' => SubscriptionStatusEnum::PENDING,
        ]);

        $response = $this->actingAs($vendor, 'customer')
            ->get(route('public.marketplace.subscription-plans'))
            ->assertOk();

        $response->assertSee(route('marketplace.vendor.subscriptions.index'));
        $response->assertSee(trans('plugins/marketplace::subscription.vendor.pending_title'));
        $response->assertDontSee(route('marketplace.vendor.subscriptions.checkout', $plan->getKey()));
    }

    /**
     * Test 11: shortcode render, toggle off, mode=Subscription → both plan names
     */
    public function test_shortcode_renders_plans_regardless_of_page_setting(): void
    {
        // Shortcode ignores the page toggle, only respects mode
        Setting::set('marketplace_mode', MarketplaceModeEnum::SUBSCRIPTION)->save();
        $this->disablePublicPlans();

        $plan1 = $this->createPlan(['name' => 'Shortcode Plan 1', 'price' => 10]);
        $plan2 = $this->createPlan(['name' => 'Shortcode Plan 2', 'price' => 29]);

        $shortcode = new Shortcode('marketplace-subscription-plans', [], null);

        $output = app(SubscriptionPlansShortcode::class)->render($shortcode);

        $this->assertStringContainsString($plan1->name, $output);
        $this->assertStringContainsString($plan2->name, $output);
    }

    /**
     * Test 12: shortcode render, mode=Commission → output is ''
     */
    public function test_shortcode_returns_empty_when_mode_commission(): void
    {
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();

        $plan = $this->createPlan();

        $shortcode = new Shortcode('marketplace-subscription-plans', [], null);

        $output = app(SubscriptionPlansShortcode::class)->render($shortcode);

        $this->assertSame('', $output);
    }

    /**
     * Test 13: shortcode limit=1 → exactly one plan name
     */
    public function test_shortcode_respects_limit_attribute(): void
    {
        $this->enablePublicPlans();

        $plan1 = $this->createPlan(['name' => 'Limited Plan 1', 'price' => 10]);
        $plan2 = $this->createPlan(['name' => 'Limited Plan 2', 'price' => 29]);

        $shortcode = new Shortcode('marketplace-subscription-plans', ['limit' => 1], null);

        $output = app(SubscriptionPlansShortcode::class)->render($shortcode);

        $this->assertStringContainsString($plan1->name, $output);
        $this->assertStringNotContainsString($plan2->name, $output);
    }

    /**
     * Unit-level: CTA resolver never throws for a guest
     */
    public function test_cta_resolver_never_throws_for_guest(): void
    {
        $plan = $this->createPlan();

        // Guest should not auth
        $this->assertNull(auth('customer')->user());

        // Resolver should not throw
        $cta = MarketplaceHelper::subscriptionPlanCta($plan);

        $this->assertIsArray($cta);
        $this->assertArrayHasKey('url', $cta);
        $this->assertArrayHasKey('label', $cta);
        $this->assertArrayHasKey('disabled', $cta);
    }
}
