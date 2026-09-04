<?php

declare(strict_types=1);

/**
 * Money going back. refund_payable is extinguished only on the gateway's
 * word or a recorded manual NEFT — never by approving, cancelling or holding.
 *
 * RRF-01: send creates the gateway refund with the idempotency key and settles on a synchronous "processed"
 * RRF-02: send adopts a refund already carrying our receipt instead of creating a second one
 * RRF-03: send refuses an amount above what the gateway still holds — loudly, without creating anything
 * RRF-04: a 4xx from the gateway marks the refund failed; a 5xx is rethrown for the queue to retry
 * RRF-05: refund.processed by webhook settles the ledger and closes the order as refunded
 * RRF-06: refund.failed by webhook marks it failed and alerts
 * RRF-07: reconcile asks about stale sent refunds and settles the processed ones
 * RRF-08: a manual NEFT settlement discharges the payable against the bank account
 * RRF-09: nothing is created for an order with no captured gateway payment
 * RRF-10: cancelling a paid order queues a refund for exactly the reversed prepayment
 * RRF-11: a held refund is not sent until released
 */

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Ledger\Models\LedgerTx;
use App\Modules\Ledger\Services\LedgerPoster;
use App\Modules\Payments\Exceptions\RazorpayApiException;
use App\Modules\Payments\Jobs\SendRazorpayRefundJob;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\RefundIntent;
use App\Modules\Payments\Services\RazorpayRefundService;
use App\Modules\Returns\Models\ReturnRequest;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function rrfStaff(): int
{
    return User::create([
        'full_name' => 'RRF Staff', 'email' => 'rrf-staff-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ])->id;
}

function rrfOrder(string $status = Order::STATUS_REFUND_APPROVED, int $total = 118000): Order
{
    $customer = Customer::create(['display_name' => 'RRF Buyer']);

    return Order::create([
        'order_no' => 'ORD-RRF-'.random_int(100000, 999999),
        'customer_id' => $customer->id,
        'attribution_source' => 'direct',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => $status,
        'subtotal_paise' => $total, 'gst_paise' => 0, 'discount_paise' => 0, 'shipping_paise' => 0,
        'total_paise' => $total,
        'ship_name' => 'Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now()->subDays(10), 'paid_at' => now()->subDays(10),
        'idempotency_key' => 'rrf-'.uniqid(),
    ]);
}

function rrfCaptured(Order $order, string $paymentId = 'pay_rrf'): PaymentIntent
{
    return PaymentIntent::create([
        'order_id' => $order->id, 'gateway' => 'razorpay', 'gateway_order_id' => 'order_'.$paymentId, 'gateway_payment_id' => $paymentId,
        'mode' => 'test', 'amount_paise' => $order->total_paise, 'status' => PaymentIntent::STATUS_CAPTURED,
        'captured_at' => now()->subDays(10), 'idempotency_key' => 'order:'.$order->id,
    ]);
}

function rrfRefund(Order $order, PaymentIntent $payment, int $amount = 118000, bool $held = false): RefundIntent
{
    return RefundIntent::create([
        'order_id' => $order->id, 'payment_intent_id' => $payment->id, 'gateway' => 'razorpay', 'mode' => 'test',
        'amount_paise' => $amount, 'status' => RefundIntent::STATUS_CREATED, 'reason_code' => 'cooling_off',
        'idempotency_key' => 'refund:'.$order->id,
        'held_at' => $held ? now() : null, 'hold_reason' => $held ? RefundIntent::HOLD_AWAITING_RETURN : null,
    ]);
}

/** Book the obligation the refund discharges, so account balances read sensibly. */
function rrfOwed(Order $order, int $amount): void
{
    app(LedgerPoster::class)->transfer('Test', 'test.owed', $order->id, 'test.owed:'.$order->id, 'revenue.sales', 'liability.refund_payable', $amount);
}

function rrfBalance(string $code): int
{
    $accountId = DB::table('ledger_accounts')->where('code', $code)->value('id');
    $debits = (int) LedgerEntry::where('account_id', $accountId)->where('side', 'debit')->sum('amount_paise');
    $credits = (int) LedgerEntry::where('account_id', $accountId)->where('side', 'credit')->sum('amount_paise');

    return $credits - $debits;
}

/** @return array<string, mixed> */
function rrfRefundEntity(string $status, string $id = 'rfnd_1', string $paymentId = 'pay_rrf', ?string $receipt = null, int $amount = 118000): array
{
    return ['id' => $id, 'entity' => 'refund', 'amount' => $amount, 'currency' => 'INR', 'payment_id' => $paymentId,
        'receipt' => $receipt, 'status' => $status, 'speed_requested' => 'optimum', 'speed_processed' => 'normal',
        'acquirer_data' => ['arn' => '10000000000000']];
}

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    config()->set('arovolife.payments.razorpay', [
        'key_id' => 'rzp_test_ABCDEF123456', 'key_secret' => 's', 'webhook_secret' => 'w',
        'base_url' => 'https://api.razorpay.com/v1', 'timeout_seconds' => 5,
    ]);
    Http::preventStrayRequests();
});

