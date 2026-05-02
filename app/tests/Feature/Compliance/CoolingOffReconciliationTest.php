<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderCoolingOff;
use App\Modules\Commerce\Services\OrderStateMachine;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CoolingOffReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LedgerAccountSeeder::class);
    }

    public function test_delivering_an_order_opens_a_cooling_off_clock(): void
    {
        $order = $this->orderInStatus(Order::STATUS_SHIPPED);

        app(OrderStateMachine::class)->markDelivered($order);

        $order->refresh();
        $this->assertSame(Order::STATUS_DELIVERED, $order->status);
        $this->assertNotNull($order->coolingOff);
        $this->assertSame(OrderCoolingOff::STATUS_OPEN, $order->coolingOff->status);
        $this->assertTrue($order->coolingOff->ends_at->greaterThan(now()->addDays(29)));
    }

    public function test_cooling_off_clock_is_exactly_30_days(): void
    {
        $order = $this->orderInStatus(Order::STATUS_SHIPPED);
        $coolingOff = app(OrderStateMachine::class)->markDelivered($order);

        $diff = $coolingOff->opened_at->diffInDays($coolingOff->ends_at);
        $this->assertSame(30, (int) $diff);
    }

    public function test_expiring_past_cooling_off_marks_expired_and_confirms_order(): void
    {
        $order = $this->orderInStatus(Order::STATUS_SHIPPED);
        $coolingOff = app(OrderStateMachine::class)->markDelivered($order);

        // Fast-forward: set ends_at to the past
        $coolingOff->update(['ends_at' => now()->subDay()]);

        app(OrderStateMachine::class)->expireCoolingOff($order);

        $order->refresh();
        $this->assertSame(Order::STATUS_CONFIRMED, $order->status);
        $this->assertSame(OrderCoolingOff::STATUS_EXPIRED, $order->coolingOff->fresh()->status);
    }

    private function orderInStatus(string $status): Order
    {
        $customer = Customer::create(['display_name' => 'Test Buyer']);

        $order = Order::create([
            'order_no' => 'ORD-TEST-'.uniqid(),
            'customer_id' => $customer->id,
            'attribution_source' => 'direct',
            'status' => $status,
            'subtotal_paise' => 29500,
            'gst_paise' => 4500,
            'total_paise' => 29500,
            'idempotency_key' => uniqid('key-'),
            'placed_at' => now()->subDays(4),
            'paid_at' => now()->subDays(4),
            'shipped_at' => now()->subDays(2),
        ]);

        return $order;
    }
}
