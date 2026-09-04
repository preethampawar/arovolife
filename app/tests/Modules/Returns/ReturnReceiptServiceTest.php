<?php

declare(strict_types=1);

/**
 * The release gate on a cooling-off refund: cash, points and repurchase
 * credit move together, on receipt of the goods, and not before.
 *
 * RRS-01: a cooling-off refund holds cash, points and credit; nothing reaches the buyer at cancellation
 * RRS-02: marking the return received restores the entitlements and releases the refund, once
 * RRS-03: courier-lost is treated as received
 * RRS-04: not-returned forfeits the refund, keeps the entitlements withheld, and reverts the order to delivered
 * RRS-05: an unknown outcome is refused; a closed return cannot be received
 */

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderCoolingOff;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Ledger\Models\LedgerTx;
use App\Modules\Payments\Jobs\SendRazorpayRefundJob;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\RefundIntent;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Returns\Services\RefundOrder;
use App\Modules\Returns\Services\ReturnReceiptService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function rrsStaff(): int
{
    return User::create([
        'full_name' => 'RRS Staff', 'email' => 'rrs-staff-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ])->id;
}

/** @return array{order: Order, rq: ReturnRequest, distributorId: int} */
function rrsCoolingOffOrder(int $creditPaise = 23000): array
{
    $user = User::create([
        'full_name' => 'RRS Dist', 'email' => 'rrs-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'), 'status' => 'active',
    ]);
    disableTestForeignKeys();
    try {
        $distributorId = DB::table('distributors')->insertGetId([
            'user_id' => $user->id, 'adn' => 'ADN'.random_int(10000, 99999), 'pan_hash' => random_bytes(32), 'pan_last4' => '0000',
            'bank_account_enc' => 'stub', 'bank_ifsc' => 'SBIN0000000', 'sponsor_id' => 0, 'placement_parent_id' => 0,
            'side_chosen_by' => 'referral_default', 'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'), 'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS', 'is_primary_couple' => 0, 'created_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $distributorId)->update(['sponsor_id' => $distributorId, 'placement_parent_id' => $distributorId]);
    } finally {
        enableTestForeignKeys();
    }

    $customer = Customer::create(['display_name' => 'RRS Buyer', 'user_id' => $user->id, 'distributor_id' => $distributorId]);
    $order = Order::create([
        'order_no' => 'ORD-RRS-'.random_int(100000, 999999),
        'customer_id' => $customer->id, 'attribution_source' => 'direct', 'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_DELIVERED, 'self_consumption' => true,
        'subtotal_paise' => 118000, 'gst_paise' => 18000, 'discount_paise' => 0, 'shipping_paise' => 5000,
        'total_paise' => 123000 - $creditPaise,
        'ship_name' => 'Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now()->subDays(20), 'paid_at' => now()->subDays(20), 'shipped_at' => now()->subDays(18), 'delivered_at' => now()->subDays(10),
        'idempotency_key' => 'rrs-'.uniqid(),
    ]);
    OrderCoolingOff::create(['order_id' => $order->id, 'opened_at' => now()->subDays(10), 'ends_at' => now()->addDays(20), 'status' => OrderCoolingOff::STATUS_OPEN]);
    PaymentIntent::create([
        'order_id' => $order->id, 'gateway' => 'razorpay', 'gateway_order_id' => 'order_rrs'.$order->id, 'gateway_payment_id' => 'pay_rrs'.$order->id,
        'mode' => 'test', 'amount_paise' => $order->total_paise, 'status' => PaymentIntent::STATUS_CAPTURED, 'captured_at' => now()->subDays(20),
        'idempotency_key' => 'order:'.$order->id,
    ]);

    // The credit the buyer spent on this order.
    $wallet = app(WalletService::class);
    $wallet->credit($distributorId, $creditPaise, 'repurchase_deduction', null, null, 'seed');
    $wallet->debit($distributorId, $creditPaise, 'repurchase_wallet_used', $order->id, 'order', 'spent on '.$order->order_no);

    $rq = ReturnRequest::create(['rma_no' => 'RMA-RRS-'.random_int(1000, 9999), 'order_id' => $order->id, 'reason' => 'cooling_off',
        'opened_by_customer_id' => $customer->id, 'status' => ReturnRequest::STATUS_OPENED]);
    $order->update(['status' => Order::STATUS_REFUND_REQUESTED]);

    return ['order' => $order, 'rq' => $rq, 'distributorId' => $distributorId];
}

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    Queue::fake();
});

it('RRS-01: a cooling-off refund holds cash, points and credit; nothing reaches the buyer at cancellation', function () {
    ['order' => $order, 'rq' => $rq, 'distributorId' => $distributorId] = rrsCoolingOffOrder();

    app(RefundOrder::class)->execute($order->fresh(), $rq, 'cooling_off', true, actorUserId: null);

    $rq->refresh();
    expect($order->fresh()->status)->toBe(Order::STATUS_REFUND_APPROVED)
        ->and($rq->isAwaitingReceipt())->toBeTrue()
        ->and($rq->entitlement_credit_paise)->toBe(23000)
        ->and(app(WalletService::class)->repurchaseWalletBalancePaise($distributorId))->toBe(0);

    $refund = RefundIntent::where('idempotency_key', 'refund:'.$order->id)->sole();
    expect($refund->isHeld())->toBeTrue()
        ->and($refund->amount_paise)->toBe(100000) // ₹1,230 − ₹230 credit: the cash the buyer paid
        ->and($refund->amount_paise)->toBeLessThan(123000);
    Queue::assertNotPushed(SendRazorpayRefundJob::class);
});

