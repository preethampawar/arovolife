<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderCoolingOff;
use App\Modules\Returns\Events\OrderRefundApproved;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Returns\Services\OpenReturn;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(LedgerAccountSeeder::class);
    DB::table('settings')->updateOrInsert(
        ['key' => 'commerce.self_purchase.earns_bv'],
        ['value' => 'false', 'version' => 1, 'updated_at' => now()],
    );
});

/** Minimal delivered order + customer for OpenReturn tests. */
function openReturnFixture(string $orderStatus = Order::STATUS_DELIVERED, bool $coolingOffOpen = true, ?int $deliveredDaysAgo = 5): array
{
    $userId = DB::table('users')->insertGetId([
        'full_name' => 'ORT User', 'email' => 'ort-'.uniqid().'@test.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('x'), 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $customer = Customer::create([
        'display_name' => 'ORT Customer',
        'user_id' => $userId,
        'email_hash' => hash('sha256', 'ort-'.$userId.'@test.com'),
        'email_enc' => 'ort-'.$userId.'@test.com',
        'claimed_at' => now(),
    ]);

    $orderId = DB::table('orders')->insertGetId([
        'order_no' => 'ORD-ORT-'.rand(10000, 99999),
        'customer_id' => $customer->id,
        'payment_method' => 'online',
        'status' => $orderStatus,
        'self_consumption' => false,
        'subtotal_paise' => 118000,
        'gst_paise' => 18000,
        'discount_paise' => 0,
        'shipping_paise' => 5000,
        'total_paise' => 123000,
        'ship_name' => 'ORT', 'ship_phone_e164' => '+919000000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now()->subDays(($deliveredDaysAgo ?? 5) + 5),
        'delivered_at' => $deliveredDaysAgo !== null ? now()->subDays($deliveredDaysAgo) : null,
        'idempotency_key' => 'test-ort-'.uniqid(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $order = Order::findOrFail($orderId);

    if ($coolingOffOpen) {
        OrderCoolingOff::create([
            'order_id' => $orderId,
            'opened_at' => now()->subDays($deliveredDaysAgo ?? 5),
            'ends_at' => now()->addDays(30 - ($deliveredDaysAgo ?? 5)),
            'status' => OrderCoolingOff::STATUS_OPEN,
        ]);
    }

    return ['order' => $order, 'customer' => $customer];
}

it('ORT-01: cooling-off auto-executes the refund immediately', function (): void {
    Event::fake([OrderRefundApproved::class]);
    ['order' => $order, 'customer' => $customer] = openReturnFixture();

    $rq = app(OpenReturn::class)->execute($order, $customer, 'cooling_off', null, null);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_REFUND_APPROVED);
    expect($rq->fresh()->status)->toBe(ReturnRequest::STATUS_APPROVED);
    Event::assertDispatched(OrderRefundApproved::class);
});

it('ORT-02: non-cooling-off reason leaves order at refund_requested', function (): void {
    Event::fake();
    ['order' => $order, 'customer' => $customer] = openReturnFixture();

    $rq = app(OpenReturn::class)->execute($order, $customer, 'damage', 'cracked lid', null);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_REFUND_REQUESTED);
    expect($rq->status)->toBe(ReturnRequest::STATUS_OPENED);
});

it('ORT-03: return request row is created with correct reason and rma_no', function (): void {
    Event::fake();
    ['order' => $order, 'customer' => $customer] = openReturnFixture();

    $rq = app(OpenReturn::class)->execute($order, $customer, 'dissatisfaction', 'bad quality', null);

    expect($rq->reason)->toBe('dissatisfaction');
    expect($rq->rma_no)->toStartWith('RMA-');
    expect($rq->notes)->toBe('bad quality');
    expect($rq->order_item_id)->toBeNull();
});

it('ORT-04: throws when order does not belong to customer', function (): void {
    Event::fake();
    ['order' => $order] = openReturnFixture();
    $otherCustomer = Customer::create([
        'display_name' => 'Other', 'email_hash' => 'other-hash-'.uniqid(), 'email_enc' => null,
    ]);

    expect(fn () => app(OpenReturn::class)->execute($order, $otherCustomer, 'damage', null, null))
        ->toThrow(RuntimeException::class);
});

it('ORT-05: throws when cooling-off clock is expired', function (): void {
    Event::fake();
    ['order' => $order, 'customer' => $customer] = openReturnFixture(coolingOffOpen: false);

    // Add an expired clock.
    OrderCoolingOff::create([
        'order_id' => $order->id,
        'opened_at' => now()->subDays(35),
        'ends_at' => now()->subDays(5),
        'status' => OrderCoolingOff::STATUS_EXPIRED,
    ]);

    expect(fn () => app(OpenReturn::class)->execute($order, $customer, 'cooling_off', null, null))
        ->toThrow(RuntimeException::class);
});

it('ORT-06: throws when order is not in a returnable status', function (): void {
    Event::fake();
    ['order' => $order, 'customer' => $customer] = openReturnFixture(orderStatus: Order::STATUS_SHIPPED);

    expect(fn () => app(OpenReturn::class)->execute($order, $customer, 'damage', null, null))
        ->toThrow(RuntimeException::class);
});

it('ORT-07: throws duplicate when an open return already exists', function (): void {
    Event::fake();
    ['order' => $order, 'customer' => $customer] = openReturnFixture();

    ReturnRequest::create([
        'rma_no' => 'RMA-EXISTING',
        'order_id' => $order->id,
        'reason' => 'damage',
        'opened_by_customer_id' => $customer->id,
        'status' => ReturnRequest::STATUS_OPENED,
    ]);

    expect(fn () => app(OpenReturn::class)->execute($order, $customer, 'damage', null, null))
        ->toThrow(RuntimeException::class);
});

it('ORT-08: damage out of 10-day window throws', function (): void {
    Event::fake();
    ['order' => $order, 'customer' => $customer] = openReturnFixture(deliveredDaysAgo: 12);

    expect(fn () => app(OpenReturn::class)->execute($order, $customer, 'damage', null, null))
        ->toThrow(RuntimeException::class);
});

it('ORT-09: general_buyback has no time window — always eligible', function (): void {
    Event::fake();
    ['order' => $order, 'customer' => $customer] = openReturnFixture(
        orderStatus: Order::STATUS_CONFIRMED,
        coolingOffOpen: false,
        deliveredDaysAgo: 120,
    );

    $rq = app(OpenReturn::class)->execute($order, $customer, 'general_buyback', null, null);
    expect($rq->status)->toBe(ReturnRequest::STATUS_OPENED);
});
