<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\ACL\Models\User;
use Botble\ACL\Services\ActivateUserService;
use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Models\Customer;
use Botble\Ecommerce\Models\Product;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Enums\StoreStatusEnum;
use Botble\Marketplace\Enums\SubscriptionStatusEnum;
use Botble\Marketplace\Enums\WithdrawalFeeTypeEnum;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Models\Store;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Services\SubscribeVendorService;
use Botble\Marketplace\Tables\VendorSubscriptionTable;
use Botble\Setting\Facades\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

class VendorSubscriptionHttpTest extends BaseTestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('marketplace_mode', MarketplaceModeEnum::SUBSCRIPTION)->save();
        Setting::set('marketplace_verify_vendor', 0)->save();

        $this->admin = $this->createAdminUser();
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
        Setting::forget('marketplace_subscription_payment_methods');
        Setting::save();

        parent::tearDown();
    }

    protected function createAdminUser(): User
    {
        Schema::disableForeignKeyConstraints();
        User::query()->truncate();

        $user = new User();
        $user->forceFill([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin@test.com',
            'username' => 'admin',
            'password' => bcrypt('password'),
            'super_user' => 1,
            'manage_supers' => 1,
        ]);
        $user->save();

        app(ActivateUserService::class)->activate($user);

        return $user;
    }

    protected function createVendor(): Customer
    {
        $customer = Customer::query()->create([
            'name' => 'Http Vendor',
            'email' => 'http-vendor-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        // is_vendor is a marketplace-added column and is not in Customer's $fillable,
        // so mass assignment silently drops it and the vendor guard rejects the user.
        $customer->is_vendor = true;
        $customer->save();

        Store::query()->create([
            'name' => 'Http Store',
            'email' => 'http-store-' . uniqid() . '@example.com',
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

        // Options are a JSON column normalised through fillOptions(), not mass-assignable.
        $plan->fillOptions(array_merge(SubscriptionPlan::defaultOptions(), $options));
        $plan->save();

        return $plan;
    }

    public function test_admin_can_open_the_subscription_plans_screen(): void
    {
        $this->actingAs($this->admin, 'web')
            ->get(route('marketplace.subscription-plans.index'))
            ->assertOk();
    }

    public function test_admin_can_create_a_plan_with_options(): void
    {
        $this->actingAs($this->admin, 'web')
            ->post(route('marketplace.subscription-plans.store'), [
                'name' => 'Pro',
                'price' => 49,
                'duration_value' => 1,
                'duration_unit' => 'month',
                'status' => BaseStatusEnum::PUBLISHED,
                'options' => ['product_limit' => 25, 'allow_coupons' => 1],
            ])
            ->assertSessionHasNoErrors();

        $plan = SubscriptionPlan::query()->where('name', 'Pro')->first();

        $this->assertNotNull($plan);
        $this->assertSame(25, $plan->getOption('product_limit'));
        $this->assertSame(1, $plan->getOption('allow_coupons'));
        // Unsubmitted flags must read as off, not inherit a default.
        $this->assertSame(0, $plan->getOption('allow_digital_products'));
    }

    public function test_a_paid_plan_cannot_be_made_the_default(): void
    {
        $this->actingAs($this->admin, 'web')
            ->post(route('marketplace.subscription-plans.store'), [
                'name' => 'Paid default',
                'price' => 49,
                'duration_value' => 1,
                'duration_unit' => 'month',
                'status' => BaseStatusEnum::PUBLISHED,
                'is_default' => 1,
            ])
            ->assertSessionHasErrors('is_default');
    }

    public function test_admin_can_approve_a_pending_subscription_over_http(): void
    {
        $vendor = $this->createVendor();
        $subscription = app(SubscribeVendorService::class)->claim($vendor, $this->createPlan());

        $this->actingAs($this->admin, 'web')
            ->post(route('marketplace.vendor-subscriptions.approve', $subscription->getKey()));

        $this->assertSame(SubscriptionStatusEnum::ACTIVE, $subscription->refresh()->status->getValue());
    }

    public function test_rejecting_without_a_reason_is_refused(): void
    {
        $vendor = $this->createVendor();
        $subscription = app(SubscribeVendorService::class)->claim($vendor, $this->createPlan());

        $this->actingAs($this->admin, 'web')
            ->post(route('marketplace.vendor-subscriptions.reject', $subscription->getKey()), [])
            ->assertSessionHasErrors('reason');

        $this->assertSame(SubscriptionStatusEnum::PENDING, $subscription->refresh()->status->getValue());
    }

    public function test_admin_can_assign_a_plan_to_a_vendor(): void
    {
        $vendor = $this->createVendor();
        $plan = $this->createPlan();

        $this->actingAs($this->admin, 'web')
            ->post(route('marketplace.vendor-subscriptions.store'), [
                'customer_id' => $vendor->getKey(),
                'subscription_plan_id' => $plan->getKey(),
            ])
            ->assertSessionHasNoErrors();

        $subscription = VendorSubscription::query()->where('customer_id', $vendor->getKey())->first();

        $this->assertNotNull($subscription);
        $this->assertTrue($subscription->isActive());
        $this->assertSame(0.0, $subscription->amount);
    }

    public function test_vendor_without_a_subscription_is_redirected_away_from_product_creation(): void
    {
        $vendor = $this->createVendor();

        $this->actingAs($vendor, 'customer')
            ->get(route('marketplace.vendor.products.create'))
            ->assertRedirect(route('marketplace.vendor.subscriptions.index'));
    }

    public function test_vendor_without_a_subscription_can_still_reach_the_subscription_pages(): void
    {
        $vendor = $this->createVendor();
        $this->createPlan();

        $this->actingAs($vendor, 'customer')
            ->get(route('marketplace.vendor.subscriptions.index'))
            ->assertOk();

        $this->actingAs($vendor, 'customer')
            ->get(route('marketplace.vendor.subscriptions.plans'))
            ->assertOk();
    }

    public function test_product_creation_is_open_again_once_the_vendor_subscribes(): void
    {
        $vendor = $this->createVendor();
        $subscribe = app(SubscribeVendorService::class);
        $subscribe->activate($subscribe->claim($vendor, $this->createPlan()));

        $this->actingAs($vendor, 'customer')
            ->get(route('marketplace.vendor.products.create'))
            ->assertOk();
    }

    public function test_product_creation_is_never_gated_in_commission_mode(): void
    {
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();

        $vendor = $this->createVendor();

        $this->actingAs($vendor, 'customer')
            ->get(route('marketplace.vendor.products.create'))
            ->assertOk();
    }

    public function test_admin_can_open_the_plan_create_and_edit_forms(): void
    {
        $plan = $this->createPlan();

        $this->actingAs($this->admin, 'web')
            ->get(route('marketplace.subscription-plans.create'))
            ->assertOk();

        $this->actingAs($this->admin, 'web')
            ->get(route('marketplace.subscription-plans.edit', $plan->getKey()))
            ->assertOk()
            ->assertSee('options[product_limit]', false);
    }

    public function test_admin_can_open_the_vendor_subscription_screens(): void
    {
        $vendor = $this->createVendor();
        $subscription = app(SubscribeVendorService::class)->claim($vendor, $this->createPlan());

        $this->actingAs($this->admin, 'web')
            ->get(route('marketplace.vendor-subscriptions.index'))
            ->assertOk();

        $this->actingAs($this->admin, 'web')
            ->get(route('marketplace.vendor-subscriptions.create'))
            ->assertOk();

        // Detail page renders the action buttons and the audit trail. Each button only
        // opens a modal, so assert the modal and its form target are actually on the page —
        // a button whose data-bs-target names a modal that was never pushed does nothing.
        $this->actingAs($this->admin, 'web')
            ->get(route('marketplace.vendor-subscriptions.edit', $subscription->getKey()))
            ->assertOk()
            ->assertSee(trans('plugins/marketplace::subscription.actions.approve'))
            ->assertSee('approve-subscription-modal')
            ->assertSee('reject-subscription-modal')
            ->assertSee(
                route('marketplace.vendor-subscriptions.approve', $subscription->getKey()),
                false
            )
            ->assertSee(
                route('marketplace.vendor-subscriptions.reject', $subscription->getKey()),
                false
            );
    }

    public function test_the_detail_page_hides_the_actions_panel_when_nothing_can_be_done(): void
    {
        $vendor = $this->createVendor();
        $subscription = app(SubscribeVendorService::class)->claim($vendor, $this->createPlan());

        // Expired, cancelled and rejected are terminal: no approve, reject, extend or
        // cancel applies, so the panel must not render as an empty card.
        foreach ([
            SubscriptionStatusEnum::EXPIRED,
            SubscriptionStatusEnum::CANCELLED,
            SubscriptionStatusEnum::REJECTED,
        ] as $status) {
            $subscription->fill(['status' => $status])->save();

            $this->actingAs($this->admin, 'web')
                ->get(route('marketplace.vendor-subscriptions.edit', $subscription->getKey()))
                ->assertOk()
                ->assertDontSee('approve-subscription-modal')
                ->assertDontSee('cancel-subscription-modal')
                ->assertDontSee('extend-subscription-modal')
                ->assertSee('col-lg-12', false);
        }
    }

    public function test_the_admin_subscription_table_filters_by_vendor_and_plan(): void
    {
        $service = app(SubscribeVendorService::class);

        $starter = $this->createPlan(['name' => 'Starter']);
        $pro = $this->createPlan(['name' => 'Pro', 'price' => 49]);

        $vendorA = $this->createVendor();
        $vendorB = $this->createVendor();

        $service->claim($vendorA, $starter);
        $service->claim($vendorB, $pro);

        $table = app(VendorSubscriptionTable::class);
        $filters = $table->getFilters();

        // Both filters must offer the values the admin can actually see in the table.
        $this->assertArrayHasKey('subscription_plan_id', $filters);
        $this->assertArrayHasKey('customer_id', $filters);

        // Both are lazy: the choices come from a callback the filter UI invokes.
        $this->assertContains('Pro', ($filters['subscription_plan_id']['callback'])());
        $this->assertContains($vendorB->name, ($filters['customer_id']['callback'])());

        // And narrowing by one of them must actually cut the result set.
        $narrowed = $table->query()->where('subscription_plan_id', $pro->getKey())->get();

        $this->assertCount(1, $narrowed);
        $this->assertSame($vendorB->getKey(), $narrowed->first()->customer_id);
    }

    /**
     * Only republish() undoes an expiry and it runs on activation, so without this a
     * marketplace leaving subscription mode would strand every unpublished product with
     * nothing left in the system able to restore it.
     */
    public function test_leaving_subscription_mode_restores_products_expiry_unpublished(): void
    {
        $vendor = $this->createVendor();
        $subscribe = app(SubscribeVendorService::class);
        $subscription = $subscribe->activate($subscribe->claim($vendor, $this->createPlan(['price' => 0])));

        Setting::set('marketplace_subscription_unpublish_products_on_expired', 1)->save();

        $product = new Product([
            'name' => 'Stranded Product',
            'status' => BaseStatusEnum::PUBLISHED,
            'is_variation' => 0,
        ]);
        $product->store_id = $vendor->store->getKey();
        $product->save();

        $subscribe->expire($subscription);

        $this->assertNotNull($product->refresh()->unpublished_by_subscription_at);
        $this->assertNotEquals(BaseStatusEnum::PUBLISHED, $product->status);

        // The admin decides subscriptions were a mistake.
        $this->actingAs($this->admin, 'web')
            ->put(route('marketplace.settings.update'), [
                'mode' => MarketplaceModeEnum::COMMISSION,
                'payout_methods' => ['bank_transfer' => 1],
                'withdrawal_fee_type' => WithdrawalFeeTypeEnum::FIXED,
            ])
            ->assertSessionHasNoErrors();

        $product->refresh();

        $this->assertNull($product->unpublished_by_subscription_at);
        $this->assertEquals(BaseStatusEnum::PUBLISHED, $product->status);
    }

    public function test_marketplace_settings_page_renders_in_both_modes(): void
    {
        // Both sections are always rendered; a collapsible keyed on the mode select
        // shows the right one, so switching mode needs no save. Match the rendered
        // input name — the bare setting keys also appear in the page's translation
        // blobs, which would make a plain substring check meaningless.
        $this->actingAs($this->admin, 'web')
            ->get(route('marketplace.settings'))
            ->assertOk()
            ->assertSee('name="subscription_grace_period_days"', false)
            ->assertSee('name="fee_per_order"', false)
            ->assertSee('data-bb-trigger="[name=mode]"', false);

        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();

        $this->actingAs($this->admin, 'web')
            ->get(route('marketplace.settings'))
            ->assertOk()
            ->assertSee('name="fee_per_order"', false)
            ->assertSee('name="subscription_grace_period_days"', false);
    }

    public function test_switching_mode_through_the_settings_form_persists(): void
    {
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();

        $this->actingAs($this->admin, 'web')
            ->put(route('marketplace.settings.update'), [
                'mode' => MarketplaceModeEnum::SUBSCRIPTION,
                'payout_methods' => ['bank_transfer' => 1],
                'withdrawal_fee_type' => WithdrawalFeeTypeEnum::FIXED,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(MarketplaceHelper::isSubscriptionMode());
    }

    public function test_settings_page_offers_the_subscription_payment_method_picker(): void
    {
        $this->actingAs($this->admin, 'web')
            ->get(route('marketplace.settings'))
            ->assertOk()
            ->assertSee('subscription_payment_methods', false);
    }

    public function test_a_gateway_the_admin_disabled_for_subscriptions_is_refused(): void
    {
        Setting::set('marketplace_subscription_payment_methods', json_encode(['bank_transfer']))->save();

        $vendor = $this->createVendor();
        $plan = $this->createPlan();

        $this->actingAs($vendor, 'customer')
            ->post(route('marketplace.vendor.subscriptions.process-checkout', $plan->getKey()), [
                'payment_method' => 'stripe',
            ])
            ->assertRedirect(route('marketplace.vendor.subscriptions.checkout', $plan->getKey()));

        // Nothing was claimed, so the admin has no stray request to review.
        $this->assertDatabaseCount('mp_vendor_subscriptions', 0);
    }

    public function test_an_allowed_offline_gateway_still_creates_a_pending_request(): void
    {
        Setting::set('marketplace_subscription_payment_methods', json_encode(['bank_transfer']))->save();

        $vendor = $this->createVendor();
        $plan = $this->createPlan();

        $this->actingAs($vendor, 'customer')
            ->post(route('marketplace.vendor.subscriptions.process-checkout', $plan->getKey()), [
                'payment_method' => 'bank_transfer',
            ])
            ->assertRedirect(route('marketplace.vendor.subscriptions.index'));

        $subscription = VendorSubscription::query()->where('customer_id', $vendor->getKey())->first();

        $this->assertNotNull($subscription);
        $this->assertTrue($subscription->isPending());
        $this->assertSame('bank_transfer', $subscription->payment_channel);
    }

    public function test_an_empty_restriction_allows_every_enabled_gateway(): void
    {
        Setting::set('marketplace_subscription_payment_methods', json_encode([]))->save();

        $vendor = $this->createVendor();
        $plan = $this->createPlan();

        $this->actingAs($vendor, 'customer')
            ->post(route('marketplace.vendor.subscriptions.process-checkout', $plan->getKey()), [
                'payment_method' => 'cod',
            ])
            ->assertRedirect(route('marketplace.vendor.subscriptions.index'));

        $this->assertDatabaseCount('mp_vendor_subscriptions', 1);
    }

    public function test_only_the_active_mode_section_starts_expanded(): void
    {
        // The collapsible hides the inactive section with an inline style; the active
        // one must not carry it, or the admin lands on a page with nothing visible.
        // data-bb-value holds a JSON array of the modes a section belongs to, because
        // hybrid shares the commission section with commission and the subscription
        // section with subscription - hence the substring match rather than equality.
        $subscriptionMode = $this->actingAs($this->admin, 'web')
            ->get(route('marketplace.settings'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/data-bb-value="[^"]*commission[^"]*"[^>]*style="display: none"/',
            $subscriptionMode,
            'The commission section should start hidden while in subscription mode.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-bb-value="[^"]*subscription[^"]*"[^>]*style="display: none"/',
            $subscriptionMode
        );

        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();

        $commissionMode = $this->actingAs($this->admin, 'web')
            ->get(route('marketplace.settings'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/data-bb-value="[^"]*subscription[^"]*"[^>]*style="display: none"/',
            $commissionMode,
            'The subscription section should start hidden while in commission mode.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-bb-value="[^"]*commission[^"]*"[^>]*style="display: none"/',
            $commissionMode
        );
    }

    public function test_an_unpublished_plan_cannot_be_reached_at_checkout(): void
    {
        $vendor = $this->createVendor();
        $draft = $this->createPlan(['name' => 'Draft', 'status' => BaseStatusEnum::DRAFT]);

        // Route-model binding resolves any id; only the status check keeps a plan the
        // admin has not published out of the vendor's hands.
        $this->actingAs($vendor, 'customer')
            ->get(route('marketplace.vendor.subscriptions.checkout', $draft->getKey()))
            ->assertNotFound();

        $this->actingAs($vendor, 'customer')
            ->post(route('marketplace.vendor.subscriptions.process-checkout', $draft->getKey()), [
                'payment_method' => 'bank_transfer',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('mp_vendor_subscriptions', 0);
    }

    public function test_an_unpublished_plan_is_absent_from_the_plan_list(): void
    {
        $vendor = $this->createVendor();
        $this->createPlan(['name' => 'Visible Plan']);
        $this->createPlan(['name' => 'Hidden Plan', 'status' => BaseStatusEnum::DRAFT]);

        $this->actingAs($vendor, 'customer')
            ->get(route('marketplace.vendor.subscriptions.plans'))
            ->assertOk()
            ->assertSee('Visible Plan')
            ->assertDontSee('Hidden Plan');
    }

    public function test_coupons_are_gated_on_the_plans_allow_coupons_flag(): void
    {
        $vendor = $this->createVendor();
        $subscribe = app(SubscribeVendorService::class);

        $without = $this->createPlan(['name' => 'No coupons'], ['allow_coupons' => 0]);
        $subscribe->activate($subscribe->claim($vendor, $without));

        $this->actingAs($vendor, 'customer')
            ->get(route('marketplace.vendor.discounts.index'))
            ->assertRedirect(route('marketplace.vendor.subscriptions.index'));

        $with = $this->createPlan(['name' => 'With coupons'], ['allow_coupons' => 1]);
        $subscribe->activate($subscribe->claim($vendor, $with));

        $this->actingAs($vendor, 'customer')
            ->get(route('marketplace.vendor.discounts.index'))
            ->assertOk();
    }

    public function test_feature_gating_is_inert_in_commission_mode(): void
    {
        $vendor = $this->createVendor();
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();

        $this->actingAs($vendor, 'customer')
            ->get(route('marketplace.vendor.discounts.index'))
            ->assertOk();
    }

    public function test_saving_the_settings_form_keeps_both_modes_values(): void
    {
        // Since the mode sections became collapsibles, every field posts on every save.
        // Neither mode's settings may be lost when the other one is active.
        $payload = [
            'mode' => MarketplaceModeEnum::SUBSCRIPTION,
            'fee_per_order' => 12,
            'fee_per_order_fixed' => 3,
            'subscription_grace_period_days' => 5,
            'subscription_reminder_days' => '10,2',
            'payout_methods' => ['bank_transfer' => 1],
            'withdrawal_fee_type' => WithdrawalFeeTypeEnum::FIXED,
        ];

        $this->actingAs($this->admin, 'web')
            ->put(route('marketplace.settings.update'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertTrue(MarketplaceHelper::isSubscriptionMode());
        $this->assertSame(5, MarketplaceHelper::subscriptionGracePeriodDays());
        $this->assertSame([10, 2], MarketplaceHelper::subscriptionReminderDays());
        // Commission values survive even though commission is not the active mode.
        $this->assertEquals(12, MarketplaceHelper::getSetting('fee_per_order'));
        $this->assertEquals(3, MarketplaceHelper::getSetting('fee_per_order_fixed'));

        $this->actingAs($this->admin, 'web')
            ->put(route('marketplace.settings.update'), array_merge($payload, [
                'mode' => MarketplaceModeEnum::COMMISSION,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue(MarketplaceHelper::isCommissionMode());
        // ...and subscription values survive the switch back.
        $this->assertSame(5, MarketplaceHelper::subscriptionGracePeriodDays());
    }

    public function test_the_settings_form_rejects_an_unknown_mode(): void
    {
        $this->actingAs($this->admin, 'web')
            ->put(route('marketplace.settings.update'), [
                'mode' => 'not-a-mode',
                'payout_methods' => ['bank_transfer' => 1],
                'withdrawal_fee_type' => WithdrawalFeeTypeEnum::FIXED,
            ])
            ->assertSessionHasErrors('mode');
    }
}
