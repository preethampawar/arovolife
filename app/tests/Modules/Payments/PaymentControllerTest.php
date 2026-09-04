<?php

declare(strict_types=1);

/**
 * The buyer-facing Razorpay pages.
 *
 * PCT-01: the pay routes are 404 unless Razorpay is the active gateway
 * PCT-02: the pay page is 404 for anyone but the session that placed the order or its owner
 * PCT-03: the pay page carries the key, the gateway order and a page-scoped CSP; other pages keep the strict one
 * PCT-04: a callback with a bad signature leaves the order placed and shows a neutral message
 * PCT-05: a callback with a good signature confirms from the API and lands on the confirmation page
 * PCT-06: the failure endpoint records a scrubbed attempt without touching the intent's status
 * PCT-07: the status poll syncs with the gateway and reports paid
 * PCT-08: a misconfigured gateway closes checkout only — the shop, the cart and My Orders stay up
 */

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Models\PaymentIntent;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function pctSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'version' => 1, 'updated_at' => now()]);
}

function pctOrder(): Order
{
    $customer = Customer::create(['display_name' => 'PCT Buyer']);

    return Order::create([
        'order_no' => 'ORD-PCT-'.random_int(100000, 999999),
        'customer_id' => $customer->id,
        'attribution_source' => 'direct',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_PLACED,
        'subtotal_paise' => 118000, 'gst_paise' => 0, 'discount_paise' => 0, 'shipping_paise' => 0,
        'total_paise' => 118000,
        'ship_name' => 'PCT Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now(),
        'idempotency_key' => 'pct-'.uniqid(),
    ]);
}

function pctIntent(Order $order): PaymentIntent
{
    return PaymentIntent::create([
        'order_id' => $order->id, 'gateway' => 'razorpay', 'gateway_order_id' => 'order_pct', 'mode' => 'test',
        'amount_paise' => $order->total_paise, 'status' => PaymentIntent::STATUS_CREATED,
        'idempotency_key' => 'order:'.$order->id, 'expires_at' => now()->addMinutes(30),
    ]);
}

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    Cache::flush();
    pctSetting('commerce.storefront.enabled', 'true');
    pctSetting('commerce.checkout.enabled', 'true');
    pctSetting('commerce.guest_checkout.enabled', 'true');
    pctSetting('payments.gateway.razorpay.enabled', 'true');
    config()->set('arovolife.payments.razorpay', [
        'key_id' => 'rzp_test_ABCDEF123456', 'key_secret' => 'secret-xyz', 'webhook_secret' => 'whsec',
        'base_url' => 'https://api.razorpay.com/v1', 'timeout_seconds' => 5,
    ]);
    Http::preventStrayRequests();
});

it('PCT-01: the pay routes are 404 unless Razorpay is the active gateway', function () {
    pctSetting('payments.gateway.razorpay.enabled', 'false');
    $order = pctOrder();
    pctIntent($order);

    $this->withSession(['recent_order_nos' => [$order->order_no]])
        ->get(route('shop.pay', $order->order_no))->assertNotFound();
});

it('PCT-02: the pay page is 404 for anyone but the placing session or the owner', function () {
    $order = pctOrder();
    pctIntent($order);

    $this->get(route('shop.pay', $order->order_no))->assertNotFound();
    $this->withSession(['recent_order_nos' => [$order->order_no]])
        ->get(route('shop.pay', $order->order_no))->assertOk();
});

it('PCT-03: the pay page carries the key and gateway order under a page-scoped CSP', function () {
    $order = pctOrder();
    pctIntent($order);

    $response = $this->withSession(['recent_order_nos' => [$order->order_no]])
        ->get(route('shop.pay', $order->order_no));

    $response->assertOk()
        ->assertSee('rzp_test_ABCDEF123456')
        ->assertSee('order_pct')
        ->assertSee('checkout.razorpay.com/v1/checkout.js', false)
        ->assertDontSee('secret-xyz');

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain('script-src \'self\' \'unsafe-inline\' https://checkout.razorpay.com')
        ->toContain('frame-src https://api.razorpay.com')
        ->toContain("form-action 'self'");

    // Every other page keeps the strict policy.
    $shop = $this->get(route('shop.index'));
    expect($shop->headers->get('Content-Security-Policy'))->not->toContain('razorpay');
});

