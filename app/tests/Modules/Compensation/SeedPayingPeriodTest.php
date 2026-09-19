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

it('refuses wherever a recompute would refuse', function (): void {
    config(['arovolife.recompute.allowed_databases' => ['some-other-database']]);

    $exit = Artisan::call('compensation:seed-paying-period', ['--month' => '2026-08', '--force' => true]);

    expect($exit)->toBe(1)
        ->and(DB::table('orders')->count())->toBe(0)
        ->and(app(RecomputeGuard::class)->isPermitted())->toBeFalse();
});
