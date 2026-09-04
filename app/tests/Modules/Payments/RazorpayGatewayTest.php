<?php

declare(strict_types=1);

/**
 * PAY-G10: createIntent creates the Razorpay order and stores its id on the intent
 * PAY-G11: createIntent is idempotent — a second call makes no second gateway order
 * PAY-G12: an existing gateway order for the receipt is reused, never re-created
 * PAY-G13: Razorpay's duplicate-receipt rejection is treated as "already exists"
 * PAY-G14: a gateway order whose amount differs from the order is refused
 * PAY-G15: an order below the 100-paise floor never reaches the gateway
 * PAY-G16: syncStatus prefers a captured attempt over a later failure and updates the intent
 * PAY-G17: syncStatus with no attempts returns null and stamps last_synced_at
 * PAY-G18: the notes sent to Razorpay carry only our order reference
 */

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Payments\Exceptions\RazorpayApiException;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Services\RazorpayGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function razorpayOrder(int $totalPaise = 118000): Order
{
    $customer = Customer::create(['display_name' => 'Rzp Buyer']);

    return Order::create([
        'order_no' => 'ORD-RZP-'.random_int(100000, 999999),
        'customer_id' => $customer->id,
        'attribution_source' => 'direct',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_PLACED,
        'subtotal_paise' => $totalPaise, 'gst_paise' => 0, 'discount_paise' => 0, 'shipping_paise' => 0,
        'total_paise' => $totalPaise,
        'ship_name' => 'Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now(),
        'idempotency_key' => 'rzp-'.uniqid(),
    ]);
}

function emptyOrders(): array
{
    return ['entity' => 'collection', 'count' => 0, 'items' => []];
}

beforeEach(function () {
    config()->set('arovolife.payments.razorpay', [
        'key_id' => 'rzp_test_ABCDEF123456', 'key_secret' => 's', 'webhook_secret' => 'w',
        'base_url' => 'https://api.razorpay.com/v1', 'timeout_seconds' => 5,
    ]);
    Http::preventStrayRequests();
});

it('PAY-G10: creates the Razorpay order and stores its id on the intent', function () {
    $order = razorpayOrder();
    Http::fake([
        'api.razorpay.com/v1/orders?*' => Http::response(emptyOrders(), 200),
        'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_new', 'amount' => 118000, 'status' => 'created', 'receipt' => $order->order_no], 200),
    ]);

    $intent = app(RazorpayGateway::class)->createIntent($order, 'order:'.$order->id);

    expect($intent->gateway)->toBe('razorpay')
        ->and($intent->gateway_order_id)->toBe('order_new')
        ->and($intent->mode)->toBe('test')
        ->and($intent->amount_paise)->toBe(118000)
        ->and($intent->status)->toBe(PaymentIntent::STATUS_CREATED)
        ->and($intent->expires_at)->not->toBeNull()
        ->and($intent->raw_payload['id'])->toBe('order_new');
});

it('PAY-G11: a second createIntent for the same key makes no second gateway order', function () {
    $order = razorpayOrder();
    Http::fake([
        'api.razorpay.com/v1/orders?*' => Http::response(emptyOrders(), 200),
        'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_once', 'amount' => 118000, 'status' => 'created'], 200),
    ]);
    $gateway = app(RazorpayGateway::class);

    $first = $gateway->createIntent($order, 'order:'.$order->id);
    $second = $gateway->createIntent($order, 'order:'.$order->id);

    expect($second->id)->toBe($first->id)->and(PaymentIntent::count())->toBe(1);
    Http::assertSentCount(2); // one receipt lookup, one create — nothing on the second call
});

it('PAY-G12: an existing gateway order for the receipt is reused', function () {
    $order = razorpayOrder();
    Http::fake([
        'api.razorpay.com/v1/orders?*' => Http::response(['entity' => 'collection', 'count' => 1, 'items' => [
            ['id' => 'order_existing', 'amount' => 118000, 'status' => 'created', 'receipt' => $order->order_no],
        ]], 200),
    ]);

    $intent = app(RazorpayGateway::class)->createIntent($order, 'order:'.$order->id);

    expect($intent->gateway_order_id)->toBe('order_existing');
    Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
});