it('RRF-01: send creates the gateway refund with the idempotency key and settles on a synchronous processed', function () {
    $order = rrfOrder();
    $payment = rrfCaptured($order);
    $refund = rrfRefund($order, $payment);
    rrfOwed($order, 118000);
    ReturnRequest::create(['rma_no' => 'RMA-1', 'order_id' => $order->id, 'reason' => 'cooling_off', 'opened_by_customer_id' => $order->customer_id, 'status' => ReturnRequest::STATUS_APPROVED]);
    Http::fake([
        'api.razorpay.com/v1/payments/pay_rrf/refunds' => Http::response(['entity' => 'collection', 'count' => 0, 'items' => []], 200),
        'api.razorpay.com/v1/payments/pay_rrf' => Http::response(['id' => 'pay_rrf', 'amount' => 118000, 'amount_refunded' => 0, 'status' => 'captured'], 200),
        'api.razorpay.com/v1/payments/pay_rrf/refund' => Http::response(rrfRefundEntity('processed', receipt: 'refund:'.$order->id), 200),
    ]);

    app(RazorpayRefundService::class)->send($refund);

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/payments/pay_rrf/refund')
        && $r->hasHeader('X-Refund-Idempotency', 'refund:'.$order->id)
        && $r->data()['amount'] === 118000
        && $r->data()['speed'] === 'optimum');

    $refund->refresh();
    expect($refund->status)->toBe(RefundIntent::STATUS_PROCESSED)
        ->and($refund->gateway_refund_id)->toBe('rfnd_1')
        ->and($refund->settled_via)->toBe('gateway')
        ->and(rrfBalance('liability.refund_payable'))->toBe(0)
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDED)
        ->and(ReturnRequest::where('order_id', $order->id)->sole()->status)->toBe(ReturnRequest::STATUS_REFUNDED);
    expect(LedgerTx::where('idempotency_key', 'refund.settled:'.$refund->id)->exists())->toBeTrue();
    expect(AuditLog::where('action', 'refund.settled')->where('subject_id', $refund->id)->exists())->toBeTrue();
});

it('RRF-02: send adopts a refund already carrying our receipt instead of creating a second one', function () {
    $order = rrfOrder();
    $refund = rrfRefund($order, rrfCaptured($order));
    Http::fake([
        'api.razorpay.com/v1/payments/pay_rrf/refunds' => Http::response(['entity' => 'collection', 'count' => 1, 'items' => [
            rrfRefundEntity('pending', 'rfnd_existing', receipt: 'refund:'.$order->id),
        ]], 200),
    ]);

    app(RazorpayRefundService::class)->send($refund);

    Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
    expect($refund->fresh()->gateway_refund_id)->toBe('rfnd_existing')
        ->and($refund->fresh()->status)->toBe(RefundIntent::STATUS_CREATED); // pending at the gateway
});

it('RRF-03: send refuses an amount above what the gateway still holds, loudly and without creating anything', function () {
    $order = rrfOrder();
    $refund = rrfRefund($order, rrfCaptured($order), amount: 118000);
    Http::fake([
        'api.razorpay.com/v1/payments/pay_rrf/refunds' => Http::response(['entity' => 'collection', 'count' => 0, 'items' => []], 200),
        'api.razorpay.com/v1/payments/pay_rrf' => Http::response(['id' => 'pay_rrf', 'amount' => 118000, 'amount_refunded' => 50000, 'status' => 'captured'], 200),
    ]);
    Log::shouldReceive('critical')->once();
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error', 'info', 'warning');

    app(RazorpayRefundService::class)->send($refund);

    Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
    expect($refund->fresh()->status)->toBe(RefundIntent::STATUS_FAILED)
        ->and($refund->fresh()->error_code)->toBe('exceeds_captured');
});

