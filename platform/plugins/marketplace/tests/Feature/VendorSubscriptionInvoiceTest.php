<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Models\Customer;
use Botble\Ecommerce\Models\Tax;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Enums\SubscriptionInvoiceStatusEnum;
use Botble\Marketplace\Enums\StoreStatusEnum;
use Botble\Marketplace\Models\Store;
use Botble\Marketplace\Models\VendorInfo;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Models\VendorSubscription;
use Botble\Marketplace\Models\VendorSubscriptionInvoice;
use Botble\Marketplace\Services\SubscribeVendorService;
use Botble\Marketplace\Services\SubscriptionBillingService;
use Botble\Marketplace\Services\SubscriptionRenewalService;
use Botble\Marketplace\Services\SubscriptionTaxService;
use Botble\Setting\Facades\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Covers the billing paperwork: tax resolution, the frozen invoice snapshot, and the
 * authorization on the PDF download.
 */
class VendorSubscriptionInvoiceTest extends BaseTestCase
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
        // Settings are a process-wide singleton that RefreshDatabase does not roll back.
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();
        Setting::forget('marketplace_subscription_tax_enabled');
        Setting::forget('ecommerce_default_tax_rate');
        Setting::forget('ecommerce_ecommerce_tax_enabled');
        Setting::save();

        parent::tearDown();
    }

    protected function createVendor(): Customer
    {
        $customer = Customer::query()->create([
            'name' => 'Invoice Vendor',
            'email' => 'invoice-vendor-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        // is_vendor is a marketplace-added column outside Customer's $fillable.
        $customer->is_vendor = true;
        $customer->save();

        Store::query()->create([
            'name' => 'Invoice Store',
            'email' => 'invoice-store-' . uniqid() . '@example.com',
            'customer_id' => $customer->id,
            'status' => StoreStatusEnum::PUBLISHED,
        ]);

        return $customer->refresh();
    }

    protected function createPlan(float $price = 100): SubscriptionPlan
    {
        $plan = new SubscriptionPlan([
            'name' => 'Pro',
            'price' => $price,
            'duration_value' => 1,
            'duration_unit' => 'month',
            'status' => BaseStatusEnum::PUBLISHED,
        ]);

        $plan->fillOptions(SubscriptionPlan::defaultOptions());
        $plan->save();

        return $plan;
    }

    protected function enableTax(float $percentage = 10, array $rule = []): Tax
    {
        // get_ecommerce_setting() prefixes with 'ecommerce_', so the stored key for
        // EcommerceHelper::isTaxEnabled() is ecommerce_ecommerce_tax_enabled.
        Setting::set('ecommerce_ecommerce_tax_enabled', 1)->save();
        Setting::set('marketplace_subscription_tax_enabled', 1)->save();

        $tax = Tax::query()->create([
            'title' => 'VAT',
            'percentage' => $percentage,
            'status' => BaseStatusEnum::PUBLISHED,
        ]);

        if ($rule) {
            $tax->rules()->create(array_merge([
                'is_enabled' => true,
                'priority' => 0,
            ], $rule));
        }

        Setting::set('ecommerce_default_tax_rate', $tax->getKey())->save();

        return $tax;
    }

    protected function billing(array $overrides = []): array
    {
        return array_merge([
            'name' => 'ACME Ltd',
            'email' => 'billing@acme.test',
            'phone' => '+15550100',
            'address' => '1 Example Street',
            'country' => 'US',
            'state' => 'California',
            'city' => 'San Francisco',
            'zip_code' => '94103',
            'tax_id' => 'US123456789',
        ], $overrides);
    }

    public function test_tax_is_zero_when_the_marketplace_toggle_is_off(): void
    {
        $this->enableTax();
        Setting::set('marketplace_subscription_tax_enabled', 0)->save();

        $this->assertFalse(app(SubscriptionTaxService::class)->isEnabled());
        $this->assertSame(
            ['rate' => 0.0, 'amount' => 0.0],
            app(SubscriptionTaxService::class)->calculate(100, $this->billing())
        );
    }

    public function test_tax_is_zero_when_the_store_toggle_is_off(): void
    {
        $this->enableTax();
        Setting::set('ecommerce_ecommerce_tax_enabled', 0)->save();

        $this->assertFalse(app(SubscriptionTaxService::class)->isEnabled());
    }

    public function test_tax_falls_back_to_the_tax_percentage_when_no_rule_matches(): void
    {
        $this->enableTax(15);

        $result = app(SubscriptionTaxService::class)->calculate(200, $this->billing());

        $this->assertSame(15.0, $result['rate']);
        $this->assertSame(30.0, $result['amount']);
    }

    public function test_a_country_rule_overrides_the_tax_percentage(): void
    {
        $this->enableTax(10, ['country' => 'US', 'percentage' => 7]);

        $result = app(SubscriptionTaxService::class)->calculate(100, $this->billing());

        $this->assertSame(7.0, $result['rate']);
        $this->assertSame(7.0, $result['amount']);
    }

    public function test_a_country_and_state_rule_beats_a_country_only_rule(): void
    {
        $tax = $this->enableTax(10, ['country' => 'US', 'percentage' => 7]);

        $tax->rules()->create([
            'country' => 'US',
            'state' => 'California',
            'percentage' => 9,
            'is_enabled' => true,
            'priority' => 0,
        ]);

        $result = app(SubscriptionTaxService::class)->calculate(100, $this->billing());

        $this->assertSame(9.0, $result['rate']);
    }

    public function test_activation_issues_one_invoice_whose_totals_reconcile(): void
    {
        $this->enableTax(10);

        $vendor = $this->createVendor();
        $plan = $this->createPlan(100);

        $service = app(SubscribeVendorService::class);
        $subscription = $service->claim($vendor, $plan, ['billing_data' => $this->billing()]);

        $this->assertSame(100.0, (float) $subscription->sub_total);
        $this->assertSame(10.0, (float) $subscription->tax_amount);
        $this->assertSame(110.0, (float) $subscription->amount);

        $service->activate($subscription);

        $invoices = VendorSubscriptionInvoice::query()
            ->where('vendor_subscription_id', $subscription->getKey())
            ->get();

        $this->assertCount(1, $invoices);

        $invoice = $invoices->first();

        $this->assertSame(110.0, (float) $invoice->amount);
        $this->assertSame(
            round($invoice->sub_total + $invoice->tax_amount, 2),
            round((float) $invoice->amount, 2)
        );
        $this->assertEquals(SubscriptionInvoiceStatusEnum::PAID, $invoice->status);
        $this->assertSame('ACME Ltd', $invoice->billing_name);
        $this->assertSame('US123456789', $invoice->billing_tax_id);
    }

    public function test_an_issued_invoice_does_not_change_when_the_admin_edits_the_tax_rate(): void
    {
        $tax = $this->enableTax(10);

        $vendor = $this->createVendor();
        $service = app(SubscribeVendorService::class);
        $subscription = $service->claim($vendor, $this->createPlan(100), ['billing_data' => $this->billing()]);
        $service->activate($subscription);

        $invoice = VendorSubscriptionInvoice::query()->latest('id')->firstOrFail();

        $tax->fill(['percentage' => 25])->save();

        $this->assertSame(10.0, (float) $invoice->refresh()->tax_rate);
        $this->assertSame(10.0, (float) $invoice->tax_amount);
        $this->assertSame(110.0, (float) $invoice->amount);
    }

    public function test_the_free_default_plan_is_not_invoiced(): void
    {
        $vendor = $this->createVendor();

        $plan = new SubscriptionPlan([
            'name' => 'Free',
            'price' => 0,
            'duration_value' => 1,
            'duration_unit' => 'month',
            'status' => BaseStatusEnum::PUBLISHED,
        ]);
        $plan->fillOptions(SubscriptionPlan::defaultOptions());
        $plan->save();

        app(SubscribeVendorService::class)->activateFreePlan($vendor, $plan);

        $this->assertSame(0, VendorSubscriptionInvoice::query()->count());
    }

    public function test_invoice_codes_use_their_own_series(): void
    {
        $vendor = $this->createVendor();
        $plan = $this->createPlan(50);
        $service = app(SubscribeVendorService::class);

        foreach (range(1, 2) as $ignored) {
            $service->activate($service->claim($vendor, $plan, ['billing_data' => $this->billing()]));
        }

        $codes = VendorSubscriptionInvoice::query()->orderBy('id')->pluck('code')->all();

        $this->assertSame(['SUB-000001', 'SUB-000002'], $codes);
    }

    public function test_a_vendor_cannot_download_another_vendors_invoice(): void
    {
        $owner = $this->createVendor();
        $intruder = $this->createVendor();

        $service = app(SubscribeVendorService::class);
        $subscription = $service->claim($owner, $this->createPlan(50), ['billing_data' => $this->billing()]);
        $service->activate($subscription);

        $invoice = VendorSubscriptionInvoice::query()->latest('id')->firstOrFail();

        $this->actingAs($intruder, 'customer')
            ->get(route('marketplace.vendor.subscriptions.invoices.download', $invoice->getKey()))
            ->assertNotFound();
    }

    public function test_a_vendor_can_download_their_own_invoice(): void
    {
        $vendor = $this->createVendor();

        $service = app(SubscribeVendorService::class);
        $subscription = $service->claim($vendor, $this->createPlan(50), ['billing_data' => $this->billing()]);
        $service->activate($subscription);

        $invoice = VendorSubscriptionInvoice::query()->latest('id')->firstOrFail();

        $response = $this->actingAs($vendor, 'customer')
            ->get(route('marketplace.vendor.subscriptions.invoices.download', $invoice->getKey()));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_billing_falls_back_to_the_vendors_default_address(): void
    {
        $vendor = $this->createVendor();

        $vendor->addresses()->create([
            'name' => 'Fallback Name',
            'email' => 'fallback@example.test',
            'phone' => '+15550199',
            'address' => '9 Fallback Road',
            'country' => 'US',
            'state' => 'Texas',
            'city' => 'Austin',
            'zip_code' => '73301',
            'is_default' => true,
        ]);

        $service = app(SubscribeVendorService::class);
        $service->activate($service->claim($vendor, $this->createPlan(50)));

        $invoice = VendorSubscriptionInvoice::query()->latest('id')->firstOrFail();

        $this->assertSame('Fallback Name', $invoice->billing_name);
        $this->assertSame('Austin', $invoice->billing_city);
    }

    public function test_a_renewal_reuses_the_original_billing_and_tax(): void
    {
        $this->enableTax(10);

        $vendor = $this->createVendor();
        $plan = $this->createPlan(100);

        $service = app(SubscribeVendorService::class);
        $original = $service->activate($service->claim($vendor, $plan, ['billing_data' => $this->billing()]));

        // Enough balance for the gross charge, so auto-renew can go through.
        $this->creditBalance($vendor, 500);

        $original->fill(['auto_renew' => true, 'ends_at' => now()->addDay()])->save();

        $this->assertTrue(app(SubscriptionRenewalService::class)->autoRenew($original->refresh()));

        $renewal = VendorSubscription::query()->latest('id')->firstOrFail();

        $this->assertSame(110.0, (float) $renewal->amount);
        $this->assertSame('ACME Ltd', ($renewal->billing_data)['name']);

        $invoice = VendorSubscriptionInvoice::query()->latest('id')->firstOrFail();

        $this->assertSame(
            trans('plugins/marketplace::subscription.invoices.titles.renewed'),
            $invoice->title
        );
        $this->assertSame('ACME Ltd', $invoice->billing_name);
    }

    public function test_auto_renew_is_refused_when_the_balance_covers_only_the_untaxed_price(): void
    {
        $this->enableTax(10);

        $vendor = $this->createVendor();

        $service = app(SubscribeVendorService::class);
        $original = $service->activate(
            $service->claim($vendor, $this->createPlan(100), ['billing_data' => $this->billing()])
        );

        // 105 covers the 100 plan price but not the 110 gross charge.
        $this->creditBalance($vendor, 105);

        $original->fill(['auto_renew' => true, 'ends_at' => now()->addDay()])->save();

        $this->assertFalse(app(SubscriptionRenewalService::class)->autoRenew($original->refresh()));

        // And nothing half-made was left behind.
        $this->assertSame(1, VendorSubscription::query()->count());
    }

    /**
     * createVendor() makes no VendorInfo row, and the balance payment path needs one to
     * lock and debit.
     */
    protected function creditBalance(Customer $vendor, float $amount): void
    {
        $info = VendorInfo::query()->firstOrNew(['customer_id' => $vendor->getKey()]);
        $info->customer_id = $vendor->getKey();
        $info->balance = $amount;
        $info->save();

        $vendor->unsetRelation('vendorInfo');
    }

    public function test_the_checkout_page_renders_the_billing_form_and_the_tax_line(): void
    {
        $this->enableTax(10);

        $vendor = $this->createVendor();
        $plan = $this->createPlan(100);

        $vendor->addresses()->create([
            'name' => 'Prefill Name',
            'email' => 'prefill@example.test',
            'phone' => '+15550111',
            'address' => '4 Prefill Way',
            'country' => 'US',
            'state' => 'California',
            'city' => 'San Francisco',
            'zip_code' => '94103',
            'is_default' => true,
        ]);

        $this->actingAs($vendor, 'customer')
            ->get(route('marketplace.vendor.subscriptions.checkout', $plan->getKey()))
            ->assertOk()
            ->assertSee(trans('plugins/marketplace::subscription.billing.title'))
            // Prefilled from the default address, and the tax is priced before submit.
            ->assertSee('Prefill Name', false)
            ->assertSee(trans('plugins/marketplace::subscription.billing.total'));
    }

    public function test_checkout_snapshots_the_submitted_billing_and_can_save_the_address(): void
    {
        $vendor = $this->createVendor();
        $plan = $this->createPlan(100);

        $this->actingAs($vendor, 'customer')
            ->post(route('marketplace.vendor.subscriptions.process-checkout', $plan->getKey()), [
                'payment_method' => 'bank_transfer',
                'billing_name' => 'Submitted Name',
                'billing_email' => 'submitted@example.test',
                'billing_phone' => '+15550122',
                'billing_address' => '7 Submitted Lane',
                'billing_country' => 'US',
                'billing_state' => 'Texas',
                'billing_city' => 'Austin',
                'billing_zip_code' => '73301',
                'billing_tax_id' => 'TX999',
                'billing_save_address' => '1',
            ])
            ->assertRedirect();

        $subscription = VendorSubscription::query()->latest('id')->firstOrFail();

        $this->assertSame('Submitted Name', ($subscription->billing_data)['name']);
        $this->assertSame('TX999', ($subscription->billing_data)['tax_id']);

        // 'save as my default address' wrote it back to the vendor's address book.
        $address = $vendor->addresses()->where('is_default', true)->first();

        $this->assertNotNull($address);
        $this->assertSame('Austin', $address->city);
    }

    public function test_checkout_prefills_the_tax_id_from_the_previous_subscription(): void
    {
        $vendor = $this->createVendor();

        $service = app(SubscribeVendorService::class);
        $service->activate($service->claim($vendor, $this->createPlan(50), [
            'billing_data' => $this->billing(),
        ]));

        // The address book has no tax_id column, so only the previous billing block can
        // carry it forward.
        $prefilled = app(SubscriptionBillingService::class)->prefilled($vendor->refresh());

        $this->assertSame('US123456789', $prefilled['tax_id']);
        $this->assertSame('ACME Ltd', $prefilled['name']);
    }

    public function test_a_pending_subscription_produces_no_invoice_until_activated(): void
    {
        $vendor = $this->createVendor();

        app(SubscribeVendorService::class)->claim($vendor, $this->createPlan(50), [
            'billing_data' => $this->billing(),
        ]);

        $this->assertSame(1, VendorSubscription::query()->count());
        $this->assertSame(0, VendorSubscriptionInvoice::query()->count());
    }
}