it('PAY-G13: the duplicate-receipt rejection is treated as already-exists', function () {
    $order = razorpayOrder();
    Http::fake([
        'api.razorpay.com/v1/orders?*' => Http::sequence()
            ->push(emptyOrders(), 200)
            ->push(['entity' => 'collection', 'count' => 1, 'items' => [['id' => 'order_dup', 'amount' => 118000, 'status' => 'created']]], 200),
        'api.razorpay.com/v1/orders' => Http::response(['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'Order with receipt already exists']], 400),
    ]);

    $intent = app(RazorpayGateway::class)->createIntent($order, 'order:'.$order->id);

    expect($intent->gateway_order_id)->toBe('order_dup');
});

it('PAY-G14: a gateway order whose amount differs from ours is refused', function () {
    $order = razorpayOrder(118000);
    Http::fake([
        'api.razorpay.com/v1/orders?*' => Http::response(['entity' => 'collection', 'count' => 1, 'items' => [
            ['id' => 'order_stale', 'amount' => 99000, 'status' => 'created'],
        ]], 200),
    ]);

    expect(fn () => app(RazorpayGateway::class)->createIntent($order, 'order:'.$order->id))
        ->toThrow(RazorpayApiException::class, 'carries 99000 paise');
});

it('PAY-G15: an order below the 100-paise floor never reaches the gateway', function () {
    Http::fake();

    expect(fn () => app(RazorpayGateway::class)->createIntent(razorpayOrder(99), 'k'))
        ->toThrow(InvalidArgumentException::class, 'below the gateway minimum');
    expect(fn () => app(RazorpayGateway::class)->createIntent(razorpayOrder(0), 'k2'))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('PAY-G16: syncStatus prefers a captured attempt over a later failure and updates the intent', function () {
    $order = razorpayOrder();
    $intent = PaymentIntent::create([
        'order_id' => $order->id, 'gateway' => 'razorpay', 'gateway_order_id' => 'order_s', 'mode' => 'test',
        'amount_paise' => 118000, 'status' => PaymentIntent::STATUS_CREATED, 'idempotency_key' => 'order:'.$order->id,
    ]);
    Http::fake(['api.razorpay.com/v1/orders/order_s/payments' => Http::response(['entity' => 'collection', 'count' => 2, 'items' => [
        ['id' => 'pay_ok', 'order_id' => 'order_s', 'amount' => 118000, 'currency' => 'INR', 'status' => 'captured', 'captured' => true, 'method' => 'upi', 'amount_refunded' => 0, 'email' => 'x@y.z'],
        ['id' => 'pay_bad', 'order_id' => 'order_s', 'amount' => 118000, 'currency' => 'INR', 'status' => 'failed', 'captured' => false, 'method' => 'card', 'error_code' => 'BAD_REQUEST_ERROR', 'error_description' => 'declined'],
    ]], 200)]);

    $payment = app(RazorpayGateway::class)->syncStatus($intent);

    expect($payment)->not->toBeNull()
        ->and($payment->id)->toBe('pay_ok')
        ->and($payment->isCaptured())->toBeTrue();

    $intent->refresh();
    expect($intent->gateway_payment_id)->toBe('pay_ok')
        ->and($intent->method)->toBe('upi')
        ->and($intent->attempt_count)->toBe(2)
        ->and($intent->last_synced_at)->not->toBeNull()
        // syncStatus never marks captured — that is PaymentConfirmationService's job
        ->and($intent->status)->toBe(PaymentIntent::STATUS_CREATED)
        ->and($intent->raw_payload)->not->toHaveKey('email');
});

it('PAY-G17: syncStatus with no attempts returns null and stamps last_synced_at', function () {
    $order = razorpayOrder();
    $intent = PaymentIntent::create([
        'order_id' => $order->id, 'gateway' => 'razorpay', 'gateway_order_id' => 'order_e', 'mode' => 'test',
        'amount_paise' => 118000, 'status' => PaymentIntent::STATUS_CREATED, 'idempotency_key' => 'order:'.$order->id,
    ]);
    Http::fake(['api.razorpay.com/v1/orders/order_e/payments' => Http::response(['entity' => 'collection', 'count' => 0, 'items' => []], 200)]);

    expect(app(RazorpayGateway::class)->syncStatus($intent))->toBeNull()
        ->and($intent->fresh()->last_synced_at)->not->toBeNull();
});

it('PAY-G18: the notes sent to Razorpay carry only our order reference', function () {
    $order = razorpayOrder();
    Http::fake([
        'api.razorpay.com/v1/orders?*' => Http::response(emptyOrders(), 200),
        'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_n', 'amount' => 118000, 'status' => 'created'], 200),
    ]);

    app(RazorpayGateway::class)->createIntent($order, 'order:'.$order->id);

    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
        && $r->data()['notes'] === ['arovolife_order_id' => (string) $order->id, 'arovolife_order_no' => $order->order_no]);
});