it('RRS-02: marking the return received restores the entitlements and releases the refund, once', function () {
    ['order' => $order, 'rq' => $rq, 'distributorId' => $distributorId] = rrsCoolingOffOrder();
    app(RefundOrder::class)->execute($order->fresh(), $rq, 'cooling_off', true, actorUserId: null);
    $service = app(ReturnReceiptService::class);

    $service->markReceived($rq->fresh(), actorUserId: $staff = rrsStaff(), outcome: ReturnRequest::RECEIPT_RECEIVED, note: 'all items back');

    $rq->refresh();
    expect($rq->received_at)->not->toBeNull()
        ->and($rq->received_by_user_id)->toBe($staff)
        ->and($rq->entitlements_restored_at)->not->toBeNull()
        ->and(app(WalletService::class)->repurchaseWalletBalancePaise($distributorId))->toBe(23000);

    $refund = RefundIntent::where('idempotency_key', 'refund:'.$order->id)->sole();
    expect($refund->isHeld())->toBeFalse()->and($refund->released_by_user_id)->toBe($staff);
    Queue::assertPushed(SendRazorpayRefundJob::class, 1);
    expect(AuditLog::where('action', 'return.received')->where('subject_id', $rq->id)->sole()->details['outcome'])->toBe('received');

    // Again: nothing doubles.
    $service->markReceived($rq->fresh(), actorUserId: $staff, outcome: ReturnRequest::RECEIPT_RECEIVED);
    expect(app(WalletService::class)->repurchaseWalletBalancePaise($distributorId))->toBe(23000);
    Queue::assertPushed(SendRazorpayRefundJob::class, 1);
});

it('RRS-03: courier-lost is treated as received', function () {
    ['order' => $order, 'rq' => $rq, 'distributorId' => $distributorId] = rrsCoolingOffOrder();
    app(RefundOrder::class)->execute($order->fresh(), $rq, 'cooling_off', true, actorUserId: null);

    app(ReturnReceiptService::class)->markReceived($rq->fresh(), actorUserId: rrsStaff(), outcome: ReturnRequest::RECEIPT_COURIER_LOST, note: 'AWB lost');

    expect(app(WalletService::class)->repurchaseWalletBalancePaise($distributorId))->toBe(23000)
        ->and(RefundIntent::where('idempotency_key', 'refund:'.$order->id)->sole()->isHeld())->toBeFalse();
    Queue::assertPushed(SendRazorpayRefundJob::class, 1);
});

it('RRS-04: not-returned forfeits the refund, withholds the entitlements, and reverts the order to delivered', function () {
    ['order' => $order, 'rq' => $rq, 'distributorId' => $distributorId] = rrsCoolingOffOrder();
    app(RefundOrder::class)->execute($order->fresh(), $rq, 'cooling_off', true, actorUserId: null);

    app(ReturnReceiptService::class)->markNotReturned($rq->fresh(), actorUserId: rrsStaff(), reason: 'no parcel after 45 days, buyer unresponsive');

    $refund = RefundIntent::where('idempotency_key', 'refund:'.$order->id)->sole();
    expect($refund->status)->toBe(RefundIntent::STATUS_FAILED)
        ->and($refund->error_code)->toBe('goods_not_returned')
        ->and(LedgerTx::where('idempotency_key', 'refund.forfeited:'.$refund->id)->exists())->toBeTrue()
        ->and(app(WalletService::class)->repurchaseWalletBalancePaise($distributorId))->toBe(0)
        ->and($order->fresh()->status)->toBe(Order::STATUS_DELIVERED)
        ->and($rq->fresh()->status)->toBe(ReturnRequest::STATUS_REJECTED)
        ->and($rq->fresh()->receipt_outcome)->toBe(ReturnRequest::RECEIPT_NOT_RETURNED);
    Queue::assertNotPushed(SendRazorpayRefundJob::class);
    expect(AuditLog::where('action', 'return.not_returned')->where('subject_id', $rq->id)->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'refund.forfeited')->where('subject_id', $refund->id)->exists())->toBeTrue();
});

it('RRS-05: an unknown outcome is refused and a closed return cannot be received', function () {
    ['order' => $order, 'rq' => $rq] = rrsCoolingOffOrder();
    app(RefundOrder::class)->execute($order->fresh(), $rq, 'cooling_off', true, actorUserId: null);
    $service = app(ReturnReceiptService::class);

    $staff = rrsStaff();
    expect(fn () => $service->markReceived($rq->fresh(), $staff, 'vanished'))->toThrow(RuntimeException::class, 'Unknown receipt outcome');

    $service->markNotReturned($rq->fresh(), $staff, 'never sent');
    expect(fn () => $service->markReceived($rq->fresh(), $staff, ReturnRequest::RECEIPT_RECEIVED))->toThrow(RuntimeException::class, 'closed as not returned');
});
