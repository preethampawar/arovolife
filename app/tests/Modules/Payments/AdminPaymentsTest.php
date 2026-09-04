<?php

declare(strict_types=1);

/**
 * Admin → Payments, the refunds worklist, and the return-receipt actions.
 *
 * APT-01: the payments list and the timeline render for a monitoring role, with the scrubbed timeline
 * APT-02: Sync asks the gateway, confirms from its answer, and audits the actor
 * APT-03: the worklist shows held, failed and awaiting-receipt rows with their clocks
 * APT-04: Retry re-drives the same refund; Settle by NEFT discharges it with the reference
 * APT-05: operations can mark a return received; finance cannot (and cannot forfeit)
 * APT-06: finance can settle and retry; operations cannot
 * APT-07: the worklist sweep alerts at 10 days and opens a grievance ticket at 21 — once each
 * APT-08: the help page renders and the nav badge counts refunds needing attention
 */

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Grievance\Models\Ticket;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Jobs\SendRazorpayRefundJob;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\RefundIntent;
use App\Modules\Payments\Support\RefundWorklist;
use App\Modules\Returns\Models\ReturnRequest;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function aptUser(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::create([
        'full_name' => 'APT '.$role, 'email' => 'apt-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    $user->assignRole($role);

    return $user;
}

function aptOrder(string $status = Order::STATUS_PLACED, int $total = 118000): Order
{
    $customer = Customer::create(['display_name' => 'APT Buyer']);

    return Order::create([
        'order_no' => 'ORD-APT-'.random_int(100000, 999999),
        'customer_id' => $customer->id, 'attribution_source' => 'direct', 'payment_method' => Order::PAYMENT_ONLINE,
        'status' => $status, 'subtotal_paise' => $total, 'gst_paise' => 0, 'discount_paise' => 0, 'shipping_paise' => 0, 'total_paise' => $total,
        'ship_name' => 'Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now(), 'paid_at' => $status === Order::STATUS_PLACED ? null : now(),
        'idempotency_key' => 'apt-'.uniqid(),
    ]);
}

function aptIntent(Order $order, string $status = PaymentIntent::STATUS_CREATED): PaymentIntent
{
    return PaymentIntent::create([
        'order_id' => $order->id, 'gateway' => 'razorpay', 'gateway_order_id' => 'order_apt'.$order->id, 'mode' => 'test',
        'gateway_payment_id' => $status === PaymentIntent::STATUS_CAPTURED ? 'pay_apt'.$order->id : null,
        'amount_paise' => $order->total_paise, 'status' => $status, 'idempotency_key' => 'order:'.$order->id,
    ]);
}

function aptHeldReturn(int $daysAgo): ReturnRequest
{
    $order = aptOrder(Order::STATUS_REFUND_APPROVED);
    $intent = aptIntent($order, PaymentIntent::STATUS_CAPTURED);
    RefundIntent::create([
        'order_id' => $order->id, 'payment_intent_id' => $intent->id, 'gateway' => 'razorpay', 'mode' => 'test',
        'amount_paise' => 118000, 'status' => RefundIntent::STATUS_CREATED, 'reason_code' => 'cooling_off',
        'idempotency_key' => 'refund:'.$order->id, 'held_at' => now()->subDays($daysAgo), 'hold_reason' => RefundIntent::HOLD_AWAITING_RETURN,
    ]);

    return ReturnRequest::create([
        'rma_no' => 'RMA-APT-'.random_int(1000, 9999), 'order_id' => $order->id, 'reason' => 'cooling_off',
        'opened_by_customer_id' => $order->customer_id, 'status' => ReturnRequest::STATUS_APPROVED,
        'entitlements_held_at' => now()->subDays($daysAgo), 'entitlement_points_paise' => 0, 'entitlement_credit_paise' => 0,
    ]);
}

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    config()->set('arovolife.payments.razorpay', [
        'key_id' => 'rzp_test_ABCDEF123456', 'key_secret' => 's', 'webhook_secret' => 'w',
        'base_url' => 'https://api.razorpay.com/v1', 'timeout_seconds' => 5,
    ]);
    Http::preventStrayRequests();
});

it('APT-01: the payments list and the timeline render for a monitoring role, with the scrubbed timeline', function () {
    $order = aptOrder();
    $intent = aptIntent($order);
    PaymentEvent::create(['order_id' => $order->id, 'payment_intent_id' => $intent->id, 'gateway' => 'razorpay', 'direction' => 'webhook',
        'event_type' => 'payment.captured', 'gateway_event_id' => 'evt_apt', 'signature_verified' => true, 'payload' => ['id' => 'pay_x', 'card' => ['last4' => '4242']]]);
    $compliance = aptUser('admin-compliance');

    $this->actingAs($compliance)->get(route('admin.payments.index'))->assertOk()->assertSee($order->order_no)->assertSee('order_apt'.$order->id);
    $this->actingAs($compliance)->get(route('admin.payments.show', $intent))->assertOk()
        ->assertSee('payment.captured')->assertSee('signature verified')->assertSee('4242')
        // a monitoring role gets no sync button
        ->assertDontSee('Sync now');
});

