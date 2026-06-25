<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\GrowthBoosterBonusService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/**
 * Seed a credited GsbCutoffResult for the given distributor, date, and slab.
 */
function seedCutoffResult(int $distributorId, string $date, int $slab): GsbCutoffResult
{
    return GsbCutoffResult::create([
        'distributor_id' => $distributorId,
        'cutoff_date' => $date,
        'left_bv_paise' => 1_500_000,
        'right_bv_paise' => 1_500_000,
        'slab' => $slab,
        'gross_gsb_paise' => 100_000,
        'admin_charge_paise' => 3_000,
        'tds_paise' => 4_850,
        'net_gsb_paise' => 92_150,
        'power_cf_after_paise' => 0,
        'slab1_weaker_cf_after_paise' => 0,
        'power_side_after' => 'L',
        'status' => GsbCutoffResult::STATUS_CREDITED,
    ]);
}

/**
 * Seed a paid Order with the given total_paise, created in the given month.
 */
function seedOrder(int $totalPaise, Carbon $createdAt): void
{
    Order::create([
        'order_no' => 'ORD-'.rand(10000, 99999),
        'customer_id' => 1,
        'attributed_distributor_id' => null,
        'status' => Order::STATUS_DELIVERED,
        'payment_method' => 'online',
        'subtotal_paise' => $totalPaise,
        'gst_paise' => 0,
        'discount_paise' => 0,
        'shipping_paise' => 0,
        'total_paise' => $totalPaise,
        'self_consumption' => false,
        'idempotency_key' => Str::uuid()->toString(),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

it('returns zero results when no eligible distributors have AGP', function () {
    $month = Carbon::parse('2026-06-01');
    seedOrder(1_000_000, $month->copy()->addDays(5));

    $svc = app(GrowthBoosterBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['total_agp'])->toBe(0);
    expect($result['credited'])->toBe(0);
});

it('returns zero pool when company turnover is zero', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    seedCutoffResult($dist->id, '2026-06-10', 1);

    $svc = app(GrowthBoosterBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['pool_paise'])->toBe(0);
    expect($result['credited'])->toBe(0);
});

it('calculates correct AGP for slab 1 (12 AGP), 2 (5 AGP), 3 (2 AGP)', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    seedOrder(10_000_000, $month->copy()->addDays(5));  // ₹1,00,000 turnover → ₹5,000 pool
    seedCutoffResult($dist->id, '2026-06-05', 1);  // 12 AGP
    seedCutoffResult($dist->id, '2026-06-06', 2);  // 5 AGP
    seedCutoffResult($dist->id, '2026-06-07', 3);  // 2 AGP

    $svc = app(GrowthBoosterBonusService::class);
    $result = $svc->runForMonth($month);

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();

    expect($row)->not->toBeNull();
    expect($row->agp_earned)->toBe(19);  // 12+5+2
    expect($row->status)->toBe(GbbMonthlyResult::STATUS_CREDITED);
    expect($result['total_agp'])->toBe(19);
    expect($result['credited'])->toBe(1);
});

it('caps AGP at 120 per distributor even with many slab 1 occurrences', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    seedOrder(10_000_000, $month->copy()->addDays(5));

    // 11 × slab 1 = 132 AGP raw → should be capped at 120.
    for ($i = 1; $i <= 11; $i++) {
        seedCutoffResult($dist->id, '2026-06-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 1);
    }

    $svc = app(GrowthBoosterBonusService::class);
    $svc->runForMonth($month);

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();
    expect($row->agp_earned)->toBe(120);
});

it('distributes pool proportionally between two distributors', function () {
    $d1 = Distributor::factory()->create();
    $d2 = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    // Pool: 5% of ₹2,000 = ₹100 = 10,000 paise
    seedOrder(200_000, $month->copy()->addDays(5));
    seedCutoffResult($d1->id, '2026-06-05', 1);  // 12 AGP
    seedCutoffResult($d2->id, '2026-06-06', 2);  //  5 AGP

    $svc = app(GrowthBoosterBonusService::class);
    $result = $svc->runForMonth($month);

    // Total AGP = 17. Point value = floor(10000 / 17) = 588 paise.
    $row1 = GbbMonthlyResult::where('distributor_id', $d1->id)->first();
    $row2 = GbbMonthlyResult::where('distributor_id', $d2->id)->first();

    expect($row1->gbb_gross_paise)->toBe(588 * 12);  // 7056
    expect($row2->gbb_gross_paise)->toBe(588 * 5);   // 2940
    expect($result['total_agp'])->toBe(17);
    expect($result['credited'])->toBe(2);
});

it('deducts 5% TDS and no admin charge', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    seedOrder(200_000, $month->copy()->addDays(5));  // pool = 10,000 paise
    seedCutoffResult($dist->id, '2026-06-05', 1);   // 12 AGP → gross = 10,000 paise

    $svc = app(GrowthBoosterBonusService::class);
    $svc->runForMonth($month);

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();
    $expectedTds = (int) round($row->gbb_gross_paise * 0.05);

    expect($row->tds_paise)->toBe($expectedTds);
    expect($row->gbb_net_paise)->toBe($row->gbb_gross_paise - $row->tds_paise);
});

it('credits wallet via gbb_credit type', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    seedOrder(200_000, $month->copy()->addDays(5));
    seedCutoffResult($dist->id, '2026-06-05', 1);

    $svc = app(GrowthBoosterBonusService::class);
    $svc->runForMonth($month);

    $ledger = WalletLedgerEntry::where('distributor_id', $dist->id)
        ->where('type', 'gbb_credit')
        ->first();

    expect($ledger)->not->toBeNull();
    expect($ledger->amount_paise)->toBeGreaterThan(0);
});

it('is idempotent — re-running the same month does not double-credit', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    seedOrder(200_000, $month->copy()->addDays(5));
    seedCutoffResult($dist->id, '2026-06-05', 1);

    $svc = app(GrowthBoosterBonusService::class);
    $svc->runForMonth($month);
    $svc->runForMonth($month);  // second run

    expect(GbbMonthlyResult::where('distributor_id', $dist->id)->count())->toBe(1);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'gbb_credit')->count())->toBe(1);
});

it('skips slabs 4–7 (no AGP awarded)', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    seedOrder(200_000, $month->copy()->addDays(5));

    // Only slab 4 and above — should yield 0 AGP, no credit.
    GsbCutoffResult::create([
        'distributor_id' => $dist->id,
        'cutoff_date' => '2026-06-05',
        'left_bv_paise' => 27_000_000,
        'right_bv_paise' => 27_000_000,
        'slab' => 4,
        'gross_gsb_paise' => 1_200_000,
        'admin_charge_paise' => 30_000,
        'tds_paise' => 58_500,
        'net_gsb_paise' => 1_111_500,
        'power_cf_after_paise' => 0,
        'slab1_weaker_cf_after_paise' => 0,
        'power_side_after' => 'L',
        'status' => GsbCutoffResult::STATUS_CREDITED,
    ]);

    $svc = app(GrowthBoosterBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['total_agp'])->toBe(0);
    expect($result['credited'])->toBe(0);
});
