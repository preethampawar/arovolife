<?php

declare(strict_types=1);

/**
 * T-6.1 finding C-1 — the stub gateway must not be reachable in production.
 *
 * PAY-G01: createIntent refuses outside local/testing
 * PAY-G02: capture refuses outside local/testing, and leaves the order unpaid
 * PAY-G03: it still works in testing, so the suite and local dev are unaffected
 */

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Services\StubGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function stubOrder(): Order
{
    $customer = Customer::create(['display_name' => 'Stub Buyer']);

    return Order::create([
        'order_no' => 'ORD-STUB-'.random_int(100000, 999999),
        'customer_id' => $customer->id,
        'attribution_source' => 'direct',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_PLACED,
        'subtotal_paise' => 118000,
        'gst_paise' => 18000,
        'discount_paise' => 0,
        'shipping_paise' => 0,
        'total_paise' => 118000,
        'ship_name' => 'Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now(),
        'idempotency_key' => 'stub-'.uniqid(),
    ]);
}

it('PAY-G01: refuses to create an intent in production', function () {
    app()->detectEnvironment(fn () => 'production');

    // Without this guard an order reaches PAID with no money collected, which
    // accrues BV and fires every compensation engine behind it.
    expect(fn () => app(StubGateway::class)->createIntent(stubOrder(), 'k'))
        ->toThrow(RuntimeException::class, 'must never run outside');
});

it('PAY-G02: refuses to capture in production and leaves the order unpaid', function () {
    $order = stubOrder();
    $intent = app(StubGateway::class)->createIntent($order, 'k');

    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(StubGateway::class)->capture($intent))
        ->toThrow(RuntimeException::class, 'must never run outside')
        ->and($order->fresh()->paid_at)->toBeNull();
});

it('PAY-G03: still captures in testing so local development is unaffected', function () {
    $order = stubOrder();
    $gateway = app(StubGateway::class);

    $intent = $gateway->capture($gateway->createIntent($order, 'k'));

    expect($intent->status)->toBe(PaymentIntent::STATUS_CAPTURED)
        ->and($order->fresh()->paid_at)->not->toBeNull();
});