it('APT-02: Sync asks the gateway, confirms from its answer, and audits the actor', function () {
    $order = aptOrder();
    $intent = aptIntent($order);
    $finance = aptUser('admin-finance');
    Http::fake(['api.razorpay.com/v1/orders/order_apt'.$order->id.'/payments' => Http::response(['entity' => 'collection', 'count' => 1, 'items' => [
        ['id' => 'pay_sync', 'order_id' => 'order_apt'.$order->id, 'amount' => 118000, 'currency' => 'INR', 'status' => 'captured', 'captured' => true, 'amount_refunded' => 0, 'method' => 'card'],
    ]], 200)]);

    $this->actingAs($finance)->post(route('admin.payments.sync', $intent))
        ->assertRedirect(route('admin.payments.show', $intent))
        ->assertSessionHas('status');

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID)
        ->and($intent->fresh()->confirmed_via)->toBe('admin');
    $audit = AuditLog::where('action', 'payment.synced_by_admin')->where('subject_id', $intent->id)->sole();
    expect($audit->actor_id)->toBe($finance->id)->and($audit->details['result'])->toBe('confirmed');
    $paid = AuditLog::where('action', 'order.paid')->where('subject_id', $order->id)->sole();
    expect($paid->actor_id)->toBe($finance->id)->and($paid->details['gateway_payment_id'])->toBe('pay_sync');
});

it('APT-03: the worklist shows held, failed and awaiting-receipt rows with their clocks', function () {
    $held = aptHeldReturn(12);
    $failedOrder = aptOrder(Order::STATUS_REFUND_APPROVED);
    $failedIntent = aptIntent($failedOrder, PaymentIntent::STATUS_CAPTURED);
    RefundIntent::create(['order_id' => $failedOrder->id, 'payment_intent_id' => $failedIntent->id, 'gateway' => 'razorpay', 'mode' => 'test',
        'amount_paise' => 118000, 'status' => RefundIntent::STATUS_FAILED, 'reason_code' => 'damage', 'idempotency_key' => 'refund:'.$failedOrder->id,
        'failed_at' => now(), 'error_code' => 'BAD_REQUEST_ERROR', 'error_description' => 'insufficient balance']);

    $this->actingAs(aptUser('admin-operations'))->get(route('admin.payments.refunds'))->assertOk()
        ->assertSee('Awaiting return receipt')
        ->assertSee('Needs manual settlement')
        ->assertSee('insufficient balance')
        ->assertSee($held->rma_no)
        ->assertSee('12');
});

it('APT-04: Retry re-drives the same refund; Settle by NEFT discharges it with the reference', function () {
    Queue::fake();
    $order = aptOrder(Order::STATUS_REFUND_APPROVED);
    $intent = aptIntent($order, PaymentIntent::STATUS_CAPTURED);
    $refund = RefundIntent::create(['order_id' => $order->id, 'payment_intent_id' => $intent->id, 'gateway' => 'razorpay', 'mode' => 'test',
        'amount_paise' => 118000, 'status' => RefundIntent::STATUS_FAILED, 'reason_code' => 'damage', 'idempotency_key' => 'refund:'.$order->id, 'failed_at' => now(), 'error_code' => 'X']);
    $finance = aptUser('admin-finance');

    $this->actingAs($finance)->post(route('admin.payments.refunds.retry', $refund))->assertRedirect(route('admin.payments.refunds'));
    expect($refund->fresh()->status)->toBe(RefundIntent::STATUS_CREATED)
        ->and($refund->fresh()->error_code)->toBeNull()
        ->and(RefundIntent::count())->toBe(1);
    Queue::assertPushed(SendRazorpayRefundJob::class, 1);
    expect(AuditLog::where('action', 'refund.retried')->where('subject_id', $refund->id)->sole()->actor_id)->toBe($finance->id);

    $this->actingAs($finance)->post(route('admin.payments.refunds.settle', $refund), ['reference' => 'UTR123456789', 'note' => 'paid by bank'])
        ->assertRedirect(route('admin.payments.refunds'));
    expect($refund->fresh()->status)->toBe(RefundIntent::STATUS_PROCESSED)
        ->and($refund->fresh()->settled_via)->toBe('manual_neft')
        ->and($order->fresh()->status)->toBe(Order::STATUS_REFUNDED);

    // A short reference is refused.
    $other = aptOrder(Order::STATUS_REFUND_APPROVED);
    $otherRefund = RefundIntent::create(['order_id' => $other->id, 'gateway' => 'razorpay', 'amount_paise' => 100, 'status' => RefundIntent::STATUS_FAILED, 'reason_code' => 'damage', 'idempotency_key' => 'refund:'.$other->id]);
    $this->actingAs($finance)->from(route('admin.payments.refunds'))->post(route('admin.payments.refunds.settle', $otherRefund), ['reference' => 'ab'])
        ->assertSessionHasErrors('reference');
});

