<?php

namespace Botble\Ecommerce\Tests\Feature;

use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Enums\CustomerStatusEnum;
use Botble\Ecommerce\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CustomerLoginRedirectTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        setting()->forceSet('ecommerce_login_option', 'email')->save();
    }

    protected function createCustomer(): Customer
    {
        return Customer::query()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => bcrypt('password123'),
            'status' => CustomerStatusEnum::ACTIVATED,
            'confirmed_at' => now(),
        ]);
    }

    public function test_login_returns_customer_to_the_intended_url(): void
    {
        $customer = $this->createCustomer();
        $intended = url('/products/free-sample');

        $response = $this
            ->withSession(['url.intended' => $intended])
            ->post(route('customer.login.post'), [
                'email' => 'john@example.com',
                'password' => 'password123',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect($intended);
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_login_does_not_land_on_the_account_overview_when_an_intended_url_exists(): void
    {
        $this->createCustomer();
        $intended = url('/products/free-sample');

        $response = $this
            ->withSession(['url.intended' => $intended])
            ->post(route('customer.login.post'), [
                'email' => 'john@example.com',
                'password' => 'password123',
            ]);

        $this->assertNotSame(route('customer.overview'), $response->headers->get('Location'));
    }

    public function test_login_remembers_the_email_for_the_next_visit(): void
    {
        $this->createCustomer();

        $response = $this->post(route('customer.login.post'), [
            'email' => 'john@example.com',
            'password' => 'password123',
        ]);

        $response->assertCookie('customer_remember_email', 'john@example.com');
    }

    public function test_failed_login_keeps_the_customer_a_guest(): void
    {
        $this->createCustomer();

        $response = $this->post(route('customer.login.post'), [
            'email' => 'john@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors();
        $this->assertGuest('customer');
    }
}
