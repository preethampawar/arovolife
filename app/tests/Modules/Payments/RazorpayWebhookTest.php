<?php

declare(strict_types=1);

/**
 * Razorpay → us, the primary authority for "paid".
 *
 * PWH-01: a body whose signature does not verify is a 400 and stores nothing
 * PWH-02: a verified event is stored once with the header event id and queued
 * PWH-03: the same event id delivered again is a 200 no-op — one row, one job
 * PWH-04: the job confirms from the API, never from the event body
 * PWH-05: a payment.failed for an earlier attempt never touches a captured intent
 * PWH-06: an event for an unknown gateway order is recorded and left alone
 * PWH-07: a refused payment (amount mismatch) is marked processed with the reason and not retried
 * PWH-08: the endpoint is 404 when no credentials are configured
 */

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Payments\Jobs\ProcessRazorpayWebhookJob;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Services\PaymentConfirmationService;
use App\Modules\Payments\Services\RazorpayGateway;
use App\Modules\Payments\Services\RazorpayRefundService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

uses(RefreshDatabase::class);

const PWH_SECRET = 'whsec-test';

function pwhOrder(int $total = 118000): Order
{
    $customer = Customer::create(['display_name' => 'PWH Buyer']);

    return Order::create([
        'order_no' => 'ORD-PWH-'.random_int(100000, 999999),
        'customer_id' => $customer->id,
        'attribution_source' => 'direct',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_PLACED,
        'subtotal_paise' => $total, 'gst_paise' => 0, 'discount_paise' => 0, 'shipping_paise' => 0,
        'total_paise' => $total,
        'ship_name' => 'Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now(),
        'idempotency_key' => 'pwh-'.uniqid(),
    ]);
}

function pwhIntent(Order $order, string $gatewayOrderId = 'order_pwh'): PaymentIntent
{
    return PaymentIntent::create([
        'order_id' => $order->id, 'gateway' => 'razorpay', 'gateway_order_id' => $gatewayOrderId, 'mode' => 'test',
        'amount_paise' => $order->total_paise, 'status' => PaymentIntent::STATUS_CREATED, 'idempotency_key' => 'order:'.$order->id,
    ]);
}

/** @param  array<string, mixed>  $payment */
function pwhBody(string $event, array $payment): string
{
    return json_encode([
        'entity' => 'event', 'account_id' => 'acc_1', 'event' => $event, 'contains' => ['payment'],
        'payload' => ['payment' => ['entity' => array_merge([
            'id' => 'pay_pwh', 'entity' => 'payment', 'order_id' => 'order_pwh', 'amount' => 118000, 'currency' => 'INR',
            'status' => 'captured', 'captured' => true, 'amount_refunded' => 0, 'method' => 'upi',
            'email' => 'buyer@example.com', 'contact' => '+919999999999', 'vpa' => 'buyer@upi',
        ], $payment)]],
        'created_at' => time(),
    ], JSON_THROW_ON_ERROR);
}

/** @return TestResponse<Response> */
function pwhPost(TestCase $test, string $body, ?string $signature = null, string $eventId = 'evt_1'): TestResponse
{
    return $test->call('POST', '/webhooks/razorpay', [], [], [], [
        'HTTP_X_RAZORPAY_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, PWH_SECRET),
        'HTTP_X_RAZORPAY_EVENT_ID' => $eventId,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $body);
}

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    config()->set('arovolife.payments.razorpay', [
        'key_id' => 'rzp_test_ABCDEF123456', 'key_secret' => 'secret-xyz', 'webhook_secret' => PWH_SECRET,
        'base_url' => 'https://api.razorpay.com/v1', 'timeout_seconds' => 5,
    ]);
    Http::preventStrayRequests();
});

