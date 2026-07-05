<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Compensation\Jobs\PropagateGroupBvJob;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Services\GroupBvAccumulatorService;
use App\Modules\Compensation\Services\GroupBvReversalService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Returns\Events\OrderRefundApproved;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function gbrRoot(): Distributor
{
    $root = Distributor::factory()->create(['depth' => 0]);
    DB::table('genealogy_closure')->insert(['ancestor_id' => $root->id, 'descendant_id' => $root->id, 'depth' => 0]);

    return $root;
}

/** Create a distributor placed under $parent on $side, with closure rows. */
function gbrChild(Distributor $parent, string $side): Distributor
{
    $child = Distributor::factory()->create([
        'placement_parent_id' => $parent->id,
        'placement_side' => $side,
        'depth' => $parent->depth + 1,
    ]);
    DB::table('genealogy_closure')->insert(['ancestor_id' => $child->id, 'descendant_id' => $child->id, 'depth' => 0]);
    $ancestors = DB::table('genealogy_closure')->where('descendant_id', $parent->id)->get();
    foreach ($ancestors as $row) {
        DB::table('genealogy_closure')->insert([
            'ancestor_id' => $row->ancestor_id,
            'descendant_id' => $child->id,
            'depth' => $row->depth + 1,
        ]);
    }

    return $child;
}

