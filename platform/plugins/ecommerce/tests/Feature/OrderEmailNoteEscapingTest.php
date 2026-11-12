<?php

namespace Botble\Ecommerce\Tests\Feature;

use Botble\Base\Facades\EmailHandler;
use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Facades\OrderHelper;
use Botble\Ecommerce\Models\Order;
use Botble\Ecommerce\Models\OrderAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The order note is plain text typed by the customer at checkout. Email templates render with
 * autoescape off, so it must be escaped before reaching the (customer and admin) inboxes.
 */
class OrderEmailNoteEscapingTest extends BaseTestCase
{
    use RefreshDatabase;

    protected const NOTE = 'Tom & Jerry <a href="https://evil.test/refund">Get your refund</a><img src="https://tracker.test/p.gif">';

    protected function createOrder(): Order
    {
        $order = Order::query()->create([
            'amount' => 100,
            'sub_total' => 100,
            'description' => self::NOTE,
        ]);

        OrderAddress::query()->create([
            'order_id' => $order->getKey(),
            'name' => 'Jane Buyer',
            'email' => 'jane@example.com',
            'phone' => '0123456789',
            'address' => '1 Main St',
        ]);

        return $order->refresh();
    }

    public function test_order_note_email_variable_is_escaped(): void
    {
        $variables = OrderHelper::getEmailVariables($this->createOrder());

        $this->assertSame(e(self::NOTE), $variables['order_note']);
        $this->assertStringNotContainsString('<a ', $variables['order_note']);
    }

    public function test_order_note_is_rendered_as_text_in_cancellation_email(): void
    {
        $handler = OrderHelper::setEmailVariables($this->createOrder());

        $html = $handler->setType('plugins')
            ->setModule(ECOMMERCE_MODULE_SCREEN_NAME)
            ->setTemplate('order_cancellation_to_admin')
            ->prepareData(get_setting_email_template_content('plugins', ECOMMERCE_MODULE_SCREEN_NAME, 'order_cancellation_to_admin'));

        $this->assertStringContainsString('Tom &amp; Jerry &lt;a href=&quot;https://evil.test/refund&quot;&gt;', $html);
        $this->assertStringNotContainsString('<a href="https://evil.test/refund">', $html);
        $this->assertStringNotContainsString('<img src="https://tracker.test/p.gif">', $html);
    }

    protected function tearDown(): void
    {
        EmailHandler::setVariableValues([], ECOMMERCE_MODULE_SCREEN_NAME);

        parent::tearDown();
    }
}