it('PWH-01: a body whose signature does not verify is a 400 and stores nothing', function () {
    Queue::fake();
    $body = pwhBody('payment.captured', []);

    pwhPost($this, $body, 'deadbeef')->assertStatus(400);
    pwhPost($this, $body, hash_hmac('sha256', $body.' ', PWH_SECRET))->assertStatus(400);

    expect(PaymentEvent::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('PWH-02: a verified event is stored once, scrubbed, with the header event id, and queued', function () {
    Queue::fake();
    $order = pwhOrder();
    $intent = pwhIntent($order);

    pwhPost($this, pwhBody('payment.captured', []), null, 'evt_abc')->assertOk()->assertJson(['status' => 'queued']);

    $event = PaymentEvent::sole();
    expect($event->direction)->toBe('webhook')
        ->and($event->event_type)->toBe('payment.captured')
        ->and($event->gateway_event_id)->toBe('evt_abc')
        ->and($event->gateway_payment_id)->toBe('pay_pwh')
        ->and($event->payment_intent_id)->toBe($intent->id)
        ->and($event->order_id)->toBe($order->id)
        ->and($event->signature_verified)->toBeTrue();
    $json = json_encode($event->payload);
    expect($json)->not->toContain('buyer@example.com');
    expect($json)->not->toContain('buyer@upi');
    Queue::assertPushed(ProcessRazorpayWebhookJob::class, 1);

    // Nothing is paid by storing the event.
    expect($order->fresh()->status)->toBe(Order::STATUS_PLACED);
});

it('PWH-03: the same event id delivered again is a 200 no-op', function () {
    Queue::fake();
    pwhIntent(pwhOrder());
    $body = pwhBody('payment.captured', []);

    pwhPost($this, $body, null, 'evt_dup')->assertOk();
    pwhPost($this, $body, null, 'evt_dup')->assertOk()->assertJson(['status' => 'duplicate']);

    expect(PaymentEvent::count())->toBe(1);
    Queue::assertPushed(ProcessRazorpayWebhookJob::class, 1);
});

it('PWH-04: the job confirms from the API, never from the event body', function () {
    $order = pwhOrder();
    $intent = pwhIntent($order);
    // The event body claims captured for the right amount; the API is the
    // authority. The queue is sync under test, so the job runs inside the POST.
    Http::fake(['api.razorpay.com/v1/payments/pay_pwh' => Http::response([
        'id' => 'pay_pwh', 'order_id' => 'order_pwh', 'amount' => 118000, 'currency' => 'INR',
        'status' => 'captured', 'captured' => true, 'amount_refunded' => 0, 'method' => 'upi',
    ], 200)]);

    pwhPost($this, pwhBody('payment.captured', []), null, 'evt_ok')->assertOk();
    $event = PaymentEvent::where('direction', 'webhook')->sole();

    Http::assertSentCount(1);
    expect($order->fresh()->status)->toBe(Order::STATUS_PAID)
        ->and($intent->fresh()->confirmed_via)->toBe('webhook')
        ->and($event->fresh()->processed_at)->not->toBeNull()
        ->and($event->fresh()->processing_error)->toBeNull();
});

it('PWH-05: a payment.failed for an earlier attempt never touches a captured intent', function () {
    $order = pwhOrder();
    $intent = pwhIntent($order);
    $intent->update(['status' => PaymentIntent::STATUS_CAPTURED, 'captured_at' => now(), 'gateway_payment_id' => 'pay_second']);
    $order->update(['status' => Order::STATUS_PAID, 'paid_at' => now()]);

    pwhPost($this, pwhBody('payment.failed', ['id' => 'pay_first', 'status' => 'failed', 'captured' => false, 'error_code' => 'BAD_REQUEST_ERROR', 'error_description' => 'declined']), null, 'evt_late_fail')->assertOk();
    $event = PaymentEvent::sole();

    (new ProcessRazorpayWebhookJob($event->id))->handle(
        app(PaymentConfirmationService::class),
        app(RazorpayGateway::class),
        app(RazorpayRefundService::class),
    );

    $intent->refresh();
    expect($intent->status)->toBe(PaymentIntent::STATUS_CAPTURED)
        ->and($intent->gateway_payment_id)->toBe('pay_second')
        ->and($intent->error_code)->toBeNull()
        ->and($order->fresh()->status)->toBe(Order::STATUS_PAID);
    Http::assertNothingSent();
});

it('PWH-06: an event for an unknown gateway order is recorded and left alone', function () {
    pwhPost($this, pwhBody('payment.captured', ['order_id' => 'order_nobody']), null, 'evt_unknown')->assertOk();
    $event = PaymentEvent::sole();
    expect($event->payment_intent_id)->toBeNull();

    (new ProcessRazorpayWebhookJob($event->id))->handle(
        app(PaymentConfirmationService::class),
        app(RazorpayGateway::class),
        app(RazorpayRefundService::class),
    );

    expect($event->fresh()->processed_at)->not->toBeNull()
        ->and(Order::where('status', Order::STATUS_PAID)->count())->toBe(0);
    Http::assertNothingSent();
});

it('PWH-07: a refused payment is marked processed with the reason and not retried', function () {
    $order = pwhOrder(118000);
    pwhIntent($order);
    // The API says the capture was for less than the order.
    Http::fake(['api.razorpay.com/v1/payments/pay_pwh' => Http::response([
        'id' => 'pay_pwh', 'order_id' => 'order_pwh', 'amount' => 100000, 'currency' => 'INR',
        'status' => 'captured', 'captured' => true, 'amount_refunded' => 0, 'method' => 'upi',
    ], 200)]);
    Log::shouldReceive('critical')->once();
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error', 'info', 'warning');

    pwhPost($this, pwhBody('payment.captured', []), null, 'evt_short')->assertOk();
    $event = PaymentEvent::where('direction', 'webhook')->sole();

    expect($order->fresh()->status)->toBe(Order::STATUS_PLACED)
        ->and($event->fresh()->processed_at)->not->toBeNull()
        ->and($event->fresh()->processing_error)->toContain('refused');
});

it('PWH-08: the endpoint is 404 when no credentials are configured', function () {
    config()->set('arovolife.payments.razorpay.webhook_secret', '');

    pwhPost($this, pwhBody('payment.captured', []))->assertNotFound();
});
