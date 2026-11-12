<?php

namespace Botble\Ecommerce\Tests\Feature;

use Botble\Base\Supports\BaseTestCase;
use Botble\Ecommerce\Enums\OrderStatusEnum;
use Botble\Ecommerce\Enums\ShippingMethodEnum;
use Botble\Ecommerce\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class OrderCodeRaceConditionTest extends BaseTestCase
{
    use RefreshDatabase;

    protected function orderData(): array
    {
        return [
            'amount' => 100,
            'sub_total' => 100,
            'status' => OrderStatusEnum::PENDING,
            'shipping_method' => ShippingMethodEnum::DEFAULT,
            'is_finished' => false,
        ];
    }

    /**
     * Simulates a concurrent request storing an order with the very same code right after
     * this one generated it but before it got inserted.
     */
    protected function stealGeneratedCode(int $times, array &$stolen): void
    {
        // Boot the model first so its own code generator runs before this listener.
        new Order();

        Order::creating(function (Order $order) use ($times, &$stolen): void {
            if (count($stolen) >= $times) {
                return;
            }

            $stolen[] = $order->code;

            DB::table('ec_orders')->insert([
                'amount' => 100,
                'sub_total' => 100,
                'status' => OrderStatusEnum::PENDING,
                'shipping_method' => ShippingMethodEnum::DEFAULT,
                'is_finished' => false,
                'code' => $order->code,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_it_retries_with_a_new_code_when_another_request_took_the_generated_one(): void
    {
        $stolen = [];

        $this->stealGeneratedCode(1, $stolen);

        $order = Order::query()->create($this->orderData());

        $this->assertTrue($order->exists);
        $this->assertCount(1, $stolen);
        $this->assertNotContains($order->code, $stolen);
        $this->assertSame(1, Order::query()->where('code', $order->code)->count());
    }

    public function test_it_gives_up_instead_of_looping_forever(): void
    {
        $stolen = [];

        $this->stealGeneratedCode(PHP_INT_MAX, $stolen);

        $this->expectException(UniqueConstraintViolationException::class);

        try {
            Order::query()->create($this->orderData());
        } finally {
            $this->assertLessThanOrEqual(5, count($stolen));
        }
    }
}
