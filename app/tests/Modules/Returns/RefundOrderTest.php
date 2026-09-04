<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderCoolingOff;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Returns\Events\OrderRefundApproved;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Returns\Services\RefundOrder;
use App\Modules\Returns\Services\ReturnReceiptService;
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

/** Build a delivered order ready for refund, with a customer and return request. */
function refundOrderFixture(string $status = Order::STATUS_REFUND_REQUESTED, ?int $bvPaise = null): array
{
    $user = DB::table('users')->insertGetId([
        'full_name' => 'Refund Test User', 'email' => 'ro-'.uniqid().'@test.com',
        'phone_e164' => '+9170000'.rand(10000, 99999),
        'password_hash' => bcrypt('x'), 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $customer = Customer::create([
        'display_name' => 'Refund Customer',
        'user_id' => $user,
        'email_hash' => hash('sha256', 'ro-'.$user.'@test.com'),
        'email_enc' => 'ro-'.$user.'@test.com',
        'claimed_at' => now(),
    ]);

    $orderId = DB::table('orders')->insertGetId([
        'order_no' => 'ORD-REFUND-'.rand(10000, 99999),
        'customer_id' => $customer->id,
        'payment_method' => 'online',
        'status' => $status,
        'self_consumption' => false,
        'subtotal_paise' => 118000, // ₹1180 = ₹1000 taxable + ₹180 GST
        'gst_paise' => 18000,
        'discount_paise' => 0,
        'shipping_paise' => 5000, // ₹50
        'total_paise' => 123000,  // ₹1180 + ₹50
        'ship_name' => 'Test', 'ship_phone_e164' => '+919000000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now()->subDays(35),
        'paid_at' => now()->subDays(35),
        'shipped_at' => now()->subDays(32),
        'delivered_at' => now()->subDays(15),
        'idempotency_key' => 'test-ro-'.uniqid(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $order = Order::findOrFail($orderId);

    // Open cooling-off clock (still open — 15 days in, 30 day window).
    OrderCoolingOff::create([
        'order_id' => $orderId,
        'opened_at' => now()->subDays(15),
        'ends_at' => now()->addDays(15),
        'status' => OrderCoolingOff::STATUS_OPEN,
    ]);

    // Post the forward ledger entries so reversal has something to reverse.
    // Online order: Dr razorpay, Cr prepayment (at checkout).
    DB::table('ledger_tx')->insert([
        'occurred_at' => now()->subDays(35),
        'source_module' => 'Commerce', 'source_type' => 'order.placed',
        'source_id' => $orderId, 'idempotency_key' => "order.placed:{$orderId}",
        'memo' => 'Test', 'created_at' => now(),
    ]);
    // Ship: Dr prepayment, Cr revenue.sales + gst_output + shipping.
    DB::table('ledger_tx')->insert([
        'occurred_at' => now()->subDays(32),
        'source_module' => 'Commerce', 'source_type' => 'order.shipped',
        'source_id' => $orderId, 'idempotency_key' => "order.shipped:{$orderId}",
        'memo' => 'Test', 'created_at' => now(),
    ]);

    // BV accrual if requested — requires a distributor row with FK.
    if ($bvPaise !== null) {
        disableTestForeignKeys();
        try {
            $distId = DB::table('distributors')->insertGetId([
                'user_id' => $user, 'adn' => 'ADN-RFO-'.random_int(10000, 99999),
                'pan_hash' => random_bytes(32), 'pan_last4' => '0000',
                'bank_account_enc' => 'stub', 'bank_ifsc' => 'SBIN0000000',
                'sponsor_id' => 0, 'placement_parent_id' => 0,
                'side_chosen_by' => 'referral_default', 'depth' => 0,
                'effective_date' => now()->format('Y-m-d H:i:s.v'),
                'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
                'state' => 'TS', 'is_primary_couple' => 0,
                'created_at' => now()->format('Y-m-d H:i:s.v'),
                'updated_at' => now()->format('Y-m-d H:i:s.v'),
            ]);
            DB::table('distributors')->where('id', $distId)->update([
                'sponsor_id' => $distId, 'placement_parent_id' => $distId,
            ]);
        } finally {
            enableTestForeignKeys();
        }

        BvLedgerEntry::create([
            'order_id' => $orderId,
            'distributor_id' => $distId,
            'type' => BvLedgerEntry::TYPE_ACCRUAL,
            'bv_paise' => $bvPaise,
            'effective_at' => now(),
        ]);
    }

    $returnRequest = ReturnRequest::create([
        'rma_no' => 'RMA-TEST-'.rand(10000, 99999),
        'order_id' => $orderId,
        'order_item_id' => null,
        'qty' => null,
        'reason' => ReturnRequest::REASON_COOLING_OFF,
        'opened_by_customer_id' => $customer->id,
        'status' => ReturnRequest::STATUS_OPENED,
    ]);

    return compact('order', 'customer', 'returnRequest');
}

it('RFO-01: cooling-off refund posts balanced ledger, sets status refund_approved', function (): void {
    Event::fake([OrderRefundApproved::class]);
    ['order' => $order, 'returnRequest' => $rq] = refundOrderFixture();

    app(RefundOrder::class)->execute($order, $rq, 'cooling_off', true, actorUserId: null);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_REFUND_APPROVED);
    expect($order->refund_approved_at)->not->toBeNull();

    $rq->refresh();
    expect($rq->status)->toBe(ReturnRequest::STATUS_APPROVED);
});

it('RFO-02: cooling-off closes the cooling-off clock', function (): void {
    ['order' => $order, 'returnRequest' => $rq] = refundOrderFixture();
    Event::fake();

    app(RefundOrder::class)->execute($order, $rq, 'cooling_off', true, actorUserId: null);

    $clock = OrderCoolingOff::where('order_id', $order->id)->first();
    expect($clock->status)->toBe(OrderCoolingOff::STATUS_CANCELLED);
});

it('RFO-03: ledger tx posted with idempotency key refund:{orderId}', function (): void {
    ['order' => $order, 'returnRequest' => $rq] = refundOrderFixture();
    Event::fake();

    app(RefundOrder::class)->execute($order, $rq, 'cooling_off', true, actorUserId: null);

    $tx = DB::table('ledger_tx')->where('idempotency_key', "refund:{$order->id}")->first();
    expect($tx)->not->toBeNull();
    expect($tx->source_module)->toBe('Returns');
});

it('RFO-04: cooling-off ledger entries are balanced (debit = credit)', function (): void {
    ['order' => $order, 'returnRequest' => $rq] = refundOrderFixture();
    Event::fake();

    app(RefundOrder::class)->execute($order, $rq, 'cooling_off', true, actorUserId: null);

    $tx = DB::table('ledger_tx')->where('idempotency_key', "refund:{$order->id}")->first();
    $entries = DB::table('ledger_entries')->where('ledger_tx_id', $tx->id)->get();

    $debits = $entries->where('side', 'debit')->sum('amount_paise');
    $credits = $entries->where('side', 'credit')->sum('amount_paise');

    expect($debits)->toBe($credits);
    // Net credit to refund_payable = total_paise
    $rfAccount = DB::table('ledger_accounts')->where('code', 'liability.refund_payable')->first();
    $refundPayable = $entries->where('account_id', $rfAccount->id)->where('side', 'credit')->sum('amount_paise');
    expect($refundPayable)->toBe(123000); // total_paise
});

it('RFO-05: BV reversal entry written when BV was accrued', function (): void {
    ['order' => $order, 'returnRequest' => $rq] = refundOrderFixture(bvPaise: 50000);
    Event::fake();

    app(RefundOrder::class)->execute($order, $rq, 'cooling_off', true, actorUserId: null);

    $reversal = BvLedgerEntry::where('order_id', $order->id)
        ->where('type', BvLedgerEntry::TYPE_REVERSAL)
        ->first();
    expect($reversal)->not->toBeNull();
    expect($reversal->bv_paise)->toBe(-50000);
});

it('RFO-06: OrderRefundApproved event fired', function (): void {
    Event::fake([OrderRefundApproved::class]);
    ['order' => $order, 'returnRequest' => $rq] = refundOrderFixture();

    app(RefundOrder::class)->execute($order, $rq, 'cooling_off', true, actorUserId: null);

    Event::assertDispatched(OrderRefundApproved::class, fn ($e) => $e->orderId === $order->id);
});

it('RFO-07: idempotent — second call does nothing', function (): void {
    Event::fake();
    ['order' => $order, 'returnRequest' => $rq] = refundOrderFixture();

    app(RefundOrder::class)->execute($order, $rq, 'cooling_off', true, null);
    app(RefundOrder::class)->execute($order, $rq, 'cooling_off', true, null); // second call

    $count = DB::table('ledger_tx')->where('idempotency_key', "refund:{$order->id}")->count();
    expect($count)->toBe(1); // only one tx
});

it('RFO-08: damage saleable refund — credits subtotal to refund_payable', function (): void {
    Event::fake();
    $fixture = refundOrderFixture(Order::STATUS_REFUND_INSPECTION);
    $fixture['returnRequest']->update(['reason' => ReturnRequest::REASON_DAMAGE]);
    $order = $fixture['order'];
    $rq = $fixture['returnRequest'];

    app(RefundOrder::class)->execute($order, $rq, 'damage', true, null);

    $tx = DB::table('ledger_tx')->where('idempotency_key', "refund:{$order->id}")->first();
    $entries = DB::table('ledger_entries')->where('ledger_tx_id', $tx->id)->get();
    $rfAccount = DB::table('ledger_accounts')->where('code', 'liability.refund_payable')->first();

    $refundPayable = $entries->where('account_id', $rfAccount->id)->where('side', 'credit')->sum('amount_paise');
    expect($refundPayable)->toBe(118000); // subtotal_paise (no shipping)
});

it('RFO-09: damage non-saleable refund — credits taxable only to refund_payable', function (): void {
    Event::fake();
    $fixture = refundOrderFixture(Order::STATUS_REFUND_INSPECTION);
    $fixture['returnRequest']->update(['reason' => ReturnRequest::REASON_DAMAGE]);
    $order = $fixture['order'];
    $rq = $fixture['returnRequest'];

    app(RefundOrder::class)->execute($order, $rq, 'damage', false, null);

    $tx = DB::table('ledger_tx')->where('idempotency_key', "refund:{$order->id}")->first();
    $entries = DB::table('ledger_entries')->where('ledger_tx_id', $tx->id)->get();
    $rfAccount = DB::table('ledger_accounts')->where('code', 'liability.refund_payable')->first();

    $refundPayable = $entries->where('account_id', $rfAccount->id)->where('side', 'credit')->sum('amount_paise');
    expect($refundPayable)->toBe(100000); // taxable only = subtotal - gst
});

it('RFO-10: throws for ineligible matrix combination', function (): void {
    Event::fake();
    ['order' => $order, 'returnRequest' => $rq] = refundOrderFixture();
    $rq->update(['reason' => ReturnRequest::REASON_COOLING_OFF]);

    expect(fn () => app(RefundOrder::class)->execute($order, $rq, 'cooling_off', false, null))
        ->toThrow(RuntimeException::class);
});

/**
 * Attach a repurchase-wallet payment to an order: a distributor with a
 * `repurchase_deduction` credit that was then spent on this order, exactly as
 * CheckoutService records it. Returns the distributor id.
 */
function refundOrderRepurchaseCredit(int $orderId, int $creditPaise): int
{
    $userId = DB::table('users')->insertGetId([
        'full_name' => 'Repurchase Refund User', 'email' => 'rorw-'.uniqid().'@test.com',
        'phone_e164' => '+9170001'.rand(10000, 99999),
        'password_hash' => bcrypt('x'), 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    disableTestForeignKeys();
    try {
        $distId = DB::table('distributors')->insertGetId([
            'user_id' => $userId, 'adn' => 'ADN-RWR-'.random_int(10000, 99999),
            'pan_hash' => random_bytes(32), 'pan_last4' => '0000',
            'bank_account_enc' => 'stub', 'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0, 'placement_parent_id' => 0,
            'side_chosen_by' => 'referral_default', 'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS', 'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $distId)->update([
            'sponsor_id' => $distId, 'placement_parent_id' => $distId,
        ]);
    } finally {
        enableTestForeignKeys();
    }

    $wallet = app(WalletService::class);
    $wallet->credit(
        distributorId: $distId,
        amountPaise: $creditPaise,
        type: 'repurchase_deduction',
        referenceId: null,
        referenceType: null,
        memo: 'Withheld from payout',
    );
    $wallet->debit(
        distributorId: $distId,
        amountPaise: $creditPaise,
        type: 'repurchase_wallet_used',
        referenceId: $orderId,
        referenceType: 'order',
        memo: 'Applied at checkout',
    );

    // The order was billed net of the credit, as CheckoutService stores it.
    DB::table('orders')->where('id', $orderId)->decrement('total_paise', $creditPaise);

    return $distId;
}

it('RFO-20: repurchase credit is refunded as repurchase credit, never as cash', function (): void {
    Event::fake([OrderRefundApproved::class]);
    ['order' => $order, 'returnRequest' => $rq] = refundOrderFixture();
    // ₹230 of the ₹1,230 order settled from the repurchase wallet.
    $distId = refundOrderRepurchaseCredit($order->id, 23000);

    expect(app(WalletService::class)->repurchaseWalletBalancePaise($distId))->toBe(0);

    app(RefundOrder::class)->execute($order->fresh(), $rq, 'cooling_off', true, actorUserId: null);

    // Cash payable excludes the credit: ₹1,180 + ₹50 shipping − ₹230.
    $tx = DB::table('ledger_tx')->where('idempotency_key', "refund:{$order->id}")->first();
    $payable = DB::table('ledger_entries')
        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
        ->where('ledger_entries.ledger_tx_id', $tx->id)
        ->where('ledger_accounts.code', 'liability.refund_payable')
        ->sum('ledger_entries.amount_paise');
    expect((int) $payable)->toBe(100000);

    Event::assertDispatched(OrderRefundApproved::class, fn ($e) => $e->netRefundPaise === 100000);

    // A cooling-off return: the entitlement is HELD with the cash until the
    // goods are received (terms §8), recorded on the return request so the
    // release restores exactly this amount…
    $rq->refresh();
    expect($rq->entitlement_credit_paise)->toBe(23000)
        ->and($rq->entitlements_held_at)->not->toBeNull()
        ->and($rq->entitlements_restored_at)->toBeNull()
        ->and(app(WalletService::class)->repurchaseWalletBalancePaise($distId))->toBe(0);

    // …and comes back, tied to the order, the moment the return is received.
    $staff = (int) DB::table('users')->insertGetId([
        'full_name' => 'Ops', 'email' => 'ops-'.uniqid().'@test.com', 'phone_e164' => '+9170001'.rand(10000, 99999),
        'password_hash' => bcrypt('x'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(ReturnReceiptService::class)->markReceived($rq, actorUserId: $staff, outcome: ReturnRequest::RECEIPT_RECEIVED);

    $restored = WalletLedgerEntry::where('type', 'repurchase_deduction')
        ->where('reference_type', 'order')
        ->where('reference_id', $order->id)
        ->first();
    expect($restored)->not->toBeNull();
    expect((int) $restored->amount_paise)->toBe(23000);
    expect(app(WalletService::class)->repurchaseWalletBalancePaise($distId))->toBe(23000);
    expect($rq->fresh()->entitlements_restored_at)->not->toBeNull();
});

it('RFO-24: a coupon comes off the refund for every reason, and the entry still balances', function (): void {
    Event::fake([OrderRefundApproved::class]);
    ['order' => $order, 'returnRequest' => $rq] = refundOrderFixture();
    // ₹200 coupon on the ₹1,180 product side: the buyer paid ₹980 + ₹50 shipping.
    DB::table('orders')->where('id', $order->id)->update(['discount_paise' => 20000, 'total_paise' => 103000]);

    // `damage` + saleable refunds product + GST but not shipping — and never the coupon.
    app(RefundOrder::class)->execute($order->fresh(), $rq, 'damage', true, actorUserId: null);

    Event::assertDispatched(OrderRefundApproved::class, fn ($e) => $e->netRefundPaise === 98000);

    $tx = DB::table('ledger_tx')->where('idempotency_key', "refund:{$order->id}")->first();
    $entries = DB::table('ledger_entries')->where('ledger_tx_id', $tx->id)->get();
    expect((int) $entries->where('side', 'debit')->sum('amount_paise'))->toBe((int) $entries->where('side', 'credit')->sum('amount_paise'));

    $payable = DB::table('ledger_entries')
        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
        ->where('ledger_entries.ledger_tx_id', $tx->id)
        ->where('ledger_accounts.code', 'liability.refund_payable')
        ->sum('ledger_entries.amount_paise');
    expect((int) $payable)->toBe(98000);
});

it('RFO-21: the refund ledger still balances when repurchase credit was applied', function (): void {
    ['order' => $order, 'returnRequest' => $rq] = refundOrderFixture();
    refundOrderRepurchaseCredit($order->id, 23000);
    Event::fake();

    app(RefundOrder::class)->execute($order->fresh(), $rq, 'cooling_off', true, actorUserId: null);

    $tx = DB::table('ledger_tx')->where('idempotency_key', "refund:{$order->id}")->first();
    $entries = DB::table('ledger_entries')->where('ledger_tx_id', $tx->id)->get();
    $debits = $entries->where('side', 'debit')->sum('amount_paise');
    $credits = $entries->where('side', 'credit')->sum('amount_paise');

    expect((int) $debits)->toBe((int) $credits);
});

it('RFO-22: restoration is capped at the refundable value when the return does not refund shipping', function (): void {
    Event::fake([OrderRefundApproved::class]);
    ['order' => $order, 'returnRequest' => $rq] = refundOrderFixture();
    // The whole ₹1,230 order — product + shipping — settled from the wallet.
    $distId = refundOrderRepurchaseCredit($order->id, 123000);

    // `damage` + saleable refunds product + GST but not the shipping.
    app(RefundOrder::class)->execute($order->fresh(), $rq, 'damage', true, actorUserId: null);

    // Refundable value is the ₹1,180 product side, so ₹50 of shipping credit is
    // not returned — the same loss a cash buyer takes on shipping.
    expect(app(WalletService::class)->repurchaseWalletBalancePaise($distId))->toBe(118000);
    Event::assertDispatched(OrderRefundApproved::class, fn ($e) => $e->netRefundPaise === 0);

    // Nothing is payable in cash, so no refund_payable line was posted.
    $tx = DB::table('ledger_tx')->where('idempotency_key', "refund:{$order->id}")->first();
    $payable = DB::table('ledger_entries')
        ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
        ->where('ledger_entries.ledger_tx_id', $tx->id)
        ->where('ledger_accounts.code', 'liability.refund_payable')
        ->count();
    expect($payable)->toBe(0);
});

it('RFO-23: a retried restoration cannot mint repurchase credit', function (): void {
    ['order' => $order] = refundOrderFixture();
    $distId = refundOrderRepurchaseCredit($order->id, 23000);

    $wallet = app(WalletService::class);
    expect($wallet->restoreRepurchaseCreditForOrder($order->id, 23000, 'first'))->not->toBeNull();
    expect($wallet->restoreRepurchaseCreditForOrder($order->id, 23000, 'second'))->toBeNull();
    expect($wallet->repurchaseWalletBalancePaise($distId))->toBe(23000);
});