it('RRF-04: a 4xx marks the refund failed; a 5xx is rethrown for the queue', function () {
    $order = rrfOrder();
    $refund = rrfRefund($order, rrfCaptured($order));
    Http::fake([
        'api.razorpay.com/v1/payments/pay_rrf/refunds' => Http::response(['entity' => 'collection', 'count' => 0, 'items' => []], 200),
        'api.razorpay.com/v1/payments/pay_rrf' => Http::response(['id' => 'pay_rrf', 'amount' => 118000, 'amount_refunded' => 0, 'status' => 'captured'], 200),
        'api.razorpay.com/v1/payments/pay_rrf/refund' => Http::response(['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'The refund amount exceeds the limit']], 400),
    ]);
    Log::shouldReceive('critical')->once();
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error', 'info', 'warning');

    app(RazorpayRefundService::class)->send($refund);
    expect($refund->fresh()->status)->toBe(RefundIntent::STATUS_FAILED)
        ->and($refund->fresh()->error_code)->toBe('BAD_REQUEST_ERROR');

    $order2 = rrfOrder();
    $refund2 = rrfRefund($order2, rrfCaptured($order2, 'pay_rrf2'));
    Http::fake([
        'api.razorpay.com/v1/payments/pay_rrf2/refunds' => Http::response(['entity' => 'collection', 'count' => 0, 'items' => []], 200),
        'api.razorpay.com/v1/payments/pay_rrf2' => Http::response(['id' => 'pay_rrf2', 'amount' => 118000, 'amount_refunded' => 0, 'status' => 'captured'], 200),
        'api.razorpay.com/v1/payments/pay_rrf2/refund' => Http::response(['error' => ['code' => 'SERVER_ERROR']], 502),
    ]);
    expect(fn () => app(RazorpayRefundService::class)->send($refund2))->toThrow(RazorpayApiException::class);
    expect($refund2->fresh()->status)->toBe(RefundIntent::STATUS_CREATED)
        ->and($refund2->fresh()->attempt_count)->toBe(1);
});

it('RRF-05: refund.processed by webhook settles the ledger and closes the order as refunded', function () {
    $order = rrfOrder();
    $refund = rrfRefund($order, rrfCaptured($order));
    $refund->update(['gateway_refund_id' => 'rfnd_wh']);
    rrfOwed($order, 118000);
    $event = PaymentEvent::create(['gateway' => 'razorpay', 'direction' => 'webhook', 'event_type' => 'refund.processed', 'gateway_event_id' => 'evt_r1',
        'payload' => ['event' => 'refund.processed', 'payload' => ['refund' => ['entity' => rrfRefundEntity('processed', 'rfnd_wh')]]]]);

    $outcome = app(RazorpayRefundService::class)->applyWebhook($event);

    expect($outcome)->toBe('settled')
        ->and($refund->fresh()->status)->toBe(RefundIntent::STATUS_PROCESSED)
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDED)
        ->and($order->fresh()->refunded_at)->not->toBeNull()
        ->and(rrfBalance('liability.refund_payable'))->toBe(0)
        ->and($event->fresh()->refund_intent_id)->toBe($refund->id);

    // A redelivery settles nothing twice.
    expect(app(RazorpayRefundService::class)->applyWebhook($event))->toBe('already settled')
        ->and(LedgerTx::where('idempotency_key', 'refund.settled:'.$refund->id)->count())->toBe(1);
});

it('RRF-06: refund.failed by webhook marks it failed and alerts', function () {
    $order = rrfOrder();
    $refund = rrfRefund($order, rrfCaptured($order));
    $refund->update(['gateway_refund_id' => 'rfnd_bad']);
    $event = PaymentEvent::create(['gateway' => 'razorpay', 'direction' => 'webhook', 'event_type' => 'refund.failed', 'gateway_event_id' => 'evt_r2',
        'payload' => ['event' => 'refund.failed', 'payload' => ['refund' => ['entity' => rrfRefundEntity('failed', 'rfnd_bad')]]]]);
    Log::shouldReceive('critical')->once();
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error', 'info', 'warning');

    expect(app(RazorpayRefundService::class)->applyWebhook($event))->toBe('failed')
        ->and($refund->fresh()->status)->toBe(RefundIntent::STATUS_FAILED)
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUND_APPROVED);
});