/** A paid order attributed to $buyer, with an accrued personal-BV entry. */
function gbrOrder(Distributor $buyer, int $bvPaise): Order
{
    $customer = Customer::create(['display_name' => 'GBR Buyer']);

    $order = Order::create([
        'order_no' => 'ORD-GBR-'.random_int(100000, 999999),
        'customer_id' => $customer->id,
        'attributed_distributor_id' => $buyer->id,
        'attribution_source' => 'logged_in',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_PAID,
        'self_consumption' => true,
        'subtotal_paise' => $bvPaise, 'gst_paise' => 0, 'discount_paise' => 0,
        'shipping_paise' => 0, 'total_paise' => $bvPaise,
        'ship_name' => 'GBR', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Pune', 'ship_state' => 'MH', 'ship_pincode' => '411001',
        'placed_at' => now(), 'paid_at' => now(), 'idempotency_key' => 'gbr-'.uniqid(),
    ]);

    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $buyer->id,
        'order_id' => $order->id,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => now()->format('Y-m-d H:i:s.v'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $order;
}

function gbrDaily(int $distributorId, ?string $date = null): ?GroupBvDaily
{
    return GroupBvDaily::where('distributor_id', $distributorId)
        ->where('date', $date ?? Carbon::today()->toDateString())
        ->first();
}

function gbrDebt(int $distributorId, string $side): int
{
    return (int) DB::table('group_bv_debts')
        ->where('distributor_id', $distributorId)
        ->where('side', $side)
        ->value('bv_paise');
}

/** Mark a day settled for a distributor (a standing cut-off result exists). */
function gbrSettle(int $distributorId, Carbon $date, string $status = 'no_match'): void
{
    DB::table('gsb_cutoff_results')->insert([
        'distributor_id' => $distributorId,
        'cutoff_date' => $date->toDateString(),
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'gross_gsb_paise' => 0, 'admin_charge_paise' => 0, 'tds_paise' => 0, 'net_gsb_paise' => 0,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => $status,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('snapshots per-ancestor credits when propagating with an order id', function (): void {
    $root = gbrRoot();
    $left = gbrChild($root, 'L');
    $buyer = gbrChild($left, 'R'); // right of left child = still LEFT group of root

    app(GroupBvAccumulatorService::class)->propagate($buyer->id, 50_000, Carbon::today(), 7001);

    $credits = DB::table('group_bv_credits')->where('order_id', 7001)->get()->keyBy('ancestor_id');
    expect($credits)->toHaveCount(2);
    expect($credits[$root->id]->side)->toBe('L');
    expect((int) $credits[$root->id]->bv_paise)->toBe(50_000);
    expect($credits[$left->id]->side)->toBe('R');
});

it('reverses same-day unsettled BV straight off the accumulator with no debt', function (): void {
    $root = gbrRoot();
    $left = gbrChild($root, 'L');
    $order = gbrOrder($left, 25_000);

    app(GroupBvAccumulatorService::class)->propagate($left->id, 25_000, Carbon::today(), $order->id);
    DB::table('bv_propagation_log')->insert([
        'order_id' => $order->id, 'distributor_id' => $left->id, 'bv_paise' => 25_000, 'date' => Carbon::today()->toDateString(),
    ]);

    expect(gbrDaily($root->id)->left_bv_paise)->toBe(25_000);

    app(GroupBvReversalService::class)->reverseForOrder($order);

    expect(gbrDaily($root->id)->left_bv_paise)->toBe(0);
    expect(gbrDebt($root->id, 'L'))->toBe(0);

    $reversal = DB::table('group_bv_reversals')->where('order_id', $order->id)->where('ancestor_id', $root->id)->first();
    expect((int) $reversal->absorbed_paise)->toBe(25_000);
    expect((int) $reversal->debt_paise)->toBe(0);
    expect(DB::table('bv_propagation_log')->where('order_id', $order->id)->value('reversed_at'))->not->toBeNull();
});

it('turns an already-settled credit into a same-side forward debt', function (): void {
    $root = gbrRoot();
    $left = gbrChild($root, 'L');
    $order = gbrOrder($left, 25_000);

    // Credited yesterday; that day is settled and today's accumulator is empty.
    $yesterday = Carbon::yesterday();
    app(GroupBvAccumulatorService::class)->propagate($left->id, 25_000, $yesterday, $order->id);
    DB::table('bv_propagation_log')->insert([
        'order_id' => $order->id, 'distributor_id' => $left->id, 'bv_paise' => 25_000, 'date' => $yesterday->toDateString(),
    ]);
    gbrSettle($root->id, $yesterday);
    gbrSettle($left->id, $yesterday);

    app(GroupBvReversalService::class)->reverseForOrder($order);

    // Yesterday's settled accumulator is never reopened.
    expect(gbrDaily($root->id, $yesterday->toDateString())->left_bv_paise)->toBe(25_000);
    expect(gbrDaily($root->id))->toBeNull();
    expect(gbrDebt($root->id, 'L'))->toBe(25_000);
    expect(gbrDebt($root->id, 'R'))->toBe(0);
});

it('consumes the debt from subsequent purchases on the same side only', function (): void {
    $root = gbrRoot();
    $left = gbrChild($root, 'L');
    $right = gbrChild($root, 'R');

    DB::table('group_bv_debts')->insert([
        'distributor_id' => $root->id, 'side' => 'L', 'bv_paise' => 20_000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $svc = app(GroupBvAccumulatorService::class);

    // A right-side purchase must NOT touch the left debt.
    $svc->propagate($right->id, 15_000, Carbon::today(), 7101);
    expect(gbrDaily($root->id)->right_bv_paise)->toBe(15_000);
    expect(gbrDebt($root->id, 'L'))->toBe(20_000);

    // A left-side purchase pays the debt first; only the remainder is credited.
    $svc->propagate($left->id, 25_000, Carbon::today(), 7102);
    expect(gbrDaily($root->id)->left_bv_paise)->toBe(5_000);
    expect(gbrDebt($root->id, 'L'))->toBe(0);

    $credit = DB::table('group_bv_credits')->where('order_id', 7102)->where('ancestor_id', $root->id)->first();
    expect((int) $credit->bv_paise)->toBe(5_000);
    expect((int) $credit->debt_consumed_paise)->toBe(20_000);
});

it('splits a reversal into absorbed-today and forward debt when today only partially covers it', function (): void {
    $root = gbrRoot();
    $left = gbrChild($root, 'L');
    $order = gbrOrder($left, 25_000);

    // Credited yesterday (settled)...
    $yesterday = Carbon::yesterday();
    app(GroupBvAccumulatorService::class)->propagate($left->id, 25_000, $yesterday, $order->id);
    DB::table('bv_propagation_log')->insert([
        'order_id' => $order->id, 'distributor_id' => $left->id, 'bv_paise' => 25_000, 'date' => $yesterday->toDateString(),
    ]);
    gbrSettle($root->id, $yesterday);
    gbrSettle($left->id, $yesterday);
    // ...and today another order has already added 10,000 on the left.
    app(GroupBvAccumulatorService::class)->propagate($left->id, 10_000, Carbon::today(), 7201);

    app(GroupBvReversalService::class)->reverseForOrder($order);

    expect(gbrDaily($root->id)->left_bv_paise)->toBe(0);
    expect(gbrDebt($root->id, 'L'))->toBe(15_000);

    $reversal = DB::table('group_bv_reversals')->where('order_id', $order->id)->where('ancestor_id', $root->id)->first();
    expect((int) $reversal->absorbed_paise)->toBe(10_000);
    expect((int) $reversal->debt_paise)->toBe(15_000);
});

it('debits the credit day itself while its cut-off is delayed or failed', function (): void {
    $root = gbrRoot();
    $left = gbrChild($root, 'L');
    $order = gbrOrder($left, 25_000);

    // Credited yesterday but yesterday was NEVER settled for the root (cut-off
    // delayed/failed) — the reversal must debit yesterday's accumulator so the
    // late settlement cannot match cancelled BV.
    $yesterday = Carbon::yesterday();
    app(GroupBvAccumulatorService::class)->propagate($left->id, 25_000, $yesterday, $order->id);
    DB::table('bv_propagation_log')->insert([
        'order_id' => $order->id, 'distributor_id' => $left->id, 'bv_paise' => 25_000, 'date' => $yesterday->toDateString(),
    ]);
    gbrSettle($root->id, $yesterday, 'failed'); // a FAILED run does not count as settled

    app(GroupBvReversalService::class)->reverseForOrder($order);

    expect(gbrDaily($root->id, $yesterday->toDateString())->left_bv_paise)->toBe(0);
    expect(gbrDebt($root->id, 'L'))->toBe(0);
    expect(gbrDaily($root->id))->toBeNull(); // today untouched
});

it('creates no debt for an ancestor whose credit day was discarded below the 600 BV gate', function (): void {
    $root = gbrRoot();
    $left = gbrChild($root, 'L');
    $order = gbrOrder($left, 25_000);

    // Credited yesterday; the root's cut-off DISCARDED the day (below_600bv) —
    // they kept nothing, so the reversal must not dock their future BV.
    $yesterday = Carbon::yesterday();
    app(GroupBvAccumulatorService::class)->propagate($left->id, 25_000, $yesterday, $order->id);
    DB::table('bv_propagation_log')->insert([
        'order_id' => $order->id, 'distributor_id' => $left->id, 'bv_paise' => 25_000, 'date' => $yesterday->toDateString(),
    ]);
    gbrSettle($root->id, $yesterday, 'below_600bv');

    app(GroupBvReversalService::class)->reverseForOrder($order);

    expect(gbrDebt($root->id, 'L'))->toBe(0);
    expect(gbrDaily($root->id, $yesterday->toDateString())->left_bv_paise)->toBe(25_000); // untouched

    $rootReversal = DB::table('group_bv_reversals')->where('order_id', $order->id)->where('ancestor_id', $root->id)->first();
    expect((int) $rootReversal->absorbed_paise)->toBe(0);
    expect((int) $rootReversal->debt_paise)->toBe(0);
});

it('is idempotent — reversing the same order twice never double-debits', function (): void {
    $root = gbrRoot();
    $left = gbrChild($root, 'L');
    $order = gbrOrder($left, 25_000);

    app(GroupBvAccumulatorService::class)->propagate($left->id, 25_000, Carbon::yesterday(), $order->id);
    DB::table('bv_propagation_log')->insert([
        'order_id' => $order->id, 'distributor_id' => $left->id, 'bv_paise' => 25_000, 'date' => Carbon::yesterday()->toDateString(),
    ]);
    gbrSettle($root->id, Carbon::yesterday());
    gbrSettle($left->id, Carbon::yesterday());

    $svc = app(GroupBvReversalService::class);
    $svc->reverseForOrder($order);
    $svc->reverseForOrder($order);

    expect(gbrDebt($root->id, 'L'))->toBe(25_000);
    expect(DB::table('group_bv_reversals')->where('order_id', $order->id)->count())->toBe(1);
});

it('pre-empts a not-yet-run propagation so a cancelled order never credits anyone', function (): void {
    $root = gbrRoot();
    $left = gbrChild($root, 'L');
    $order = gbrOrder($left, 25_000);

    // Reversal runs first (queued propagation has not executed yet).
    app(GroupBvReversalService::class)->reverseForOrder($order);

    // The late propagation job must no-op on the pre-inserted marker.
    (new PropagateGroupBvJob($order->id, $left->id, 25_000, Carbon::today()->toDateString()))
        ->handle(app(GroupBvAccumulatorService::class));

    expect(gbrDaily($root->id))->toBeNull();
    expect(DB::table('group_bv_credits')->where('order_id', $order->id)->count())->toBe(0);
    expect(gbrDebt($root->id, 'L'))->toBe(0);
});

it('debits the originally credited ancestors even after a placement change', function (): void {
    $root = gbrRoot();
    $left = gbrChild($root, 'L');
    $order = gbrOrder($left, 25_000);

    app(GroupBvAccumulatorService::class)->propagate($left->id, 25_000, Carbon::yesterday(), $order->id);
    DB::table('bv_propagation_log')->insert([
        'order_id' => $order->id, 'distributor_id' => $left->id, 'bv_paise' => 25_000, 'date' => Carbon::yesterday()->toDateString(),
    ]);
    gbrSettle($root->id, Carbon::yesterday());
    gbrSettle($left->id, Carbon::yesterday());

    // The buyer's leg is later changed L → R; the credit snapshot must win.
    DB::table('distributors')->where('id', $left->id)->update(['placement_side' => 'R']);

    app(GroupBvReversalService::class)->reverseForOrder($order);

    expect(gbrDebt($root->id, 'L'))->toBe(25_000);
    expect(gbrDebt($root->id, 'R'))->toBe(0);
});

it('reverses group BV up the whole chain when an order is cancelled via the state machine', function (): void {
    $root = gbrRoot();
    $mid = gbrChild($root, 'L');
    $buyer = gbrChild($mid, 'L');
    $order = gbrOrder($buyer, 40_000);

    // Propagate exactly as the paid-order pipeline would.
    (new PropagateGroupBvJob($order->id, $buyer->id, 40_000, Carbon::today()->toDateString()))
        ->handle(app(GroupBvAccumulatorService::class));
    expect(gbrDaily($root->id)->left_bv_paise)->toBe(40_000);
    expect(gbrDaily($mid->id)->left_bv_paise)->toBe(40_000);

    app(OrderStateMachine::class)->cancel($order, 'Cancelled by customer');

    expect(gbrDaily($root->id)->fresh()->left_bv_paise)->toBe(0);
    expect(gbrDaily($mid->id)->fresh()->left_bv_paise)->toBe(0);
    expect(DB::table('group_bv_reversals')->where('order_id', $order->id)->count())->toBe(2);
    // Personal BV reversed too (existing behaviour, still intact).
    expect((int) DB::table('bv_ledger_entries')->where('order_id', $order->id)->sum('bv_paise'))->toBe(0);
});

it('reverses group BV when a refund is approved (cooling-off / buyback path)', function (): void {
    $root = gbrRoot();
    $buyer = gbrChild($root, 'R');
    $order = gbrOrder($buyer, 30_000);

    (new PropagateGroupBvJob($order->id, $buyer->id, 30_000, Carbon::today()->toDateString()))
        ->handle(app(GroupBvAccumulatorService::class));
    expect(gbrDaily($root->id)->right_bv_paise)->toBe(30_000);

    event(new OrderRefundApproved(
        orderId: $order->id,
        returnRequestId: 1,
        reason: 'cooling_off',
        netRefundPaise: 30_000,
        idempotencyKey: 'refund:'.$order->id,
    ));

    expect(gbrDaily($root->id)->fresh()->right_bv_paise)->toBe(0);
    expect(DB::table('bv_propagation_log')->where('order_id', $order->id)->value('reversed_at'))->not->toBeNull();
});
