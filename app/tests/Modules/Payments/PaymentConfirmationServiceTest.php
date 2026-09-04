<?php

declare(strict_types=1);

/**
 * The single choke point into markPaid() — hard rule 2 in code.
 *
 * PCS-01: a captured payment for the intent's gateway order confirms the order, with evidence in the audit row
 * PCS-02: confirming twice marks paid once
 * PCS-03: a payment for a different gateway order is refused
 * PCS-04: a payment for a different amount is refused
 * PCS-05: a non-INR payment is refused
 * PCS-06: a partly refunded payment is refused
 * PCS-07: an authorised-only payment is pending, never paid
 * PCS-08: a failed payment leaves the order placed and records the reason
 * PCS-09: a capture on an already-cancelled order posts the cash as owed back and queues a full refund
 * PCS-10: the callback refuses a bad signature and an order id that is not the intent's
 * PCS-11: a good callback fetches the payment from the API before confirming
 * PCS-12: a zero-cash order confirms only when the entitlements cover the gross, re-derived from the order
 * PCS-13: syncAndConfirm confirms from the gateway's own list of attempts
 * PCS-14: the invoice is issued for a confirmed order, after commit
 */

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Ledger\Models\LedgerTx;
use App\Modules\Payments\Data\ConfirmationResult;
use App\Modules\Payments\Data\GatewayPayment;
use App\Modules\Payments\Exceptions\PaymentMismatchException;
use App\Modules\Payments\Exceptions\SignatureVerificationException;
use App\Modules\Payments\Jobs\SendRazorpayRefundJob;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\RefundIntent;
use App\Modules\Payments\Services\PaymentConfirmationService;
use App\Modules\Tax\Models\Invoice;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/** @param  array<string, mixed>  $overrides */
function pcsOrder(int $totalPaise = 118000, array $overrides = []): Order
{
    $customer = Customer::create(['display_name' => 'PCS Buyer']);

    return Order::create(array_merge([
        'order_no' => 'ORD-PCS-'.random_int(100000, 999999),
        'customer_id' => $customer->id,
        'attribution_source' => 'direct',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_PLACED,
        'subtotal_paise' => $totalPaise, 'gst_paise' => 0, 'discount_paise' => 0, 'shipping_paise' => 0,
        'total_paise' => $totalPaise,
        'ship_name' => 'Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now(),
        'idempotency_key' => 'pcs-'.uniqid(),
    ], $overrides));
}

function pcsIntent(Order $order, string $gatewayOrderId = 'order_pcs'): PaymentIntent
{
    return PaymentIntent::create([
        'order_id' => $order->id, 'gateway' => 'razorpay', 'gateway_order_id' => $gatewayOrderId, 'mode' => 'test',
        'amount_paise' => $order->total_paise, 'status' => PaymentIntent::STATUS_CREATED, 'idempotency_key' => 'order:'.$order->id,
    ]);
}

/** @param  array<string, mixed>  $overrides */
function pcsPayment(array $overrides = []): GatewayPayment
{
    $entity = array_merge([
        'id' => 'pay_pcs', 'order_id' => 'order_pcs', 'amount' => 118000, 'currency' => 'INR',
        'status' => 'captured', 'captured' => true, 'amount_refunded' => 0, 'method' => 'upi',
    ], $overrides);

    return GatewayPayment::fromEntity($entity, $entity);
}

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    config()->set('arovolife.payments.razorpay', [
        'key_id' => 'rzp_test_ABCDEF123456', 'key_secret' => 'secret-xyz', 'webhook_secret' => 'whsec',
        'base_url' => 'https://api.razorpay.com/v1', 'timeout_seconds' => 5,
    ]);
    Http::preventStrayRequests();
});

