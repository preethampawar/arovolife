<?php

declare(strict_types=1);

use App\Modules\Compensation\Services\Recompute\RecomputeGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * The fixture that makes a test environment pay.
 *
 * What is worth pinning here is not the order count — that moves with the tree
 * — but the two promises the rest of the rehearsal leans on: every distributor
 * clears the 600 BV income gate (without it every cut-off row is `below_600bv`
 * and the whole exercise proves nothing), and `--rollback` removes this
 * fixture and nothing else (it runs against a database holding real rows).
 */
uses(RefreshDatabase::class);

/** The 600 BV income gate, in paise. */
const INCOME_GATE_PAISE = 60_000;

beforeEach(function (): void {
    disableTestForeignKeys();
    config(['arovolife.recompute.enabled' => true]);

    // Only the variants the command reaches for, at the BV the staging
    // catalogue carries: 10,000 / 600 / 15,000 / 6,000 BV.
    foreach ([4 => 1_000_000, 6 => 60_000, 7 => 1_500_000, 9 => 600_000] as $id => $bvPaise) {
        DB::table('product_variants')->insert([
            'id' => $id,
            'product_id' => $id,
            'variant_sku' => 'AV-TEST-'.$id,
            'name' => 'Fixture variant '.$id,
            'mrp_paise' => 100_000,
            'sale_price_paise' => 62_000,
            'bv_paise' => $bvPaise,
            'gst_rate_bp' => 1800,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // A three-level spine: one root, two at depth 1, four leaves at depth 2.
    // The root is its OWN placement parent, which is how the column (NOT NULL)
    // carries a root — and what makes leaf detection a question of who is
    // pointed AT rather than who has a null parent.
    foreach ([
        [1, 1, 0], [2, 1, 1], [3, 1, 1],
        [4, 2, 2], [5, 2, 2], [6, 3, 2], [7, 3, 2],
    ] as [$id, $parent, $depth]) {
        DB::table('distributors')->insert([
            'id' => $id,
            'user_id' => $id,
            'adn' => '90000'.$id,
            'placement_parent_id' => $parent,
            'placement_side' => $parent === $id ? null : ($id % 2 === 0 ? 'L' : 'R'),
            'depth' => $depth,
            'status' => 'active',
            'sponsor_id' => $parent,
            'side_chosen_by' => 'referral_explicit',
            'pan_hash' => hash('sha256', 'fixture'.$id, true),
            'pan_last4' => str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'state' => 'Telangana',
            'effective_date' => '2026-06-01 00:00:00',
            'cooling_off_end_at' => '2026-07-01 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
});

it('gives every distributor the personal BV that opens the income gate', function (): void {
    Artisan::call('compensation:seed-paying-period', [
        '--titles-on' => '2026-07-02',
        '--month' => '2026-08',
        '--night' => '2026-09-18',
        '--force' => true,
    ]);

    $personalBv = DB::table('bv_ledger_entries')
        ->selectRaw('distributor_id, SUM(bv_paise) total')
        ->groupBy('distributor_id')
        ->pluck('total', 'distributor_id');

    expect($personalBv)->toHaveCount(7);

    foreach ($personalBv as $distributorId => $total) {
        expect((int) $total)
            ->toBeGreaterThanOrEqual(INCOME_GATE_PAISE, "distributor {$distributorId} is below the income gate");
    }

    // The root is sized for the slab-5 title (68,000 BV) so the rehearsal spans
    // more than slab 1's arithmetic; a title below the legs is a silent cap.
    $rootTitleBv = (int) DB::table('bv_ledger_entries')
        ->where('distributor_id', 1)
        ->where('effective_at', '<', '2026-08-01')
        ->sum('bv_paise');

    expect($rootTitleBv)->toBeGreaterThanOrEqual(6_800_000);
});

it('seeds the month before the target month, so the growth booster has a prior rank to read', function (): void {
    Artisan::call('compensation:seed-paying-period', [
        '--month' => '2026-08',
        '--night' => '2026-09-18',
        '--force' => true,
    ]);

    $july = DB::table('orders')->whereBetween('paid_at', ['2026-07-01', '2026-07-31 23:59:59'])->count();
    $august = DB::table('orders')->whereBetween('paid_at', ['2026-08-01', '2026-08-31 23:59:59'])->count();
    $night = DB::table('orders')->whereBetween('paid_at', ['2026-09-18', '2026-09-18 23:59:59'])->count();

    expect($july)->toBeGreaterThan(0)
        ->and($august)->toBeGreaterThan(0)
        ->and($night)->toBeGreaterThan(0);
});

it('rolls back exactly its own orders and leaves everything else standing', function (): void {
    DB::table('customers')->insert(['id' => 99, 'display_name' => 'A real buyer', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('orders')->insert([
        'id' => 9001,
        'order_no' => 'ORD-260801-REAL01',
        'customer_id' => 99,
        'attributed_distributor_id' => 1,
        'status' => 'paid',
        'idempotency_key' => 'real:9001',
        'subtotal_paise' => 1000,
        'total_paise' => 1000,
        'paid_at' => '2026-08-05 10:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => 1, 'order_id' => 9001, 'bv_paise' => 1000,
        'type' => 'accrual', 'effective_at' => '2026-08-05 10:00:00',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Artisan::call('compensation:seed-paying-period', ['--month' => '2026-08', '--force' => true]);

    expect(DB::table('orders')->count())->toBeGreaterThan(1);

    Artisan::call('compensation:seed-paying-period', ['--rollback' => true]);

    expect(DB::table('orders')->pluck('id')->all())->toBe([9001])
        ->and(DB::table('bv_ledger_entries')->count())->toBe(1)
        ->and(DB::table('order_items')->count())->toBe(0);
});

it('collects a share of the orders from an Arete centre, which is the ADC engine\'s only input', function (): void {
    DB::table('arete_centers')->insert([
        'id' => 5,
        'name' => 'Fixture centre',
        'centre_type' => 'distributor',
        'status' => 'active',
        'assigned_distributor_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Artisan::call('compensation:seed-paying-period', ['--month' => '2026-08', '--force' => true]);

    $collected = DB::table('orders')->whereNotNull('arete_center_id')->get(['arete_center_id', 'delivery_type']);

    expect($collected)->not->toBeEmpty();

    foreach ($collected as $order) {
        expect((int) $order->arete_center_id)->toBe(5)
            ->and($order->delivery_type)->toBe('collect');
    }

    // Shipped orders must not carry a centre — the pairing is the whole point.
    expect(DB::table('orders')->where('delivery_type', 'collect')->whereNull('arete_center_id')->count())->toBe(0);

    // The centre on the order is only half the input. AreteDevelopmentCenter-
    // BonusService joins shipments and requires a recorded handover, because
    // paying on the order column alone paid centres for parcels they never
    // received (R-24). Every collected order therefore needs a collected
    // shipment, or the engine runs and credits nobody — which is exactly what
    // it did on staging with 327 collected orders and no shipments at all.
    $collectedIds = DB::table('orders')->whereNotNull('arete_center_id')->pluck('id');

    $handovers = DB::table('shipments')
        ->whereIn('order_id', $collectedIds)
        ->where('arete_center_id', 5)
        ->whereNotNull('collected_at')
        ->count();

    expect($handovers)->toBe($collectedIds->count());
});

it('spends the repurchase balance the cycle is judged on, and not a paisa that lands after it', function (): void {
    Artisan::call('compensation:seed-paying-period', ['--month' => '2026-08', '--force' => true]);

    DB::table('repurchase_cycles')->insert([
        'distributor_id' => 2,
        'cycle_start_date' => '2026-07-01',
        'due_date' => '2026-07-31',
        'required_bv_paise' => 60_000,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // One credit inside the window and one after it. The verdict is frozen at
    // the window's last instant, so only the first is the balance to clear;
    // spending both would leave the wallet negative and the August cycle
    // already settled before it had begun.
    foreach ([['2026-07-15 09:00:00', 100_000], ['2026-08-05 09:00:00', 50_000]] as [$at, $amount]) {
        DB::table('wallet_ledger_entries')->insert([
            'distributor_id' => 2,
            'type' => 'repurchase_deduction',
            'amount_paise' => $amount,
            'created_at' => $at,
        ]);
    }

    Artisan::call('compensation:seed-paying-period', ['--settle-repurchase' => '2026-07']);

    $spend = DB::table('wallet_ledger_entries')->where('type', 'repurchase_wallet_used')->get();

    expect($spend)->toHaveCount(1)
        ->and((int) $spend[0]->amount_paise)->toBe(-100_000)
        ->and((string) $spend[0]->created_at)->toBe('2026-07-31 22:00:00')
        ->and($spend[0]->reference_type)->toBe('order');

    // It has to point at an order that already existed when it was spent.
    $paidAt = DB::table('orders')->where('id', $spend[0]->reference_id)->value('paid_at');
    expect($paidAt)->not->toBeNull()
        ->and(strtotime((string) $paidAt))->toBeLessThanOrEqual(strtotime('2026-07-31 22:00:00'));
});

it('settles the calendar month end as well as the cycle close, because different engines read each', function (): void {
    Artisan::call('compensation:seed-paying-period', ['--month' => '2026-08', '--force' => true]);

    // A cycle opened 02 Jul is judged on 01 Aug. The monthly gates
    // (RepurchaseWalletGateService::clearedAtMonthEnd) instead read 31 Jul
    // 23:59:59, so a fixture that settles only the cycle leaves every monthly
    // bonus blocked through a month whose daily bonuses pay.
    DB::table('repurchase_cycles')->insert([
        'distributor_id' => 2,
        'cycle_start_date' => '2026-07-02',
        'due_date' => '2026-08-01',
        'required_bv_paise' => 60_000,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([['2026-07-20 09:00:00', 100_000], ['2026-08-01 00:10:00', 30_000]] as [$at, $amount]) {
        DB::table('wallet_ledger_entries')->insert([
            'distributor_id' => 2,
            'type' => 'repurchase_deduction',
            'amount_paise' => $amount,
            'created_at' => $at,
        ]);
    }

    Artisan::call('compensation:seed-paying-period', ['--settle-repurchase' => '2026-07,2026-08']);

    $balanceAt = function (string $at): int {
        $credits = (int) DB::table('wallet_ledger_entries')->where('distributor_id', 2)
            ->where('type', 'repurchase_deduction')->where('created_at', '<=', $at)->sum('amount_paise');
        $debits = abs((int) DB::table('wallet_ledger_entries')->where('distributor_id', 2)
            ->where('type', 'repurchase_wallet_used')->where('created_at', '<=', $at)->sum('amount_paise'));

        return $credits - $debits;
    };

    // Zero at BOTH instants, and never overspent at either.
    expect($balanceAt('2026-07-31 23:59:59'))->toBe(0)
        ->and($balanceAt('2026-08-01 23:59:59'))->toBe(0);
});

it('leaves every distributor enough orders to settle both instants', function (): void {
    Artisan::call('compensation:seed-paying-period', [
        '--titles-on' => '2026-07-02',
        '--month' => '2026-08',
        '--force' => true,
    ]);

    // One spend per order is all the ledger's unique key allows, so a
    // distributor needs at least two orders before the earlier instant or the
    // second settlement has nothing to attach to and is silently left unspent.
    $counts = DB::table('orders')
        ->where('order_no', 'like', 'PS-%')
        ->where('paid_at', '<=', '2026-07-31 22:00:00')
        ->selectRaw('attributed_distributor_id, COUNT(*) n')
        ->groupBy('attributed_distributor_id')
        ->pluck('n', 'attributed_distributor_id');

    expect($counts)->toHaveCount(7);

    // Two instants take two orders, and pool drift means the settlement has to
    // be run again afterwards to clear the residue — each of those top-up
    // rounds needs another unclaimed order. With exactly two, round two spends
    // nothing and reports "had a balance but no seeded order to apply it to"
    // forever, which is how 146 cycles stayed suspended through three rounds.
    foreach ($counts as $distributorId => $n) {
        expect((int) $n)->toBeGreaterThanOrEqual(4, "distributor {$distributorId} has only {$n} order(s)");
    }
});

it('takes its wallet spends back out on rollback, because a recompute will not', function (): void {
    Artisan::call('compensation:seed-paying-period', ['--month' => '2026-08', '--force' => true]);

    DB::table('repurchase_cycles')->insert([
        'distributor_id' => 2,
        'cycle_start_date' => '2026-07-01',
        'due_date' => '2026-07-31',
        'required_bv_paise' => 60_000,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => 2,
        'type' => 'repurchase_deduction',
        'amount_paise' => 100_000,
        'created_at' => '2026-07-15 09:00:00',
    ]);

    // A spend this fixture did NOT write, which must survive the rollback.
    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => 3,
        'type' => 'repurchase_wallet_used',
        'amount_paise' => -7_000,
        'memo' => 'Applied at checkout — order #ORD-260701-REAL01',
        'created_at' => '2026-07-20 09:00:00',
    ]);

    Artisan::call('compensation:seed-paying-period', ['--settle-repurchase' => '2026-07']);
    expect(DB::table('wallet_ledger_entries')->where('type', 'repurchase_wallet_used')->count())->toBe(2);

    Artisan::call('compensation:seed-paying-period', ['--rollback' => true]);

    $left = DB::table('wallet_ledger_entries')->where('type', 'repurchase_wallet_used')->get();
    expect($left)->toHaveCount(1)
        ->and((int) $left[0]->distributor_id)->toBe(3);
});

it('refuses to roll back an order something else has attached a record to, and leaves it whole', function (): void {
    Artisan::call('compensation:seed-paying-period', ['--month' => '2026-08', '--force' => true]);

    $orderId = (int) DB::table('orders')->where('order_no', 'like', 'PS-%')->min('id');
    DB::table('payment_events')->insert([
        'order_id' => $orderId,
        'gateway' => 'razorpay',
        'direction' => 'inbound',
        'event_type' => 'payment.captured',
        'created_at' => now(),
    ]);

    $before = DB::table('orders')->count();

    $exit = Artisan::call('compensation:seed-paying-period', ['--rollback' => true]);

    // The refusal has to be total. A rollback that deleted the children and
    // then hit the RESTRICT would leave orders answering to the tag with no
    // items and no BV behind them — which is what made a re-seed stack a
    // second fixture on top of the first.
    expect($exit)->toBe(1)
        ->and(DB::table('orders')->count())->toBe($before)
        ->and(DB::table('orders')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('order_items')->whereColumn('order_items.order_id', 'orders.id'))->count())->toBe(0)
        ->and(DB::table('orders')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('bv_ledger_entries')->whereColumn('bv_ledger_entries.order_id', 'orders.id'))->count())->toBe(0);
});

it('clears the referencing rows too when forced', function (): void {
    Artisan::call('compensation:seed-paying-period', ['--month' => '2026-08', '--force' => true]);

    $orderId = (int) DB::table('orders')->where('order_no', 'like', 'PS-%')->min('id');
    DB::table('payment_events')->insert([
        'order_id' => $orderId,
        'gateway' => 'razorpay',
        'direction' => 'inbound',
        'event_type' => 'payment.captured',
        'created_at' => now(),
    ]);

    $exit = Artisan::call('compensation:seed-paying-period', ['--rollback' => true, '--force' => true]);

    expect($exit)->toBe(0)
        ->and(DB::table('orders')->where('order_no', 'like', 'PS-%')->count())->toBe(0)
        ->and(DB::table('payment_events')->count())->toBe(0);
});

it('clears its own shipments without being forced, and still stops for somebody else\'s', function (): void {
    DB::table('arete_centers')->insert([
        'id' => 5,
        'name' => 'Fixture centre',
        'centre_type' => 'distributor',
        'status' => 'active',
        'assigned_distributor_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Artisan::call('compensation:seed-paying-period', ['--month' => '2026-08', '--force' => true]);

    expect(DB::table('shipments')->count())->toBeGreaterThan(0);

    // Shipments RESTRICT the order, and this fixture now writes them — so its
    // own must not pin its own rollback. A human's still must.
    $exit = Artisan::call('compensation:seed-paying-period', ['--rollback' => true]);

    expect($exit)->toBe(0)
        ->and(DB::table('shipments')->count())->toBe(0)
        ->and(DB::table('orders')->where('order_no', 'like', 'PS-%')->count())->toBe(0);

    Artisan::call('compensation:seed-paying-period', ['--month' => '2026-08', '--force' => true]);

    $orderId = (int) DB::table('orders')->where('order_no', 'like', 'PS-%')->min('id');
    DB::table('shipments')->insert([
        'order_id' => $orderId,
        'warehouse_code' => 'DEFAULT',
        'carrier_code' => 'BLUEDART',
        'status' => 'dispatched',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(Artisan::call('compensation:seed-paying-period', ['--rollback' => true]))->toBe(1)
        ->and(DB::table('orders')->where('order_no', 'like', 'PS-%')->count())->toBeGreaterThan(0);
});

it('refuses wherever a recompute would refuse', function (): void {
    config(['arovolife.recompute.allowed_databases' => ['some-other-database']]);

    $exit = Artisan::call('compensation:seed-paying-period', ['--month' => '2026-08', '--force' => true]);

    expect($exit)->toBe(1)
        ->and(DB::table('orders')->count())->toBe(0)
        ->and(app(RecomputeGuard::class)->isPermitted())->toBeFalse();
});

it('re-sizes its own spend in place instead of needing another order', function (): void {
    Artisan::call('compensation:seed-paying-period', ['--month' => '2026-08', '--force' => true]);

    // The engines do not run in a test, so the balance to clear is written by
    // hand — same shape as the settlement tests above.
    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => 2,
        'type' => 'repurchase_deduction',
        'amount_paise' => 100_000,
        'created_at' => '2026-07-15 00:10:00',
    ]);

    Artisan::call('compensation:seed-paying-period', ['--settle-repurchase' => '2026-07']);

    $first = DB::table('wallet_ledger_entries')
        ->where('type', 'repurchase_wallet_used')
        ->where('created_at', '2026-07-31 22:00:00')
        ->get();

    expect($first)->toHaveCount(1)
        ->and((int) $first[0]->amount_paise)->toBe(-100_000);

    // A later replay credits more than the first pass could see, inside the
    // same window. Re-running must correct the SAME row: the ledger's unique
    // key allows one spend per order, so adding a second would burn another
    // order — which is what made this need three rounds and then starve.
    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => 2,
        'type' => 'repurchase_deduction',
        'amount_paise' => 50_000,
        'created_at' => '2026-07-20 00:10:00',
    ]);

    Artisan::call('compensation:seed-paying-period', ['--settle-repurchase' => '2026-07']);

    $after = DB::table('wallet_ledger_entries')
        ->where('type', 'repurchase_wallet_used')
        ->where('created_at', '2026-07-31 22:00:00')
        ->get();

    expect($after)->toHaveCount(1)
        ->and((int) $after[0]->id)->toBe((int) $first[0]->id)
        ->and((int) $after[0]->reference_id)->toBe((int) $first[0]->reference_id)
        ->and((int) $after[0]->amount_paise)->toBe(-150_000);
});

it('shrinks its own spend when a later replay credited less than it spent', function (): void {
    Artisan::call('compensation:seed-paying-period', ['--month' => '2026-08', '--force' => true]);

    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => 2,
        'type' => 'repurchase_deduction',
        'amount_paise' => 100_000,
        'created_at' => '2026-07-15 00:10:00',
    ]);

    Artisan::call('compensation:seed-paying-period', ['--settle-repurchase' => '2026-07']);

    // The replay re-derived that credit smaller. Without the correction the
    // position stays below zero behind the max(0, ...) floor.
    DB::table('wallet_ledger_entries')
        ->where('type', 'repurchase_deduction')
        ->where('distributor_id', 2)
        ->update(['amount_paise' => 60_000]);

    Artisan::call('compensation:seed-paying-period', ['--settle-repurchase' => '2026-07']);

    expect((int) DB::table('wallet_ledger_entries')
        ->where('type', 'repurchase_wallet_used')
        ->where('created_at', '2026-07-31 22:00:00')
        ->value('amount_paise'))->toBe(-60_000);
});

it('warns when the ledger was stamped by a rebuild at the real clock', function (): void {
    Artisan::call('compensation:seed-paying-period', ['--month' => '2026-08', '--force' => true]);

    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => 2,
        'type' => 'repurchase_deduction',
        'amount_paise' => 10_000,
        'reference_id' => 999_002,
        'reference_type' => 'gbb_monthly_result',
        'memo' => 'rebuild re-run, real clock',
        'created_at' => '2026-09-19 20:10:00',
    ]);

    expect(Artisan::call('compensation:seed-paying-period', ['--settle-repurchase' => '2026-07']))->toBe(0);
    expect(Artisan::output())->toContain('a wall-clock time no engine runs at');
});
