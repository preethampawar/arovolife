<?php

declare(strict_types=1);

/**
 * PAY-C01: createOrder posts the Razorpay Orders shape with basic auth and records the event
 * PAY-C02: a 4xx becomes RazorpayApiException carrying the gateway code, and is recorded
 * PAY-C03: a connection failure throws and is recorded, without a response
 * PAY-C04: idempotent GETs retry a 5xx; a 4xx is never retried
 * PAY-C05: createRefund carries the idempotency header and receipt
 * PAY-C06: checkout signature verifies only the exact order|payment pair
 * PAY-C07: webhook signature verifies only the exact raw body
 * PAY-C08: mode from the key prefix; malformed key = not configured
 * PAY-C09: configured() needs all three secrets, including the webhook secret
 * PAY-C10: production takes only live keys, everywhere else only test keys
 * PAY-C11: nothing personal reaches payment_events or the payments log
 */

use App\Modules\Payments\Exceptions\RazorpayApiException;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Services\RazorpayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('arovolife.payments.razorpay', [
        'key_id' => 'rzp_test_ABCDEF123456',
        'key_secret' => 'secret-xyz',
        'webhook_secret' => 'whsec-xyz',
        'base_url' => 'https://api.razorpay.com/v1',
        'timeout_seconds' => 5,
    ]);
    Http::preventStrayRequests();
});

it('PAY-C01: createOrder posts the Orders API shape with basic auth and records the event', function () {
    Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_1', 'amount' => 118000, 'status' => 'created'], 200)]);

    $json = app(RazorpayClient::class)->createOrder(118000, 'ORD-1', ['arovolife_order_id' => '7'], 'optimum', orderId: null, intentId: null);

    expect($json['id'])->toBe('order_1');
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://api.razorpay.com/v1/orders'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('rzp_test_ABCDEF123456:secret-xyz'))
            && $body['amount'] === 118000
            && $body['currency'] === 'INR'
            && $body['receipt'] === 'ORD-1'
            && $body['payment']['capture'] === 'automatic'
            && $body['payment']['capture_options']['refund_speed'] === 'optimum';
    });

    $event = PaymentEvent::sole();
    expect($event->direction)->toBe('outbound')
        ->and($event->event_type)->toBe('orders.create')
        ->and($event->http_status)->toBe(200)
        ->and($event->error)->toBeNull()
        ->and($event->payload['response']['id'])->toBe('order_1')
        ->and($event->payload['request']['receipt'])->toBe('ORD-1');
});

it('PAY-C02: a 4xx becomes RazorpayApiException carrying the gateway code and is recorded', function () {
    Http::fake(['api.razorpay.com/v1/orders' => Http::response([
        'error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'Order amount less than minimum amount allowed'],
    ], 400)]);

    try {
        app(RazorpayClient::class)->createOrder(50, 'ORD-2', [], 'optimum');
        $this->fail('expected RazorpayApiException');
    } catch (RazorpayApiException $e) {
        expect($e->httpStatus)->toBe(400)
            ->and($e->gatewayCode)->toBe('BAD_REQUEST_ERROR')
            ->and($e->gatewayDescription)->toContain('minimum amount');
    }

    $event = PaymentEvent::sole();
    expect($event->http_status)->toBe(400)->and($event->error)->toContain('BAD_REQUEST_ERROR');
});

it('PAY-C03: a connection failure throws and is recorded without a response', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28'));

    expect(fn () => app(RazorpayClient::class)->createOrder(118000, 'ORD-3', [], 'optimum'))
        ->toThrow(RazorpayApiException::class, 'could not be reached');

    $event = PaymentEvent::sole();
    expect($event->http_status)->toBeNull()->and($event->error)->toStartWith('connection:');
});