it('PCS-01: a captured payment for the intent\'s gateway order confirms the order with evidence', function () {
    $order = pcsOrder();
    $intent = pcsIntent($order);

    $result = app(PaymentConfirmationService::class)->confirmPayment($intent, pcsPayment(), PaymentIntent::CONFIRMED_VIA_WEBHOOK);

    expect($result->status)->toBe(ConfirmationResult::CONFIRMED);
    $order->refresh();
    $intent->refresh();
    expect($order->status)->toBe(Order::STATUS_PAID)
        ->and($order->paid_at)->not->toBeNull()
        ->and($intent->status)->toBe(PaymentIntent::STATUS_CAPTURED)
        ->and($intent->gateway_payment_id)->toBe('pay_pcs')
        ->and($intent->confirmed_via)->toBe('webhook')
        ->and($intent->method)->toBe('upi');

    $audit = AuditLog::where('action', 'order.paid')->where('subject_id', $order->id)->sole();
    expect($audit->details['payment_intent_id'])->toBe($intent->id)
        ->and($audit->details['gateway_payment_id'])->toBe('pay_pcs')
        ->and($audit->details['confirmed_via'])->toBe('webhook')
        ->and($audit->details['confirming_event_id'])->toBe(PaymentEvent::where('event_type', 'payment.confirmed')->sole()->id);
});

it('PCS-02: confirming twice marks paid once', function () {
    $order = pcsOrder();
    $intent = pcsIntent($order);
    $service = app(PaymentConfirmationService::class);

    $service->confirmPayment($intent, pcsPayment(), PaymentIntent::CONFIRMED_VIA_CALLBACK);
    $second = $service->confirmPayment($intent->fresh(), pcsPayment(), PaymentIntent::CONFIRMED_VIA_WEBHOOK);

    expect($second->status)->toBe(ConfirmationResult::ALREADY_CONFIRMED)
        ->and(AuditLog::where('action', 'order.paid')->where('subject_id', $order->id)->count())->toBe(1)
        ->and($intent->fresh()->confirmed_via)->toBe('callback');
});

it('PCS-03: a payment for a different gateway order is refused', function () {
    $order = pcsOrder();
    $intent = pcsIntent($order);
    Log::shouldReceive('critical')->once();
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error', 'info', 'warning');

    expect(fn () => app(PaymentConfirmationService::class)->confirmPayment($intent, pcsPayment(['order_id' => 'order_other']), 'webhook'))
        ->toThrow(PaymentMismatchException::class, 'order_other');

    expect($order->fresh()->status)->toBe(Order::STATUS_PLACED)
        ->and(PaymentEvent::where('event_type', 'confirmation.refused')->count())->toBe(1);
});

it('PCS-04: a payment for a different amount is refused', function () {
    $order = pcsOrder(118000);
    $intent = pcsIntent($order);
    Log::shouldReceive('critical')->once();
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error', 'info', 'warning');

    expect(fn () => app(PaymentConfirmationService::class)->confirmPayment($intent, pcsPayment(['amount' => 117900]), 'webhook'))
        ->toThrow(PaymentMismatchException::class, '117900 paise');
    expect($order->fresh()->status)->toBe(Order::STATUS_PLACED);
});

it('PCS-05: a non-INR payment is refused', function () {
    $order = pcsOrder();
    $intent = pcsIntent($order);
    Log::shouldReceive('critical')->once();
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error', 'info', 'warning');

    expect(fn () => app(PaymentConfirmationService::class)->confirmPayment($intent, pcsPayment(['currency' => 'USD']), 'webhook'))
        ->toThrow(PaymentMismatchException::class, 'USD');
});

it('PCS-06: a partly refunded payment is refused', function () {
    $order = pcsOrder();
    $intent = pcsIntent($order);
    Log::shouldReceive('critical')->once();
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error', 'info', 'warning');

    expect(fn () => app(PaymentConfirmationService::class)->confirmPayment($intent, pcsPayment(['amount_refunded' => 100]), 'webhook'))
        ->toThrow(PaymentMismatchException::class, 'refunded');
});

it('PCS-07: an authorised-only payment is pending, never paid', function () {
    $order = pcsOrder();
    $intent = pcsIntent($order);

    $result = app(PaymentConfirmationService::class)->confirmPayment($intent, pcsPayment(['status' => 'authorized', 'captured' => false]), 'webhook');

    expect($result->status)->toBe(ConfirmationResult::PENDING)
        ->and($order->fresh()->status)->toBe(Order::STATUS_PLACED)
        ->and($intent->fresh()->status)->toBe(PaymentIntent::STATUS_CREATED);
});