it('RRF-07: reconcile asks about stale sent refunds and settles the processed ones', function () {
    $order = rrfOrder();
    $refund = rrfRefund($order, rrfCaptured($order));
    $refund->update(['gateway_refund_id' => 'rfnd_stale', 'last_synced_at' => now()->subHour()]);
    $fresh = rrfRefund(rrfOrder(), rrfCaptured(rrfOrder(), 'pay_other'));
    $fresh->update(['gateway_refund_id' => 'rfnd_fresh', 'last_synced_at' => now()]);
    Http::fake(['api.razorpay.com/v1/refunds/rfnd_stale' => Http::response(rrfRefundEntity('processed', 'rfnd_stale'), 200)]);

    $tally = app(RazorpayRefundService::class)->reconcileOutstanding();

    expect($tally)->toBe(['checked' => 1, 'settled' => 1, 'failed' => 0])
        ->and($refund->fresh()->status)->toBe(RefundIntent::STATUS_PROCESSED)
        ->and($fresh->fresh()->status)->toBe(RefundIntent::STATUS_CREATED);
    Http::assertSentCount(1);
});

it('RRF-08: a manual NEFT settlement discharges the payable against the bank account', function () {
    $order = rrfOrder();
    $refund = rrfRefund($order, rrfCaptured($order));
    $refund->update(['status' => RefundIntent::STATUS_FAILED, 'error_code' => 'BAD_REQUEST_ERROR']);
    rrfOwed($order, 118000);

    $staff = rrfStaff();
    app(RazorpayRefundService::class)->settleManually($refund, actorUserId: $staff, reference: 'NEFT-UTR-123', note: 'gateway refused, paid by bank');

    expect($refund->fresh()->status)->toBe(RefundIntent::STATUS_PROCESSED)
        ->and($refund->fresh()->settled_via)->toBe('manual_neft')
        ->and(rrfBalance('liability.refund_payable'))->toBe(0)
        ->and(rrfBalance('asset.cash.bank.settlement'))->toBe(118000) // credit to the asset: cash left the bank
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDED);
    $audit = AuditLog::where('action', 'refund.manual_settlement')->where('subject_id', $refund->id)->sole();
    expect($audit->actor_id)->toBe($staff)->and($audit->details['reference'])->toBe('NEFT-UTR-123');
});

it('RRF-09: nothing is created for an order with no captured gateway payment', function () {
    $order = rrfOrder();
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->once();

    expect(app(RazorpayRefundService::class)->createForReturn($order, 50000, 'damage', hold: false, actorUserId: null))->toBeNull()
        ->and(RefundIntent::count())->toBe(0);
});

it('RRF-10: cancelling a paid order queues a refund for exactly the reversed prepayment', function () {
    Queue::fake();
    $order = rrfOrder(Order::STATUS_PAID);
    rrfCaptured($order);
    // The placement entry the cancel reads back.
    app(LedgerPoster::class)->transfer('Commerce', 'order.placed', $order->id, 'order.placed:'.$order->id, 'asset.cash.gateway.razorpay', 'liability.customer_prepayment', 118000);

    app(OrderStateMachine::class)->cancel($order, 'customer_request', rrfStaff());

    $refund = RefundIntent::where('idempotency_key', 'order.cancelled:'.$order->id)->sole();
    expect($refund->amount_paise)->toBe(118000)
        ->and($refund->reason_code)->toBe('cancelled')
        ->and($refund->isHeld())->toBeFalse();
    Queue::assertPushed(SendRazorpayRefundJob::class, 1);
});

it('RRF-11: a held refund is not sent until released', function () {
    Queue::fake();
    $order = rrfOrder();
    $refund = rrfRefund($order, rrfCaptured($order), held: true);
    Http::fake();

    app(RazorpayRefundService::class)->send($refund);
    Http::assertNothingSent();
    expect($refund->fresh()->status)->toBe(RefundIntent::STATUS_CREATED);

    $staff = rrfStaff();
    app(RazorpayRefundService::class)->release($refund, actorUserId: $staff, reason: 'received');

    expect($refund->fresh()->released_at)->not->toBeNull()
        ->and($refund->fresh()->released_by_user_id)->toBe($staff);
    Queue::assertPushed(SendRazorpayRefundJob::class, 1);
    expect(AuditLog::where('action', 'refund.released')->where('subject_id', $refund->id)->exists())->toBeTrue();
});