it('PAY-C04: an idempotent GET retries a 5xx and never retries a 4xx', function () {
    Http::fake([
        'api.razorpay.com/v1/payments/pay_retry' => Http::sequence()
            ->push(['error' => ['code' => 'SERVER_ERROR']], 502)
            ->push(['id' => 'pay_retry', 'status' => 'captured'], 200),
        'api.razorpay.com/v1/payments/pay_gone' => Http::response(['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'id does not exist']], 400),
    ]);

    $client = app(RazorpayClient::class);

    expect($client->fetchPayment('pay_retry')['status'])->toBe('captured');
    Http::assertSentCount(1 + 1); // the 502 then the 200

    expect(fn () => $client->fetchPayment('pay_gone'))->toThrow(RazorpayApiException::class);
    Http::assertSentCount(3); // exactly one more — the 400 was not retried
});

it('PAY-C05: createRefund carries the idempotency header and receipt', function () {
    Http::fake(['api.razorpay.com/v1/payments/pay_1/refund' => Http::response(['id' => 'rfnd_1', 'status' => 'processed', 'amount' => 500], 200)]);

    app(RazorpayClient::class)->createRefund('pay_1', 500, 'normal', 'refund:42', ['arovolife_order_id' => '42']);

    Http::assertSent(fn (Request $r): bool => $r->hasHeader('X-Refund-Idempotency', 'refund:42')
        && $r->data()['receipt'] === 'refund:42'
        && $r->data()['speed'] === 'normal'
        && $r->data()['amount'] === 500);
});

it('PAY-C06: the checkout signature verifies only the exact order|payment pair', function () {
    $client = app(RazorpayClient::class);
    $sig = hash_hmac('sha256', 'order_1|pay_1', 'secret-xyz');

    expect($client->verifyCheckoutSignature('order_1', 'pay_1', $sig))->toBeTrue()
        ->and($client->verifyCheckoutSignature('order_2', 'pay_1', $sig))->toBeFalse()
        ->and($client->verifyCheckoutSignature('order_1', 'pay_2', $sig))->toBeFalse()
        ->and($client->verifyCheckoutSignature('order_1', 'pay_1', strtoupper($sig)))->toBeFalse()
        ->and($client->verifyCheckoutSignature('order_1', 'pay_1', ''))->toBeFalse();
});

it('PAY-C07: the webhook signature verifies only the exact raw body', function () {
    $client = app(RazorpayClient::class);
    $body = '{"event":"payment.captured","payload":{}}';
    $sig = hash_hmac('sha256', $body, 'whsec-xyz');

    expect($client->verifyWebhookSignature($body, $sig))->toBeTrue()
        ->and($client->verifyWebhookSignature($body.' ', $sig))->toBeFalse()
        ->and($client->verifyWebhookSignature($body, hash_hmac('sha256', $body, 'secret-xyz')))->toBeFalse();
});

it('PAY-C08: mode comes from the key prefix and a malformed key is not configured', function () {
    $client = app(RazorpayClient::class);
    expect($client->mode())->toBe('test')->and($client->configured())->toBeTrue();

    config()->set('arovolife.payments.razorpay.key_id', 'rzp_live_ABCDEF123456');
    expect($client->mode())->toBe('live');

    config()->set('arovolife.payments.razorpay.key_id', 'not-a-razorpay-key');
    expect($client->mode())->toBeNull()->and($client->configured())->toBeFalse();
});

it('PAY-C09: configured() needs the webhook secret too', function () {
    config()->set('arovolife.payments.razorpay.webhook_secret', '');

    // Without it the primary confirmation authority is silently dead.
    expect(app(RazorpayClient::class)->configured())->toBeFalse();
});

it('PAY-C10: production takes only live keys and every other environment only test keys', function () {
    $client = app(RazorpayClient::class);

    expect($client->modeMatchesEnvironment())->toBeTrue(); // test key in testing

    app()->detectEnvironment(fn () => 'production');
    expect($client->modeMatchesEnvironment())->toBeFalse(); // test key in production

    config()->set('arovolife.payments.razorpay.key_id', 'rzp_live_ABCDEF123456');
    expect($client->modeMatchesEnvironment())->toBeTrue(); // live key in production

    app()->detectEnvironment(fn () => 'staging');
    expect($client->modeMatchesEnvironment())->toBeFalse(); // live key outside production
});

it('PAY-C11: nothing personal reaches payment_events', function () {
    Http::fake(['api.razorpay.com/v1/payments/pay_p' => Http::response([
        'id' => 'pay_p', 'status' => 'captured', 'email' => 'buyer@example.com', 'contact' => '+919999999999',
        'vpa' => 'buyer@upi', 'card' => ['name' => 'Buyer Name', 'last4' => '1111', 'network' => 'Visa'],
    ], 200)]);

    app(RazorpayClient::class)->fetchPayment('pay_p');

    $json = json_encode(PaymentEvent::sole()->payload, JSON_THROW_ON_ERROR);
    expect($json)->not->toContain('buyer@example.com')->not->toContain('+919999999999')
        ->not->toContain('buyer@upi')->not->toContain('Buyer Name')
        ->toContain('1111')->toContain('Visa');
});