it('PCS-08: a failed payment leaves the order placed and records the reason', function () {
    $order = pcsOrder();
    $intent = pcsIntent($order);

    $result = app(PaymentConfirmationService::class)->confirmPayment($intent,
        pcsPayment(['status' => 'failed', 'captured' => false, 'error_code' => 'BAD_REQUEST_ERROR', 'error_description' => 'declined by bank']), 'webhook');

    expect($result->status)->toBe(ConfirmationResult::FAILED)
        ->and($order->fresh()->status)->toBe(Order::STATUS_PLACED)
        ->and($intent->fresh()->error_code)->toBe('BAD_REQUEST_ERROR')
        ->and($intent->fresh()->status)->toBe(PaymentIntent::STATUS_CREATED);
});

it('PCS-09: a capture on an already-cancelled order posts the cash as owed back and queues a full refund', function () {
    Queue::fake();
    $order = pcsOrder();
    $intent = pcsIntent($order);
    app(OrderStateMachine::class)->cancel($order, 'payment_expired');
    // Alerted on every sighting — the retry below is the second one.
    Log::shouldReceive('critical')->twice();
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error', 'info', 'warning');

    $service = app(PaymentConfirmationService::class);
    $result = $service->confirmPayment($intent, pcsPayment(), 'webhook');

    expect($result->status)->toBe(ConfirmationResult::LATE_CAPTURE)
        ->and($order->fresh()->status)->toBe(Order::STATUS_CANCELLED)
        ->and($order->fresh()->paid_at)->toBeNull();

    $refund = RefundIntent::where('order_id', $order->id)->sole();
    expect($refund->amount_paise)->toBe(118000)
        ->and($refund->reason_code)->toBe('late_capture')
        ->and($refund->status)->toBe(RefundIntent::STATUS_CREATED);

    $tx = LedgerTx::where('idempotency_key', 'order.late_capture:'.$order->id)->sole();
    expect((int) LedgerEntry::where('ledger_tx_id', $tx->id)->where('side', 'debit')->sum('amount_paise'))->toBe(118000)
        ->and((int) LedgerEntry::where('ledger_tx_id', $tx->id)->where('side', 'credit')->sum('amount_paise'))->toBe(118000);

    // A retry (the webhook after the callback) changes nothing.
    $again = $service->confirmPayment($intent->fresh(), pcsPayment(), 'webhook');
    expect($again->status)->toBe(ConfirmationResult::LATE_CAPTURE)
        ->and(RefundIntent::where('order_id', $order->id)->count())->toBe(1)
        ->and(LedgerTx::where('idempotency_key', 'order.late_capture:'.$order->id)->count())->toBe(1);
    Queue::assertPushed(SendRazorpayRefundJob::class, 1);
});

it('PCS-10: the callback refuses a bad signature and an order id that is not the intent\'s', function () {
    $order = pcsOrder();
    $intent = pcsIntent($order);
    $service = app(PaymentConfirmationService::class);

    expect(fn () => $service->confirmFromCallback($intent, 'order_pcs', 'pay_pcs', 'not-a-signature'))
        ->toThrow(SignatureVerificationException::class);
    expect(PaymentEvent::where('event_type', 'checkout.callback')->sole()->signature_verified)->toBeFalse();

    // A valid signature for a pair that belongs to another order.
    $sig = hash_hmac('sha256', 'order_other|pay_other', 'secret-xyz');
    Log::shouldReceive('critical')->once();
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error', 'info', 'warning');
    expect(fn () => $service->confirmFromCallback($intent, 'order_other', 'pay_other', $sig))
        ->toThrow(PaymentMismatchException::class);
    expect($order->fresh()->status)->toBe(Order::STATUS_PLACED);
});

