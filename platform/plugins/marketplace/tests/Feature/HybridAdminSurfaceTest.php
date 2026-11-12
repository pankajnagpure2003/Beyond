<?php

namespace Botble\Marketplace\Tests\Feature;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Supports\BaseTestCase;
use Botble\Marketplace\Enums\MarketplaceModeEnum;
use Botble\Marketplace\Facades\MarketplaceHelper;
use Botble\Marketplace\Forms\SubscriptionPlanForm;
use Botble\Marketplace\Http\Requests\MarketPlaceSettingFormRequest;
use Botble\Marketplace\Http\Requests\SubscriptionPlanRequest;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Marketplace\Services\VendorSubscriptionService;
use Botble\Setting\Facades\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

/**
 * The admin-facing surface of hybrid mode: which fields render in which mode, what the
 * request rules accept, and the default-plan warning condition.
 *
 * Also guards the getSetting() float regression - see the test for why it matters.
 */
class HybridAdminSurfaceTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Setting::set('marketplace_mode', MarketplaceModeEnum::COMMISSION)->save();
        Setting::forget('marketplace_fee_per_order');
        Setting::forget('marketplace_fee_per_order_fixed');
        Setting::save();

        parent::tearDown();
    }

    protected function setMode(string $mode): void
    {
        Setting::load(true);
        Setting::set('marketplace_mode', $mode);
        Setting::save();
        Setting::load(true);
        $this->app->forgetScopedInstances();
    }

    /** @return array<int, string> */
    protected function planFormFieldNames(): array
    {
        return array_keys(SubscriptionPlanForm::create()->getFields());
    }

    // ------------------------------------------------------- getSetting float regression

    /**
     * getSetting() once declared its return as string|int|array|null|bool, omitting float,
     * so a setting still held in memory as a float was implicitly truncated - a 4.5%
     * commission rate silently became 4%. Production reads settings back from the database
     * as strings, which is why it went unnoticed, but the coercion is lossy and commission
     * rates are decimals. This asserts the value survives.
     */
    public function test_a_float_setting_is_not_truncated(): void
    {
        Setting::set('marketplace_fee_per_order', 4.5);

        $value = MarketplaceHelper::getSetting('fee_per_order', 0);

        $this->assertEqualsWithDelta(4.5, (float) $value, 0.0001);
        $this->assertNotSame(4, $value, 'A float rate must not be coerced to an int.');
    }

    public function test_a_string_setting_still_reads_back_unchanged(): void
    {
        Setting::set('marketplace_fee_per_order', '4.5');

        $this->assertEqualsWithDelta(4.5, (float) MarketplaceHelper::getSetting('fee_per_order', 0), 0.0001);
    }

    // ------------------------------------------------------------------ plan form fields

    public function test_commission_override_fields_appear_only_in_hybrid_mode(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $fields = $this->planFormFieldNames();

        $this->assertContains('options[commission_percentage]', $fields);
        $this->assertContains('options[commission_fixed]', $fields);
        $this->assertContains('commission_override_title', $fields);
    }

    public function test_commission_override_fields_are_absent_in_commission_mode(): void
    {
        $this->setMode(MarketplaceModeEnum::COMMISSION);
        $fields = $this->planFormFieldNames();

        $this->assertNotContains('options[commission_percentage]', $fields);
        $this->assertNotContains('options[commission_fixed]', $fields);
    }

    public function test_commission_override_fields_are_absent_in_subscription_mode(): void
    {
        $this->setMode(MarketplaceModeEnum::SUBSCRIPTION);
        $fields = $this->planFormFieldNames();

        $this->assertNotContains('options[commission_percentage]', $fields);
        $this->assertNotContains('options[commission_fixed]', $fields);
    }

    /**
     * The quota fields are a fixed whitelist; adding the commission options must not have
     * leaked them into the ordinary option loop in the other two modes.
     */
    public function test_quota_fields_render_in_every_mode(): void
    {
        foreach ([MarketplaceModeEnum::COMMISSION, MarketplaceModeEnum::SUBSCRIPTION, MarketplaceModeEnum::HYBRID] as $mode) {
            $this->setMode($mode);
            $fields = $this->planFormFieldNames();

            $this->assertContains('options[product_limit]', $fields, "product_limit missing in $mode");
            $this->assertContains('options[listing_priority]', $fields, "listing_priority missing in $mode");
        }
    }

    // ------------------------------------------------------------------------ validation

    /** @param array<string, mixed> $options */
    protected function planValidator(array $options): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make(
            [
                'name' => 'Plan',
                'price' => 10,
                'duration_value' => 1,
                'duration_unit' => 'month',
                'status' => BaseStatusEnum::PUBLISHED,
                'options' => $options,
            ],
            (new SubscriptionPlanRequest())->rules()
        );
    }

    public function test_a_decimal_commission_percentage_is_accepted(): void
    {
        $this->assertTrue($this->planValidator(['commission_percentage' => 4.5])->passes());
    }

    public function test_the_inherit_sentinel_is_accepted(): void
    {
        $this->assertTrue($this->planValidator(['commission_percentage' => -1, 'commission_fixed' => -1])->passes());
    }

    public function test_a_commission_percentage_above_one_hundred_is_rejected(): void
    {
        $validator = $this->planValidator(['commission_percentage' => 101]);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('options.commission_percentage', $validator->errors()->toArray());
    }

    public function test_a_commission_percentage_below_the_sentinel_is_rejected(): void
    {
        $this->assertFalse($this->planValidator(['commission_percentage' => -2])->passes());
    }

    public function test_a_decimal_quota_is_still_rejected_as_non_integer(): void
    {
        // Only the commission_* keys became numeric; quotas stay integers.
        $this->assertFalse($this->planValidator(['product_limit' => 2.5])->passes());
    }

    public function test_hybrid_is_an_accepted_marketplace_mode(): void
    {
        $rules = (new MarketPlaceSettingFormRequest())->rules();

        foreach ([MarketplaceModeEnum::COMMISSION, MarketplaceModeEnum::SUBSCRIPTION, MarketplaceModeEnum::HYBRID] as $mode) {
            $this->assertTrue(
                Validator::make(['mode' => $mode], ['mode' => $rules['mode']])->passes(),
                "$mode should be accepted"
            );
        }

        $this->assertFalse(Validator::make(['mode' => 'nonsense'], ['mode' => $rules['mode']])->passes());
    }

    // ------------------------------------------------------------- default-plan condition

    /**
     * Switching into a subscription-bearing mode with no published default plan leaves every
     * vendor without one unable to publish. This is the condition the settings controller
     * warns on.
     */
    protected function warnsAboutMissingDefaultPlan(): bool
    {
        return MarketplaceHelper::hasVendorSubscriptions()
            && ! app(VendorSubscriptionService::class)->defaultPlan();
    }

    public function test_warns_for_subscription_bearing_modes_when_no_default_plan_exists(): void
    {
        $this->setMode(MarketplaceModeEnum::HYBRID);
        $this->assertTrue($this->warnsAboutMissingDefaultPlan());

        $this->setMode(MarketplaceModeEnum::SUBSCRIPTION);
        $this->assertTrue($this->warnsAboutMissingDefaultPlan());
    }

    public function test_never_warns_in_commission_mode(): void
    {
        $this->setMode(MarketplaceModeEnum::COMMISSION);

        $this->assertFalse($this->warnsAboutMissingDefaultPlan());
    }

    public function test_does_not_warn_once_a_published_default_plan_exists(): void
    {
        SubscriptionPlan::query()->create([
            'name' => 'Free default',
            'price' => 0,
            'duration_value' => 1,
            'duration_unit' => 'month',
            'is_default' => true,
            'status' => BaseStatusEnum::PUBLISHED,
        ]);

        $this->setMode(MarketplaceModeEnum::HYBRID);

        $this->assertFalse($this->warnsAboutMissingDefaultPlan());
    }

    /**
     * A draft default plan does not count - canPublishProducts() only accepts a published one.
     */
    public function test_a_draft_default_plan_does_not_satisfy_the_warning(): void
    {
        $plan = SubscriptionPlan::query()->create([
            'name' => 'Draft default',
            'price' => 0,
            'duration_value' => 1,
            'duration_unit' => 'month',
            'is_default' => true,
        ]);
        $plan->status = BaseStatusEnum::DRAFT;
        $plan->save();

        $this->setMode(MarketplaceModeEnum::HYBRID);

        $this->assertTrue($this->warnsAboutMissingDefaultPlan());
    }
}