it('APT-05: operations can mark a return received; finance cannot, and cannot forfeit', function () {
    Queue::fake();
    $return = aptHeldReturn(3);
    $operations = aptUser('admin-operations');
    $finance = aptUser('admin-finance');

    $this->actingAs($finance)->post(route('admin.returns.receive', $return), ['outcome' => 'received'])->assertForbidden();
    $this->actingAs($finance)->post(route('admin.returns.not-returned', $return), ['reason' => 'never came back at all'])->assertForbidden();
    expect($return->fresh()->received_at)->toBeNull();

    $this->actingAs($operations)->post(route('admin.returns.receive', $return), ['outcome' => 'received', 'note' => 'sealed'])
        ->assertRedirect(route('admin.returns.show', $return))->assertSessionHas('status');
    expect($return->fresh()->received_at)->not->toBeNull()
        ->and(RefundIntent::where('order_id', $return->order_id)->sole()->isHeld())->toBeFalse();
    Queue::assertPushed(SendRazorpayRefundJob::class, 1);

    // The page shows the outcome afterwards.
    $this->actingAs($operations)->get(route('admin.returns.show', $return))->assertOk()->assertSee('Return received');
});

it('APT-06: finance can settle and retry; operations cannot', function () {
    Queue::fake();
    $order = aptOrder(Order::STATUS_REFUND_APPROVED);
    $refund = RefundIntent::create(['order_id' => $order->id, 'gateway' => 'razorpay', 'amount_paise' => 118000, 'status' => RefundIntent::STATUS_FAILED, 'reason_code' => 'damage', 'idempotency_key' => 'refund:'.$order->id]);
    $operations = aptUser('admin-operations');

    $this->actingAs($operations)->post(route('admin.payments.refunds.retry', $refund))->assertForbidden();
    $this->actingAs($operations)->post(route('admin.payments.refunds.settle', $refund), ['reference' => 'UTR123456789'])->assertForbidden();
    $this->actingAs($operations)->post(route('admin.payments.sync', aptIntent($order)))->assertForbidden();
    expect($refund->fresh()->status)->toBe(RefundIntent::STATUS_FAILED);
    Queue::assertNothingPushed();
});

it('APT-07: the worklist sweep alerts at 10 days and opens a grievance ticket at 21 — once each', function () {
    $young = aptHeldReturn(3);
    $alert = aptHeldReturn(11);
    $escalate = aptHeldReturn(25);
    Log::shouldReceive('critical')->twice(); // one per alerted return
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error', 'info', 'warning', 'debug');

    $worklist = app(RefundWorklist::class);
    expect($worklist->sweep())->toBe(['alerted' => 2, 'escalated' => 1]);

    expect($young->fresh()->hold_alert_sent_at)->toBeNull()
        ->and($alert->fresh()->hold_alert_sent_at)->not->toBeNull()
        ->and($alert->fresh()->hold_escalated_at)->toBeNull()
        ->and($escalate->fresh()->hold_escalated_at)->not->toBeNull();

    $ticket = Ticket::where('order_id', $escalate->order_id)->sole();
    expect($ticket->category->value)->toBe('refund')
        ->and($ticket->subject)->toContain($escalate->order->order_no);
    expect(AuditLog::where('action', 'refund.hold_escalated')->where('subject_id', $escalate->id)->sole()->details['ticket_no'])->toBe($ticket->ticket_no);

    // A second sweep does nothing twice.
    expect($worklist->sweep())->toBe(['alerted' => 0, 'escalated' => 0])
        ->and(Ticket::count())->toBe(1);
});

it('APT-08: the help page renders and the nav badge counts refunds needing attention', function () {
    aptHeldReturn(12);
    $operations = aptUser('admin-operations');

    $this->actingAs($operations)->get(route('admin.help.show', 'payments'))->assertOk()->assertSee('never marked paid on anyone');
    expect(app(RefundWorklist::class)->attentionCount())->toBe(1);
    $this->actingAs($operations)->get(route('admin.payments.index'))->assertOk()->assertSee('attention');
});