it('PCS-11: a good callback fetches the payment from the API before confirming', function () {
    $order = pcsOrder();
    $intent = pcsIntent($order);
    Http::fake(['api.razorpay.com/v1/payments/pay_pcs' => Http::response([
        'id' => 'pay_pcs', 'order_id' => 'order_pcs', 'amount' => 118000, 'currency' => 'INR',
        'status' => 'captured', 'captured' => true, 'amount_refunded' => 0, 'method' => 'card',
    ], 200)]);
    $sig = hash_hmac('sha256', 'order_pcs|pay_pcs', 'secret-xyz');

    $result = app(PaymentConfirmationService::class)->confirmFromCallback($intent, 'order_pcs', 'pay_pcs', $sig);

    expect($result->status)->toBe(ConfirmationResult::CONFIRMED)
        ->and($order->fresh()->status)->toBe(Order::STATUS_PAID)
        ->and($intent->fresh()->signature_verified_at)->not->toBeNull()
        ->and($intent->fresh()->confirmed_via)->toBe('callback');
    Http::assertSentCount(1);
});

it('PCS-12: a zero-cash order confirms only when the entitlements cover the gross', function () {
    // 100% coupon: discount covers the whole gross, nothing to collect.
    $order = pcsOrder(0, ['subtotal_paise' => 50000, 'discount_paise' => 50000]);

    $result = app(PaymentConfirmationService::class)->confirmZeroCash($order, null);

    expect($result->status)->toBe(ConfirmationResult::CONFIRMED)
        ->and($order->fresh()->status)->toBe(Order::STATUS_PAID);
    $audit = AuditLog::where('action', 'order.paid')->where('subject_id', $order->id)->sole();
    expect($audit->details['settlement'])->toBe('zero_cash')->and($audit->details['discount_paise'])->toBe(50000);

    // Entitlements short of the gross: refused, never paid.
    $short = pcsOrder(0, ['subtotal_paise' => 50000, 'discount_paise' => 40000]);
    expect(fn () => app(PaymentConfirmationService::class)->confirmZeroCash($short, null))
        ->toThrow(RuntimeException::class, 'do not cover');
    expect($short->fresh()->status)->toBe(Order::STATUS_PLACED);

    // A payable above zero is not a zero-cash order, whatever the caller says.
    $cash = pcsOrder(100);
    expect(fn () => app(PaymentConfirmationService::class)->confirmZeroCash($cash, null))
        ->toThrow(RuntimeException::class, 'not a zero-cash order');
});

it('PCS-13: syncAndConfirm confirms from the gateway\'s own list of attempts', function () {
    $order = pcsOrder();
    $intent = pcsIntent($order);
    Http::fake(['api.razorpay.com/v1/orders/order_pcs/payments' => Http::response(['entity' => 'collection', 'count' => 1, 'items' => [
        ['id' => 'pay_sync', 'order_id' => 'order_pcs', 'amount' => 118000, 'currency' => 'INR', 'status' => 'captured', 'captured' => true, 'amount_refunded' => 0, 'method' => 'netbanking'],
    ]], 200)]);

    $result = app(PaymentConfirmationService::class)->syncAndConfirm($intent, PaymentIntent::CONFIRMED_VIA_RECONCILE);

    expect($result->status)->toBe(ConfirmationResult::CONFIRMED)
        ->and($order->fresh()->status)->toBe(Order::STATUS_PAID)
        ->and($intent->fresh()->gateway_payment_id)->toBe('pay_sync')
        ->and($intent->fresh()->confirmed_via)->toBe('reconcile');
});

it('PCS-14: the invoice is issued for a confirmed order and never for an unconfirmed one', function () {
    $order = pcsOrder();
    $intent = pcsIntent($order);
    expect(Invoice::where('order_id', $order->id)->exists())->toBeFalse();

    app(PaymentConfirmationService::class)->confirmPayment($intent, pcsPayment(), 'webhook');

    expect(Invoice::where('order_id', $order->id)->exists())->toBeTrue();

    $unpaid = pcsOrder();
    pcsIntent($unpaid, 'order_unpaid');
    expect(Invoice::where('order_id', $unpaid->id)->exists())->toBeFalse();
});
