<?php

namespace Botble\Ecommerce\Tests\Feature;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Base\Supports\BaseTestCase;
use Botble\Captcha\Facades\Captcha;
use Botble\Ecommerce\Facades\Cart;
use Botble\Ecommerce\Facades\OrderHelper;
use Botble\Ecommerce\Forms\Fronts\CheckoutForm;
use Botble\Ecommerce\Http\Requests\CheckoutRequest;
use Botble\Ecommerce\Http\Requests\SaveCheckoutInformationRequest;
use Botble\Ecommerce\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

class CheckoutCaptchaTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cart::instance('cart')->destroy();

        setting()->set([
            'enable_math_captcha' => '1',
            Captcha::formSettingKey(CheckoutForm::class, 'enable_math_captcha') => '1',
        ]);

        Captcha::getFormsSupport();
    }

    public function test_checkout_form_is_registered_for_captcha(): void
    {
        $this->assertArrayHasKey(CheckoutForm::class, Captcha::getFormsSupport());
        $this->assertEquals(CheckoutForm::class, Captcha::formByRequest(CheckoutRequest::class));
    }

    public function test_math_captcha_field_is_rendered_on_the_checkout_page(): void
    {
        $this->getCheckoutPage()->assertSee('math-captcha', false);
    }

    public function test_recaptcha_v2_renders_widget_and_api_script_on_the_checkout_page(): void
    {
        $this->enableReCaptcha('v2');

        $this
            ->getCheckoutPage()
            ->assertSee('g-recaptcha', false)
            // The placeholder alone is useless - without the API script Google never turns
            // it into a widget, so the customer has nothing to solve.
            ->assertSee('recaptcha/api.js', false);
    }

    public function test_recaptcha_v3_renders_the_api_script_on_the_checkout_page(): void
    {
        $this->enableReCaptcha('v3');

        $this
            ->getCheckoutPage()
            ->assertSee('g-recaptcha-response', false)
            // The checkout page uses its own layout and only applies ecommerce_checkout_footer,
            // not THEME_FRONT_FOOTER. When v3 hooked THEME_FRONT_FOOTER alone these scripts were
            // silently dropped, the token input stayed empty and every order was rejected.
            ->assertSee('recaptcha/api.js', false)
            ->assertSee('recaptchaInputs.push', false)
            ->assertSee('var refreshRecaptcha', false);
    }

    public function test_captcha_rule_is_applied_to_the_checkout_request_only(): void
    {
        // The captcha plugin registers its rules filter when routing starts.
        $this->get(route('public.index'));

        $this->assertArrayHasKey(
            'math-captcha',
            apply_filters('core_request_rules', [], new CheckoutRequest())
        );

        // The AJAX request that refreshes the checkout summary must stay captcha free,
        // otherwise every shipping / coupon update would fail validation.
        $this->assertArrayNotHasKey(
            'math-captcha',
            apply_filters('core_request_rules', [], new SaveCheckoutInformationRequest())
        );
    }

    protected function enableReCaptcha(string $type): void
    {
        setting()->set([
            'enable_math_captcha' => '0',
            'enable_captcha' => '1',
            'captcha_type' => $type,
            'captcha_site_key' => 'test-site-key',
            'captcha_secret' => 'test-secret',
            Captcha::formSettingKey(CheckoutForm::class, 'enable_recaptcha') => '1',
        ]);

        // The keys are injected when the singleton is built, so drop the cached instance.
        $this->app->forgetInstance('captcha');
        Captcha::clearResolvedInstance('captcha');
    }

    protected function getCheckoutPage(): TestResponse
    {
        $product = Product::query()->create([
            'name' => 'Test Product',
            'price' => 100,
            'status' => BaseStatusEnum::PUBLISHED,
        ]);

        // Go through the real route so the cart lives in the same session as the
        // checkout request that follows.
        $this->get(route('public.cart.add-by-url', $product->id))->assertRedirect();

        return $this
            ->get(route('public.checkout.information', OrderHelper::getOrderSessionToken()))
            ->assertOk();
    }
}