it('PCT-04: a callback with a bad signature leaves the order placed and shows a neutral message', function () {
    $order = pctOrder();
    pctIntent($order);

    $this->withSession(['recent_order_nos' => [$order->order_no]])
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('shop.pay.callback', $order->order_no), [
            'razorpay_order_id' => 'order_pct', 'razorpay_payment_id' => 'pay_x', 'razorpay_signature' => 'forged',
        ])
        ->assertRedirect(route('shop.pay', $order->order_no))
        ->assertSessionHas('payment_error');

    expect($order->fresh()->status)->toBe(Order::STATUS_PLACED)
        ->and(PaymentEvent::where('event_type', 'checkout.callback')->sole()->signature_verified)->toBeFalse();
    Http::assertNothingSent();
});

it('PCT-05: a callback with a good signature confirms from the API and lands on the confirmation page', function () {
    $order = pctOrder();
    pctIntent($order);
    Http::fake(['api.razorpay.com/v1/payments/pay_ok' => Http::response([
        'id' => 'pay_ok', 'order_id' => 'order_pct', 'amount' => 118000, 'currency' => 'INR',
        'status' => 'captured', 'captured' => true, 'amount_refunded' => 0, 'method' => 'upi', 'email' => 'x@y.z',
    ], 200)]);
    $sig = hash_hmac('sha256', 'order_pct|pay_ok', 'secret-xyz');

    $this->withSession(['recent_order_nos' => [$order->order_no]])
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('shop.pay.callback', $order->order_no), [
            'razorpay_order_id' => 'order_pct', 'razorpay_payment_id' => 'pay_ok', 'razorpay_signature' => $sig,
        ])
        ->assertRedirect(route('shop.confirmation', $order->order_no));

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);

    // The confirmation page opens for the same session.
    $this->withSession(['recent_order_nos' => [$order->order_no]])
        ->get(route('shop.confirmation', $order->order_no))->assertOk();
});

it('PCT-06: the failure endpoint records a scrubbed attempt without touching the intent\'s status', function () {
    $order = pctOrder();
    $intent = pctIntent($order);

    $this->withSession(['recent_order_nos' => [$order->order_no]])
        ->withoutMiddleware(PreventRequestForgery::class)
        ->postJson(route('shop.pay.failure', $order->order_no), [
            'kind' => 'failed',
            'error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'Payment declined', 'reason' => 'card_declined',
                'metadata' => ['payment_id' => 'pay_fail', 'order_id' => 'order_pct'], 'email' => 'x@y.z'],
        ])->assertOk();

    $event = PaymentEvent::where('event_type', 'checkout.failed')->sole();
    expect($event->gateway_payment_id)->toBe('pay_fail')
        ->and($event->payload)->not->toHaveKey('email')
        ->and($event->error)->toContain('BAD_REQUEST_ERROR');

    $intent->refresh();
    expect($intent->status)->toBe(PaymentIntent::STATUS_CREATED)
        ->and($intent->error_code)->toBe('BAD_REQUEST_ERROR')
        ->and($intent->attempt_count)->toBe(1);
});

it('PCT-07: the status poll syncs with the gateway and reports paid', function () {
    $order = pctOrder();
    pctIntent($order);
    Http::fake(['api.razorpay.com/v1/orders/order_pct/payments' => Http::response(['entity' => 'collection', 'count' => 1, 'items' => [
        ['id' => 'pay_poll', 'order_id' => 'order_pct', 'amount' => 118000, 'currency' => 'INR', 'status' => 'captured', 'captured' => true, 'amount_refunded' => 0, 'method' => 'upi'],
    ]], 200)]);

    $this->withSession(['recent_order_nos' => [$order->order_no]])
        ->getJson(route('shop.pay.status', $order->order_no))
        ->assertOk()
        ->assertJson(['status' => 'paid', 'redirect' => route('shop.confirmation', $order->order_no)]);

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);
});

it('PCT-08: a misconfigured gateway closes checkout only — the shop, the cart and My Orders stay up', function () {
    config()->set('arovolife.payments.razorpay.key_secret', ''); // flag on, credentials incomplete

    $this->get(route('shop.checkout'))->assertStatus(503)->assertSee('Checkout is temporarily unavailable');
    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('shop.checkout.place'), ['payment_method' => 'online'])->assertRedirect(route('shop.checkout'));

    $this->get(route('shop.index'))->assertOk();
    $this->get(route('shop.cart'))->assertOk();

    $user = User::create([
        'full_name' => 'PCT Member', 'email' => 'pct-'.uniqid().'@test.com', 'phone_e164' => '+917000000009',
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    $this->actingAs($user)->get(route('orders.index'))->assertOk();
});
