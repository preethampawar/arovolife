<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\GbbMonthlyPool;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\GrowthBoosterBonusService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/**
 * Seed a credited GsbCutoffResult for the given distributor, date, and slab.
 */
function gbbSeedCutoff(int $distributorId, string $date, int $slab): GsbCutoffResult
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
 * Seed company-wide BV for the month. The GBB pool is a percentage of the
 * signed bv_ledger_entries sum, exactly like the GSB and MSB pools — orders
 * are irrelevant to it.
 */
function gbbSeedCompanyBv(int $bvPaise, string $date = '2026-06-10'): void
{
    static $fakeOrderId = 900000;

    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => 1,
        'order_id' => $fakeOrderId++,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => $date.' 12:00:00',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

/**
 * Seed a qualified rank_qualifications row for the given month.
 */
function gbbSeedRank(int $distributorId, string $monthStart, bool $isCarryForward = false): void
{
    DB::table('rank_qualifications')->insert([
        'distributor_id' => $distributorId,
        'rank_number' => 1,
        'month_start' => $monthStart,
        'occurrence_in_month' => 1,
        'is_carry_forward' => $isCarryForward,
        'carry_forward_from_month' => null,
        'status' => 'qualified',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

/**
 * Put the distributor's repurchase cycle in the given state with the engine on.
 */
function gbbSeedCycle(int $distributorId, string $status, ?string $reason = null, int $walletPaise = 0): RepurchaseCycle
{
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    return RepurchaseCycle::create([
        'distributor_id' => $distributorId,
        'cycle_start_date' => '2026-05-05',
        'due_date' => '2026-06-04',
        'required_bv_paise' => 100_000,
        'completed_bv_paise' => 0,
        'wallet_balance_paise' => $walletPaise,
        'wallet_zeroed' => $walletPaise === 0,
        'status' => $status,
        'failure_reason' => $reason ?? ($status === RepurchaseCycle::STATUS_COMPLETED ? null : RepurchaseCycle::REASON_BV_SHORT),
        'resolved_at' => Carbon::parse('2026-06-05 00:05:00'),
    ]);
}

it('returns zero results when no eligible distributors have AGP', function () {
    gbbSeedCompanyBv(1_000_000);

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($result['total_agp'])->toBe(0);
    expect($result['credited'])->toBe(0);
});

it('freezes a zero-value pool when nobody earned AGP', function () {
    gbbSeedCompanyBv(1_000_000);

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    $pool = GbbMonthlyPool::first();

    expect($pool)->not->toBeNull();
    expect($pool->pool_paise)->toBe(40_000);       // 4% of 10,00,000 paise
    expect($pool->total_agp)->toBe(0);
    expect($pool->point_value_paise)->toBe(0);
    expect($pool->payout_paise)->toBe(0);
    expect($pool->leftover_paise)->toBe(40_000);   // the whole pool goes unspent
    expect($result['point_value_paise'])->toBe(0);
});

it('returns zero pool when company BV is zero', function () {
    $dist = Distributor::factory()->create();
    gbbSeedCutoff($dist->id, '2026-06-10', 1);

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($result['pool_paise'])->toBe(0);
    expect($result['point_value_paise'])->toBe(0);

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();
    expect($row->gbb_gross_paise)->toBe(0);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'gbb_credit')->count())->toBe(0);
});

it('calculates correct AGP for slab 1 (12 AGP), 2 (5 AGP), 3 (2 AGP)', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(10_000_000);          // ₹1,00,000 BV → ₹4,000 pool
    gbbSeedCutoff($dist->id, '2026-06-05', 1);  // 12 AGP
    gbbSeedCutoff($dist->id, '2026-06-06', 2);  // 5 AGP
    gbbSeedCutoff($dist->id, '2026-06-07', 3);  // 2 AGP

    $result = app(GrowthBoosterBonusService::class)->runForMonth($month);

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();

    expect($row)->not->toBeNull();
    expect($row->agp_earned)->toBe(19);  // 12+5+2
    expect($row->status)->toBe(GbbMonthlyResult::STATUS_CREDITED);
    expect($result['total_agp'])->toBe(19);
    expect($result['credited'])->toBe(1);
});

// Client 2026-10-09: the per-distributor 120 AGP cap is retired; only the
// point value is capped now.
it('no longer caps a single distributor\'s AGP at 120', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(10_000_000);

    // 11 × slab 1 = 132 AGP — all of it counts.
    for ($i = 1; $i <= 11; $i++) {
        gbbSeedCutoff($dist->id, '2026-06-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 1);
    }

    $result = app(GrowthBoosterBonusService::class)->runForMonth($month);

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();
    expect($row->agp_earned)->toBe(132);
    expect($result['total_agp'])->toBe(132);
});

it('caps the GBB point value at ₹240 (client example 1: 320 → 240)', function (): void {
    // 50L BV × 4% = 2,00,000; 625 AGP → raw ₹320 → capped ₹240.
    gbbSeedCompanyBv(500_000_000, '2026-07-15');

    // 625 AGP: five distributors × (10 slab-1 = 120 AGP + 1 slab-2 = 5 AGP).
    for ($d = 1; $d <= 5; $d++) {
        $dist = Distributor::factory()->create();
        for ($i = 1; $i <= 10; $i++) {
            gbbSeedCutoff($dist->id, '2026-07-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 1);
        }
        gbbSeedCutoff($dist->id, '2026-07-20', 2);
    }

    $out = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));
    $pool = GbbMonthlyPool::where('month_start', '2026-07-01')->firstOrFail();

    expect($pool->raw_point_value_paise)->toBe(32_000)
        ->and($pool->point_value_paise)->toBe(24_000)
        ->and($pool->point_value_cap_paise)->toBe(24_000)
        ->and($pool->pool_rate_bp)->toBe(400)
        ->and($pool->total_agp)->toBe(625)
        ->and($pool->pool_paise)->toBe(20_000_000)
        ->and($pool->payout_paise)->toBe(24_000 * 625)
        // The capped difference stays with the company.
        ->and($pool->leftover_paise)->toBe(20_000_000 - 24_000 * 625)
        ->and($out['point_value_paise'])->toBe(24_000);

    // Σ gross + leftover = pool.
    $gross = (int) GbbMonthlyResult::where('year_month', '2026-07-01')->sum('gbb_gross_paise');
    expect($gross + (int) $pool->leftover_paise)->toBe((int) $pool->pool_paise);
    expect(GbbMonthlyResult::where('year_month', '2026-07-01')->first()->gbb_gross_paise)->toBe(24_000 * 125);

    $details = json_decode((string) DB::table('audit_log')->where('action', 'gbb.pool.frozen')->value('details'), true);
    expect($details['raw_point_value_paise'])->toBe(32_000)
        ->and($details['point_value_cap_paise'])->toBe(24_000);
});

it('stores a raw value below the cap unchanged and freezes the cap on the row', function (): void {
    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000, '2026-06-03');     // pool = 8,000 paise
    gbbSeedCutoff($dist->id, '2026-06-05', 1);   // 12 AGP → ₹6

    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    $pool = GbbMonthlyPool::firstOrFail();
    expect($pool->point_value_cap_paise)->toBe(24_000)
        ->and($pool->raw_point_value_paise)->toBe(600)
        ->and($pool->point_value_paise)->toBe(600);

    // The admin changes the cap afterwards; the frozen month never moves.
    DB::table('settings')->updateOrInsert(['key' => 'comp.gbb.point_value_cap_paise'], ['value' => '100']);
    app()->forgetInstance(CompensationPlanSettingsService::class);
    app()->forgetInstance(GrowthBoosterBonusService::class);

    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    $after = GbbMonthlyPool::firstOrFail();
    expect(GbbMonthlyPool::count())->toBe(1)
        ->and($after->point_value_cap_paise)->toBe(24_000)
        ->and($after->point_value_paise)->toBe(600);
});

it('refuses to freeze the month when the cap setting is below ₹1 or not a whole rupee, writing nothing', function (string $cap): void {
    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000, '2026-06-03');
    gbbSeedCutoff($dist->id, '2026-06-05', 1);
    DB::table('settings')->updateOrInsert(['key' => 'comp.gbb.point_value_cap_paise'], ['value' => $cap]);

    expect(fn () => app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01')))
        ->toThrow(RuntimeException::class, 'comp.gbb.point_value_cap_paise must be');

    expect(GbbMonthlyPool::count())->toBe(0);
    expect(GbbMonthlyResult::count())->toBe(0);
    expect(WalletLedgerEntry::where('type', 'gbb_credit')->count())->toBe(0);
    expect(DB::table('audit_log')->where('action', 'gbb.pool.frozen')->exists())->toBeFalse();
})->with(['zero' => '0', 'fifty paise' => '50', 'not a whole rupee' => '24050']);

// The cap is read BEFORE a premature pool is replaced: a bad setting must not
// delete the provisional row or write a refreeze audit row on its way to the error.
it('refuses before touching a premature pool — nothing deleted, no refreeze audit row', function (): void {
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000, '2026-06-03');

    Carbon::setTestNow('2026-06-10 12:00:00');
    app(GrowthBoosterBonusService::class)->runForMonth($month);
    $premature = GbbMonthlyPool::firstOrFail();

    DB::table('settings')->updateOrInsert(['key' => 'comp.gbb.point_value_cap_paise'], ['value' => '0']);
    app()->forgetInstance(CompensationPlanSettingsService::class);
    app()->forgetInstance(GrowthBoosterBonusService::class);

    Carbon::setTestNow('2026-07-01 00:45:00');
    expect(fn () => app(GrowthBoosterBonusService::class)->runForMonth($month))
        ->toThrow(RuntimeException::class);

    expect(GbbMonthlyPool::count())->toBe(1);
    expect(GbbMonthlyPool::first()->id)->toBe($premature->id);
    expect(DB::table('audit_log')->where('action', 'gbb.pool.refrozen')->exists())->toBeFalse();
});

it('distributes pool proportionally between two distributors', function () {
    $d1 = Distributor::factory()->create();
    $d2 = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    // Pool: 4% of 2,00,000 paise BV = 8,000 paise.
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($d1->id, '2026-06-05', 1);  // 12 AGP
    gbbSeedCutoff($d2->id, '2026-06-06', 2);  //  5 AGP

    $result = app(GrowthBoosterBonusService::class)->runForMonth($month);

    // Total AGP = 17. 8,000 ÷ 17 = 470.6 paise → floored to ₹4 (400 paise).
    $row1 = GbbMonthlyResult::where('distributor_id', $d1->id)->first();
    $row2 = GbbMonthlyResult::where('distributor_id', $d2->id)->first();

    expect($row1->gbb_gross_paise)->toBe(400 * 12);  // 4800
    expect($row2->gbb_gross_paise)->toBe(400 * 5);   // 2000
    expect($result['total_agp'])->toBe(17);
    expect($result['point_value_paise'])->toBe(400);
    expect($result['credited'])->toBe(2);
});

it('sets the pool to 4% of monthly company BV, floors the point value to whole rupees and keeps the residual as leftover', function () {
    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(150_000, '2026-06-03');
    gbbSeedCompanyBv(50_000, '2026-06-20');
    // A June-30 entry is inside the month; a July-1 entry must not count.
    gbbSeedCompanyBv(100_000, '2026-07-01');

    gbbSeedCutoff($dist->id, '2026-06-05', 1);  // 12 AGP

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    $pool = GbbMonthlyPool::first();

    expect($pool->company_bv_paise)->toBe(200_000);
    expect($pool->pool_rate_bp)->toBe(400);
    expect($pool->pool_paise)->toBe(8_000);             // 4% of 2,00,000
    expect($pool->total_agp)->toBe(12);
    expect($pool->point_value_paise)->toBe(600);        // 666.7 floored to ₹6
    expect($pool->payout_paise)->toBe(7_200);
    expect($pool->leftover_paise)->toBe(800);           // flooring residual
    expect($result['point_value_paise'])->toBe(600);

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();
    expect($row->point_value_paise)->toBe(600);
    expect($row->gbb_gross_paise)->toBe(7_200);
});

it('freezes the month economics — later BV and cut-offs never reprice it', function () {
    $d1 = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000, '2026-06-03');
    gbbSeedCutoff($d1->id, '2026-06-05', 1);  // 12 AGP

    $svc = app(GrowthBoosterBonusService::class);
    $svc->runForMonth($month);

    $firstGross = GbbMonthlyResult::where('distributor_id', $d1->id)->first()->gbb_gross_paise;

    // More BV lands, and a second distributor earns AGP for the same month.
    $d2 = Distributor::factory()->create();
    gbbSeedCompanyBv(5_000_000, '2026-06-25');
    gbbSeedCutoff($d2->id, '2026-06-26', 1);  // 12 AGP

    $result = $svc->runForMonth($month);

    $pool = GbbMonthlyPool::first();

    expect(GbbMonthlyPool::count())->toBe(1);
    expect($pool->company_bv_paise)->toBe(200_000);
    expect($pool->pool_paise)->toBe(8_000);
    expect($pool->total_agp)->toBe(12);
    expect($pool->point_value_paise)->toBe(600);
    expect($result['point_value_paise'])->toBe(600);

    // The already-paid distributor is untouched; the newcomer has no roster row
    // and is refused — paying them would spend a pool already fully divided.
    expect(GbbMonthlyResult::where('distributor_id', $d1->id)->first()->gbb_gross_paise)->toBe($firstGross);
    expect(GbbMonthlyResult::where('distributor_id', $d2->id)->exists())->toBeFalse();
    expect($result['qualified_after_freeze'])->toBe(1);
});

it('refuses a distributor whose AGP lands after the freeze and never overspends the frozen pool', function () {
    $d1 = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000, '2026-06-03');   // pool = 8,000 paise
    gbbSeedCutoff($d1->id, '2026-06-05', 1);   // 12 AGP → point value ₹6

    $svc = app(GrowthBoosterBonusService::class);
    $svc->runForMonth($month);

    // A gsb:daily-cutoff re-run for a date inside the closed month adds a
    // credited cut-off that was not in the frozen denominator.
    $d2 = Distributor::factory()->create();
    gbbSeedCutoff($d2->id, '2026-06-26', 1);   // 12 AGP, after the freeze

    $second = $svc->runForMonth($month);

    expect($second['credited'])->toBe(0);
    expect($second['qualified_after_freeze'])->toBe(1);
    expect(GbbMonthlyResult::where('distributor_id', $d2->id)->exists())->toBeFalse();
    expect(WalletLedgerEntry::where('distributor_id', $d2->id)->where('type', 'gbb_credit')->count())->toBe(0);

    // The month still spends exactly what it froze.
    $pool = GbbMonthlyPool::first();
    $creditedGross = (int) GbbMonthlyResult::where('status', GbbMonthlyResult::STATUS_CREDITED)->sum('gbb_gross_paise');

    expect($creditedGross)->toBe((int) $pool->payout_paise);
    expect((int) $pool->leftover_paise)->toBe((int) $pool->pool_paise - $creditedGross);
    expect((int) $pool->leftover_paise)->toBeGreaterThanOrEqual(0);

    // R-35: the refusal permanently withholds a month's income, so it is an
    // audit fact with an 8-year retention, not a log line that rotates away.
    $audit = DB::table('audit_log')
        ->where('action', 'gbb.result.qualified_after_freeze')
        ->where('subject_type', 'distributor')
        ->where('subject_id', $d2->id)
        ->get();

    expect($audit)->toHaveCount(1);

    $details = json_decode((string) $audit->first()->details, true);
    expect($details['year_month'])->toBe('2026-06-01')
        ->and($details['agp'])->toBe(12)
        ->and($details['frozen_total_agp'])->toBe(12)
        ->and($details['refused_gross_paise'])->toBe(7_200);
});

it('freezes the repurchase deduction on the row; admin charge and TDS are left to the payout', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($dist->id, '2026-06-05', 1);

    app(GrowthBoosterBonusService::class)->runForMonth($month);

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();

    expect($row->admin_charge_paise)->toBe(0);
    expect($row->tds_paise)->toBe(0);
    expect($row->repurchase_deduction_paise)->toBe((int) floor($row->gbb_gross_paise / 10));
    expect($row->gbb_net_paise)->toBe($row->gbb_gross_paise - $row->repurchase_deduction_paise);
});

it('credits wallet via gbb_credit type', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($dist->id, '2026-06-05', 1);

    app(GrowthBoosterBonusService::class)->runForMonth($month);

    $ledger = WalletLedgerEntry::where('distributor_id', $dist->id)
        ->where('type', 'gbb_credit')
        ->first();

    expect($ledger)->not->toBeNull();
    expect($ledger->amount_paise)->toBeGreaterThan(0);
});

it('is idempotent — re-running the same month does not double-credit', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($dist->id, '2026-06-05', 1);

    $svc = app(GrowthBoosterBonusService::class);
    $svc->runForMonth($month);
    $svc->runForMonth($month);  // second run

    expect(GbbMonthlyResult::where('distributor_id', $dist->id)->count())->toBe(1);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'gbb_credit')->count())->toBe(1);
});

it('skips slabs 4–7 (no AGP awarded)', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000);

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

    $result = app(GrowthBoosterBonusService::class)->runForMonth($month);

    expect($result['total_agp'])->toBe(0);
    expect($result['credited'])->toBe(0);
});

it('excludes a distributor who held a qualified rank in any earlier month', function () {
    $d1 = Distributor::factory()->create();
    $d2 = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($d1->id, '2026-06-05', 1);  // 12 AGP, never ranked
    gbbSeedCutoff($d2->id, '2026-06-06', 1);  // 12 AGP, ranked in May
    gbbSeedRank($d2->id, '2026-05-01');

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($result['total_agp'])->toBe(12);   // only d1 in the denominator
    expect($result['credited'])->toBe(1);
    expect(GbbMonthlyResult::where('distributor_id', $d2->id)->exists())->toBeFalse();
    expect(WalletLedgerEntry::where('distributor_id', $d2->id)->where('type', 'gbb_credit')->count())->toBe(0);
});

it('excludes an earlier-month carry-forward rank too — a paid carry row still means ranked', function () {
    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($dist->id, '2026-06-05', 1);
    gbbSeedRank($dist->id, '2026-05-01', isCarryForward: true);

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($result['total_agp'])->toBe(0);
    expect($result['credited'])->toBe(0);
});

it('keeps a distributor ranked for the FIRST time in the current month eligible', function () {
    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($dist->id, '2026-06-05', 1);
    gbbSeedRank($dist->id, '2026-06-01');  // this month only — no earlier-month row

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($result['credited'])->toBe(1);
    expect(GbbMonthlyResult::where('distributor_id', $dist->id)->first()->status)
        ->toBe(GbbMonthlyResult::STATUS_CREDITED);
});

it('excludes a distributor ranked in M-2 even with no rank in M-1 (lifetime rule)', function () {
    // The client 2026-10-09: once ranked, never Growth Booster again — a
    // lapsed ranker does NOT re-enter.
    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($dist->id, '2026-06-05', 1);
    gbbSeedRank($dist->id, '2026-04-01');  // M-2, nothing in May

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($result['credited'])->toBe(0);
    expect($result['total_agp'])->toBe(0);
    expect(GbbMonthlyResult::where('distributor_id', $dist->id)->exists())->toBeFalse();
});

it('pays GBB in the month a distributor first reaches a rank, but never afterwards', function (): void {
    $d = Distributor::factory()->create()->id;
    gbbSeedCompanyBv(10_000_000, '2026-07-10');
    gbbSeedCutoff($d, '2026-07-10', 1);
    gbbSeedRank($d, '2026-07-01');          // ranks for the first time in July
    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));
    expect(GbbMonthlyResult::where('distributor_id', $d)->where('year_month', '2026-07-01')->value('status'))
        ->toBe(GbbMonthlyResult::STATUS_CREDITED);

    gbbSeedCompanyBv(10_000_000, '2026-09-10');
    gbbSeedCutoff($d, '2026-09-10', 1); // no rank in August or September
    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-09-01'));
    expect(GbbMonthlyResult::where('distributor_id', $d)->where('year_month', '2026-09-01')->exists())->toBeFalse();
});

it('re-admits a distributor to a fresh later run once an earlier month is rebuilt without their rank (F-8)', function (): void {
    // A later month's GBB roster depends on EVERY earlier month's rank
    // qualifications. This pins the oldest-first replay order F-8 relies on:
    // rebuilding July changes September only because September is re-run after.
    $d = Distributor::factory()->create()->id;
    gbbSeedCompanyBv(10_000_000, '2026-07-10');
    gbbSeedCutoff($d, '2026-07-10', 1);
    gbbSeedRank($d, '2026-07-01');
    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));

    gbbSeedCompanyBv(10_000_000, '2026-09-10');
    gbbSeedCutoff($d, '2026-09-10', 1);
    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-09-01'));
    expect(GbbMonthlyResult::where('distributor_id', $d)->where('year_month', '2026-09-01')->exists())->toBeFalse();

    // July rebuilt with no qualification; then the GBB rows MonthRebuilder::wipe()
    // removes for September. The pool row is the load-bearing one — without
    // deleting it the re-run reuses the frozen empty pool.
    DB::table('rank_qualifications')->where('distributor_id', $d)->where('month_start', '2026-07-01')->delete();
    $septemberResultIds = GbbMonthlyResult::where('year_month', '2026-09-01')->pluck('id')->all();
    DB::table('wallet_ledger_entries')->where('type', 'gbb_credit')
        ->where('reference_type', 'gbb_monthly_result')
        ->whereIn('reference_id', $septemberResultIds)->delete();
    DB::table('gbb_monthly_results')->where('year_month', '2026-09-01')->delete();
    DB::table('gbb_monthly_pools')->where('month_start', '2026-09-01')->delete();

    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-09-01'));

    expect(GbbMonthlyResult::where('distributor_id', $d)->where('year_month', '2026-09-01')->value('status'))
        ->toBe(GbbMonthlyResult::STATUS_CREDITED);
    // Exactly one September credit — a double credit would show here (July's
    // first-rank-month credit is a separate row, hence scoping to September's).
    $septemberRowId = GbbMonthlyResult::where('distributor_id', $d)->where('year_month', '2026-09-01')->value('id');
    expect(WalletLedgerEntry::where('distributor_id', $d)->where('type', 'gbb_credit')
        ->where('reference_id', $septemberRowId)->count())->toBe(1);
});

it('never bars GBB on a voided rank — a retracted rank is not a rank', function (): void {
    $d = Distributor::factory()->create()->id;
    DB::table('rank_qualifications')->insert([
        'distributor_id' => $d,
        'rank_number' => 1,
        'month_start' => '2026-07-01',
        'occurrence_in_month' => 1,
        'is_carry_forward' => false,
        'carry_forward_from_month' => null,
        'status' => 'voided',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    gbbSeedCompanyBv(10_000_000, '2026-09-10');
    gbbSeedCutoff($d, '2026-09-10', 1);

    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-09-01'));

    expect(GbbMonthlyResult::where('distributor_id', $d)->where('year_month', '2026-09-01')->value('status'))
        ->toBe(GbbMonthlyResult::STATUS_CREDITED);
});

it('re-freezes a pool that was frozen before the month closed, and discards the rows it produced', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000, '2026-06-03');

    $svc = app(GrowthBoosterBonusService::class);

    // A manual run made WHILE June was still open, before anyone had earned
    // AGP: the month freezes at a zero denominator, which would otherwise pay
    // ₹0 for June for ever.
    Carbon::setTestNow('2026-06-10 12:00:00');
    $svc->runForMonth($month);
    expect(GbbMonthlyPool::first()->total_agp)->toBe(0);

    // June closes; the earner's cut-off is in the books; the scheduled run fires.
    Carbon::setTestNow('2026-07-01 00:45:00');
    gbbSeedCutoff($dist->id, '2026-06-05', 1);  // 12 AGP
    $result = $svc->runForMonth($month);

    $pool = GbbMonthlyPool::first();

    expect(GbbMonthlyPool::count())->toBe(1);
    expect($pool->total_agp)->toBe(12);
    expect($pool->point_value_paise)->toBe(600);
    expect($result['credited'])->toBe(1);
    expect(GbbMonthlyResult::where('distributor_id', $dist->id)->first()->gbb_gross_paise)->toBe(7_200);

    // R-35: the replacement is an audit fact, not just a log line.
    expect(DB::table('audit_log')->where('action', 'gbb.pool.refrozen')->count())->toBe(1);
});

it('keeps a premature pool once a distributor has been paid against it', function () {
    $d1 = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000, '2026-06-03');
    gbbSeedCutoff($d1->id, '2026-06-05', 1);  // 12 AGP

    $svc = app(GrowthBoosterBonusService::class);

    // Mid-month run that DID pay: those economics can never move again.
    Carbon::setTestNow('2026-06-10 12:00:00');
    $svc->runForMonth($month);
    $paidPool = GbbMonthlyPool::first();
    expect($paidPool->total_agp)->toBe(12);

    // More AGP lands, and the month closes.
    Carbon::setTestNow('2026-07-01 00:45:00');
    $d2 = Distributor::factory()->create();
    gbbSeedCutoff($d2->id, '2026-06-26', 1);
    $svc->runForMonth($month);

    $pool = GbbMonthlyPool::first();

    expect(GbbMonthlyPool::count())->toBe(1);
    expect($pool->id)->toBe($paidPool->id);
    expect($pool->total_agp)->toBe(12);
    expect(DB::table('audit_log')->where('action', 'gbb.pool.refrozen')->count())->toBe(0);
    // The newcomer arrived after the roster closed, exactly as a normal re-run.
    expect(GbbMonthlyResult::where('distributor_id', $d2->id)->exists())->toBeFalse();
});

it('keeps a premature pool when the row it funded was credited at a zero gross', function () {
    // The month froze with no company BV, so the point value floored to ₹0 and
    // the earner was credited ₹0. That row is still the record of a real
    // participation in this pool — re-freezing it would move economics a
    // distributor has already been shown.
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCutoff($dist->id, '2026-06-05', 1);  // 12 AGP, no BV anywhere

    $svc = app(GrowthBoosterBonusService::class);

    Carbon::setTestNow('2026-06-10 12:00:00');
    $svc->runForMonth($month);

    $prematurePool = GbbMonthlyPool::first();
    $zeroRow = GbbMonthlyResult::where('distributor_id', $dist->id)->first();

    expect($prematurePool->total_agp)->toBe(12);
    expect($zeroRow->status)->toBe(GbbMonthlyResult::STATUS_CREDITED);
    expect($zeroRow->gbb_gross_paise)->toBe(0);

    // The month closes and BV arrives late. The pool is NOT replaced.
    Carbon::setTestNow('2026-07-01 00:45:00');
    gbbSeedCompanyBv(200_000, '2026-06-25');
    $svc->runForMonth($month);

    $pool = GbbMonthlyPool::first();

    expect(GbbMonthlyPool::count())->toBe(1);
    expect($pool->id)->toBe($prematurePool->id);
    expect($pool->pool_paise)->toBe(0);
    expect(GbbMonthlyResult::whereKey($zeroRow->id)->exists())->toBeTrue();
    expect(DB::table('audit_log')->where('action', 'gbb.pool.refrozen')->count())->toBe(0);
});

it('snapshots the rows a premature re-freeze discards into the audit details', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000, '2026-06-03');
    gbbSeedCutoff($dist->id, '2026-06-06', 2);  // 5 AGP

    $svc = app(GrowthBoosterBonusService::class);

    Carbon::setTestNow('2026-06-10 12:00:00');
    $svc->runForMonth($month);

    // A row left behind by the engine as it stood before the client's
    // 2026-09-06 rules: excluded from the denominator, gross 0, never funded by
    // the pool. Those rows are still in the database and a premature re-freeze
    // still has to snapshot them before deleting them.
    $discardedRow = GbbMonthlyResult::where('distributor_id', $dist->id)->first();
    $discardedRow->update([
        'status' => GbbMonthlyResult::STATUS_REPURCHASE_WALLET_BLOCKED,
        'gbb_gross_paise' => 0,
    ]);

    Carbon::setTestNow('2026-07-01 00:45:00');
    $svc->runForMonth($month);

    $audit = DB::table('audit_log')->where('action', 'gbb.pool.refrozen')->first();
    expect($audit)->not->toBeNull();

    $details = json_decode((string) $audit->details, true);

    expect($details['discarded_results'])->toBe(1);
    expect($details['discarded_rows'])->toHaveCount(1);
    expect($details['discarded_rows'][0]['id'])->toBe($discardedRow->id)
        ->and($details['discarded_rows'][0]['distributor_id'])->toBe($dist->id)
        ->and($details['discarded_rows'][0]['agp_earned'])->toBe(5)
        ->and($details['discarded_rows'][0]['status'])->toBe(GbbMonthlyResult::STATUS_REPURCHASE_WALLET_BLOCKED)
        ->and($details['discarded_rows'][0]['gbb_gross_paise'])->toBe(0);
});

it('refuses the monthly run when the previous month rank check never succeeded', function () {
    Feature::for(null)->activate(GrowthBoosterBonusFeature::class);

    // GBB for July reads JUNE's qualifications to exclude last month's rankers.
    // A succeeded check for July is the wrong month and must not open the gate.
    EngineRun::create([
        'engine_key' => 'rank.check',
        'period_start' => '2026-07-01',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000, '2026-07-03');
    gbbSeedCutoff($dist->id, '2026-07-05', 1);
    gbbSeedRank($dist->id, '2026-06-01');   // ranked in June — the plan bars them

    $exit = Artisan::call('gbb:monthly-run', ['--month' => '2026-07']);

    expect($exit)->toBe(Command::FAILURE);
    expect(Artisan::output())->toContain('rank:check-qualifications --month=2026-06');
    // The barred distributor was NOT credited behind an empty exclusion list.
    expect(GbbMonthlyResult::where('year_month', '2026-07-01')->count())->toBe(0);
    expect(GbbMonthlyPool::count())->toBe(0);
});

it('runs the monthly run when the previous month had no Genos BV at all', function () {
    // The first replayed month (e.g. June, before any BV exists in a full
    // recompute replay) can never produce a rank check — the scheduler never
    // runs one for a month before BV existed. With group_bv_daily AND
    // rank_qualifications both empty for June, nobody could have ranked, so
    // the exclusion set is provably empty and the prior-month prerequisite
    // is waived instead of refusing the run.
    Feature::for(null)->activate(GrowthBoosterBonusFeature::class);

    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000, '2026-07-03');
    gbbSeedCutoff($dist->id, '2026-07-05', 1);
    // No EngineRun for June, no group_bv_daily rows, no rank_qualifications row.

    $exit = Artisan::call('gbb:monthly-run', ['--month' => '2026-07']);

    expect($exit)->toBe(Command::SUCCESS);
    expect(GbbMonthlyPool::count())->toBe(1);
});

it('still refuses when the previous month has Genos BV but no rank check', function () {
    // Pins the GroupBvDaily half of monthHadNoGenosBv(): a month with actual
    // Genos BV could have produced a rank qualification, so a missing check
    // must still refuse even though rank_qualifications itself is empty for
    // that month — the waiver may not fire on BV alone.
    Feature::for(null)->activate(GrowthBoosterBonusFeature::class);

    DB::table('group_bv_daily')->insert([
        'distributor_id' => Distributor::factory()->create()->id,
        'date' => '2026-06-15',
        'left_bv_paise' => 1_000_000,
        'right_bv_paise' => 0,
        'updated_at' => now()->toDateTimeString(),
    ]);
    // No rank_qualifications row for June, no EngineRun for rank.check.

    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000, '2026-07-03');
    gbbSeedCutoff($dist->id, '2026-07-05', 1);

    $exit = Artisan::call('gbb:monthly-run', ['--month' => '2026-07']);

    expect($exit)->toBe(Command::FAILURE);
    expect(Artisan::output())->toContain('rank:check-qualifications --month=2026-06');
    expect(GbbMonthlyResult::where('year_month', '2026-07-01')->count())->toBe(0);
    expect(GbbMonthlyPool::count())->toBe(0);
});

it('refuses when an older month with Genos BV never had its rank check, even though M-1 did', function (): void {
    // The chain is only transitive: June's close aborted at the rank check and
    // was never re-run; July's own check succeeded. GBB for August reads every
    // earlier month's rankers (lifetime rule), so June's gap must refuse it.
    Feature::for(null)->activate(GrowthBoosterBonusFeature::class);

    DB::table('group_bv_daily')->insert([
        'distributor_id' => Distributor::factory()->create()->id,
        'date' => '2026-06-15',
        'left_bv_paise' => 1_000_000,
        'right_bv_paise' => 0,
        'updated_at' => now()->toDateTimeString(),
    ]);
    EngineRun::create([
        'engine_key' => 'rank.check',
        'period_start' => '2026-07-01',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000, '2026-08-03');
    gbbSeedCutoff($dist->id, '2026-08-05', 1);

    $exit = Artisan::call('gbb:monthly-run', ['--month' => '2026-08']);

    expect($exit)->toBe(Command::FAILURE);
    expect(Artisan::output())->toContain('rank:check-qualifications --month=2026-06');
    expect(GbbMonthlyPool::count())->toBe(0);
    expect(GbbMonthlyResult::where('year_month', '2026-08-01')->count())->toBe(0);
});

it('runs once every earlier month with Genos BV has a succeeded rank check', function (): void {
    Feature::for(null)->activate(GrowthBoosterBonusFeature::class);

    DB::table('group_bv_daily')->insert([
        'distributor_id' => Distributor::factory()->create()->id,
        'date' => '2026-06-15',
        'left_bv_paise' => 1_000_000,
        'right_bv_paise' => 0,
        'updated_at' => now()->toDateTimeString(),
    ]);
    foreach (['2026-06-01', '2026-07-01'] as $periodStart) {
        EngineRun::create([
            'engine_key' => 'rank.check',
            'period_start' => $periodStart,
            'status' => EngineRun::STATUS_SUCCEEDED,
            'trigger' => EngineRun::TRIGGER_CONSOLE,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000, '2026-08-03');
    gbbSeedCutoff($dist->id, '2026-08-05', 1);

    expect(Artisan::call('gbb:monthly-run', ['--month' => '2026-08']))->toBe(Command::SUCCESS);
    expect(GbbMonthlyResult::where('distributor_id', $dist->id)->where('year_month', '2026-08-01')->value('status'))
        ->toBe(GbbMonthlyResult::STATUS_CREDITED);
});

/**
 * Seed one day of Genos BV and a rank.check engine run for the month.
 *
 * @param  array<string, string>|null  $summary
 */
function gbbSeedRankMonth(string $monthStart, string $status, ?array $summary = null): void
{
    DB::table('group_bv_daily')->insert([
        'distributor_id' => Distributor::factory()->create()->id,
        'date' => Carbon::parse($monthStart)->addDays(14)->toDateString(),
        'left_bv_paise' => 1_000_000,
        'right_bv_paise' => 0,
        'updated_at' => now()->toDateTimeString(),
    ]);
    EngineRun::create([
        'engine_key' => 'rank.check',
        'period_start' => $monthStart,
        'status' => $status,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'summary' => $summary,
        'started_at' => now(),
        'finished_at' => now(),
    ]);
}

it('accepts a flag-off skipped rank check for a month before M-1 even with the Rank Bonus flag now on', function (): void {
    // The engine was off in June: no qualification row was written, so there is
    // nobody that month for the lifetime exclusion to miss.
    Feature::for(null)->activate(GrowthBoosterBonusFeature::class);
    Feature::for(null)->activate(RankBonusFeature::class);

    gbbSeedRankMonth('2026-06-01', EngineRun::STATUS_SKIPPED, ['reason' => 'feature_flag_off']);
    gbbSeedRankMonth('2026-07-01', EngineRun::STATUS_SUCCEEDED);

    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000, '2026-08-03');
    gbbSeedCutoff($dist->id, '2026-08-05', 1);

    expect(Artisan::call('gbb:monthly-run', ['--month' => '2026-08']))->toBe(Command::SUCCESS);
    expect(GbbMonthlyPool::count())->toBe(1);
});

it('keeps M-1 strict — a flag-off skipped check for M-1 still refuses once the flag is on', function (): void {
    Feature::for(null)->activate(GrowthBoosterBonusFeature::class);
    Feature::for(null)->activate(RankBonusFeature::class);

    gbbSeedRankMonth('2026-06-01', EngineRun::STATUS_SUCCEEDED);
    gbbSeedRankMonth('2026-07-01', EngineRun::STATUS_SKIPPED, ['reason' => 'feature_flag_off']);

    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000, '2026-08-03');
    gbbSeedCutoff($dist->id, '2026-08-05', 1);

    expect(Artisan::call('gbb:monthly-run', ['--month' => '2026-08']))->toBe(Command::FAILURE);
    expect(Artisan::output())->toContain('rank:check-qualifications --month=2026-07');
    expect(GbbMonthlyPool::count())->toBe(0);
});

it('runs once the previous month rank check has succeeded, still excluding last month rankers', function () {
    Feature::for(null)->activate(GrowthBoosterBonusFeature::class);

    EngineRun::create([
        'engine_key' => 'rank.check',
        'period_start' => '2026-06-01',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000, '2026-07-03');
    gbbSeedCutoff($dist->id, '2026-07-05', 1);
    gbbSeedRank($dist->id, '2026-06-01');

    expect(Artisan::call('gbb:monthly-run', ['--month' => '2026-07']))->toBe(Command::SUCCESS);
    expect(GbbMonthlyResult::where('distributor_id', $dist->id)->count())->toBe(0);
});

/**
 * Seed an unspent repurchase-wallet credit so the distributor fails the
 * repurchase wallet = ₹0 gate at month end.
 */
function gbbSeedRepurchaseWalletCredit(int $distributorId, int $amountPaise, string $createdAt): void
{
    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'type' => 'repurchase_deduction',
        'amount_paise' => abs($amountPaise),
        'reference_id' => null,
        'reference_type' => null,
        'memo' => 'test',
        'created_at' => $createdAt,
    ]);
}

it('forfeits the month for wallet money held at month end, whether or not a cycle is due', function () {
    // Client 2026-09-05, re-confirmed 2026-09-07: the month-end wallet = ₹0
    // gate is independent of the repurchase cycle. A balance at 23:59:59 on the
    // last day forfeits the month even with no cycle open at all.
    $dist = Distributor::factory()->create();
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($dist->id, '2026-06-05', 1);
    gbbSeedRepurchaseWalletCredit($dist->id, 50_000, '2026-06-03 09:00:00');

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($result['total_agp'])->toBe(0);          // outside the denominator
    expect($result['wallet_blocked'])->toBe(1);
    expect($result['credited'])->toBe(0);

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();
    expect($row->status)->toBe(GbbMonthlyResult::STATUS_REPURCHASE_WALLET_BLOCKED);
    expect($row->agp_earned)->toBe(12);
    expect($row->gbb_gross_paise)->toBe(0);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'gbb_credit')->count())->toBe(0);
});

it('blocks GBB earned on compliant days when the cycle is still failed at month end (A-G1)', function () {
    // Client 2026-10-09 (A-G1) supersedes spec 2026-09-07 §2.3: the repurchase
    // CONDITION must hold on the month's last day. The 12 AGP were earned before
    // the failure, but a distributor forfeited on 30 Jun is blocked for June.
    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);                // pool = 8,000 paise
    gbbSeedCutoff($dist->id, '2026-06-05', 1);  // 12 AGP earned on a compliant day
    gbbSeedCycle($dist->id, RepurchaseCycle::STATUS_SUSPENDED);  // due 4 Jun, failed from 5 Jun, still failed at month end

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($result['total_agp'])->toBe(0);
    expect($result['repurchase_failed'])->toBe(1);
    expect($result['credited'])->toBe(0);
    expect($result['wallet_blocked'])->toBe(0);

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();
    expect($row->status)->toBe(GbbMonthlyResult::STATUS_REPURCHASE_FAILED_BLOCKED);
    expect($row->agp_earned)->toBe(12);
    expect($row->gbb_gross_paise)->toBe(0);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'gbb_credit')->count())->toBe(0);
});

it('keeps wallet-blocked AGP outside the denominator so it never dilutes the payable', function () {
    $payable = Distributor::factory()->create();
    $blocked = Distributor::factory()->create();
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    gbbSeedCompanyBv(200_000);                     // pool = 8,000 paise
    gbbSeedCutoff($payable->id, '2026-06-05', 1);  // 12 AGP, payable
    gbbSeedCutoff($blocked->id, '2026-06-06', 2);  //  5 AGP, wallet not cleared
    gbbSeedRepurchaseWalletCredit($blocked->id, 50_000, '2026-06-20 09:00:00');

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    // 12, not 17 — blocked AGP can never be paid, so it must not price the pool.
    expect($result['total_agp'])->toBe(12);
    expect($result['point_value_paise'])->toBe(600);
    expect($result['wallet_blocked'])->toBe(1);

    expect(GbbMonthlyResult::where('distributor_id', $payable->id)->first()->gbb_gross_paise)->toBe(7_200);
    expect(GbbMonthlyResult::where('distributor_id', $blocked->id)->first()->gbb_gross_paise)->toBe(0);
});

it('a re-run prices against the frozen roster; the wallet gate is not re-judged', function () {
    $dist = Distributor::factory()->create();
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($dist->id, '2026-06-05', 1);
    gbbSeedRepurchaseWalletCredit($dist->id, 50_000, '2026-06-20 09:00:00');

    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();
    expect($row->status)->toBe(GbbMonthlyResult::STATUS_REPURCHASE_WALLET_BLOCKED);

    // A back-dated correction now says the wallet WAS empty before June closed,
    // so the gate asked live would clear them. The verdict was frozen on the
    // roster at freeze time and is never revisited — the month's denominator
    // was priced without this AGP, so paying it would overspend the pool.
    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => $dist->id,
        'type' => 'repurchase_wallet_used',
        'amount_paise' => -50_000,
        'reference_id' => null,
        'reference_type' => null,
        'memo' => 'test',
        'created_at' => '2026-06-25 09:00:00',
    ]);

    $second = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($second['credited'])->toBe(0);
    expect($second['total_agp'])->toBe(0);
    expect($row->fresh()->status)->toBe(GbbMonthlyResult::STATUS_REPURCHASE_WALLET_BLOCKED);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'gbb_credit')->count())->toBe(0);

    // The refusal is auditable — a month is never silently withheld.
    expect(DB::table('audit_log')->where('action', 'gbb.result.excluded_from_frozen_denominator')->count())
        ->toBe(1);
});

// ------------------------------------------------- month-end verdict gate (A-G1)

/**
 * Seed a repurchase cycle that failed on BV short: due $dueDate, resolved the
 * next day at 00:05 (the `repurchase:evaluate` run) unless $resolved is false,
 * and optionally fulfilled late on $fulfilledOn. Does NOT touch the engine
 * flag — callers decide.
 */
function gbbSeedFailedCycle(int $distributorId, string $dueDate, ?string $fulfilledOn = null, bool $resolved = true): RepurchaseCycle
{
    $due = Carbon::parse($dueDate);

    return RepurchaseCycle::create([
        'distributor_id' => $distributorId,
        'cycle_start_date' => $due->copy()->subDays(29)->toDateString(),
        'due_date' => $due->toDateString(),
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => 0,
        'wallet_balance_paise' => 0,
        'wallet_zeroed' => true,
        'status' => $resolved ? RepurchaseCycle::STATUS_SUSPENDED : RepurchaseCycle::STATUS_ACTIVE,
        'failure_reason' => $resolved ? RepurchaseCycle::REASON_BV_SHORT : null,
        'fulfilled_on' => $fulfilledOn,
        'resolved_at' => $resolved ? $due->copy()->addDay()->setTime(0, 5)->toDateTimeString() : null,
    ]);
}

it('blocks a distributor who is failed on the last day of the month (A-G1) and keeps them out of the denominator', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $ok = Distributor::factory()->create()->id;
    $failed = Distributor::factory()->create()->id;
    gbbSeedCompanyBv(10_000_000, '2026-07-10');
    gbbSeedCutoff($ok, '2026-07-10', 1);       // 12 AGP
    gbbSeedCutoff($failed, '2026-07-10', 1);   // 12 AGP, earned before failing
    gbbSeedFailedCycle($failed, dueDate: '2026-07-20'); // failed from 21 Jul, unfulfilled at month end

    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));
    $pool = GbbMonthlyPool::where('month_start', '2026-07-01')->firstOrFail();

    expect($pool->total_agp)->toBe(12)
        ->and(GbbMonthlyResult::where('distributor_id', $failed)->value('status'))->toBe(GbbMonthlyResult::STATUS_REPURCHASE_FAILED_BLOCKED)
        ->and(GbbMonthlyResult::where('distributor_id', $failed)->value('gbb_gross_paise'))->toBe(0);
});

it('refuses to freeze while an earner has a cycle due on or before the month end with no verdict — Run repurchase:evaluate first', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = Distributor::factory()->create()->id;
    gbbSeedCompanyBv(200_000, '2026-07-10');
    gbbSeedCutoff($dist, '2026-07-10', 1);
    // Active, due on the month's last day, not yet evaluated: an unresolved
    // cycle reads as eligible, so freezing now could pay someone about to fail.
    gbbSeedFailedCycle($dist, dueDate: '2026-07-31', resolved: false);

    expect(fn () => app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-07-01')))
        ->toThrow(RuntimeException::class, 'Run repurchase:evaluate first');

    expect(GbbMonthlyPool::count())->toBe(0)
        ->and(GbbMonthlyResult::count())->toBe(0)
        ->and(WalletLedgerEntry::where('type', 'gbb_credit')->count())->toBe(0);
});

it('refuses before replacing a premature pool when a verdict is pending — nothing deleted', function (): void {
    $dist = Distributor::factory()->create()->id;
    gbbSeedCompanyBv(200_000, '2026-07-10');
    gbbSeedCutoff($dist, '2026-07-10', 1);

    // A mid-month manual run froze July early (engine still off, so the
    // open-month wallet guard did not stop it).
    Carbon::setTestNow('2026-07-15 10:00:00');
    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));
    // Undo its credit so the premature pool would be replaceable.
    GbbMonthlyResult::query()->update(['status' => GbbMonthlyResult::STATUS_PENDING]);
    $poolId = GbbMonthlyPool::firstOrFail()->id;

    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    gbbSeedFailedCycle($dist, dueDate: '2026-07-31', resolved: false);
    Carbon::setTestNow('2026-08-01 04:00:00');

    expect(fn () => app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-07-01')))
        ->toThrow(RuntimeException::class, 'Run repurchase:evaluate first');

    expect(GbbMonthlyPool::whereKey($poolId)->exists())->toBeTrue()
        ->and(GbbMonthlyResult::count())->toBe(1);

    Carbon::setTestNow();
});

it('cannot block the month with a cycle due on its last day — that cycle is judged from the 1st (F-1)', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = Distributor::factory()->create()->id;
    gbbSeedCompanyBv(200_000, '2026-07-10');
    gbbSeedCutoff($dist, '2026-07-10', 1);
    // Due 31 Jul, resolved failed on 1 Aug, never fulfilled: forfeited from
    // 1 Aug, so 31 Jul itself is still eligible.
    gbbSeedFailedCycle($dist, dueDate: '2026-07-31');

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));

    expect($result['credited'])->toBe(1)
        ->and($result['repurchase_failed'])->toBe(0)
        ->and($result['total_agp'])->toBe(12)
        ->and(GbbMonthlyResult::where('distributor_id', $dist)->value('status'))->toBe(GbbMonthlyResult::STATUS_CREDITED);
});

it('pays a distributor whose failed cycle was fulfilled late but before the month end', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = Distributor::factory()->create()->id;
    gbbSeedCompanyBv(200_000, '2026-07-10');
    gbbSeedCutoff($dist, '2026-07-10', 1);
    // Failed 21–24 Jul, fulfilled on 25 Jul: the forfeited window closed
    // before the 31st.
    gbbSeedFailedCycle($dist, dueDate: '2026-07-20', fulfilledOn: '2026-07-25');

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));

    expect($result['credited'])->toBe(1)
        ->and($result['repurchase_failed'])->toBe(0)
        ->and(GbbMonthlyResult::where('distributor_id', $dist)->value('gbb_gross_paise'))->toBe(7_200);
});

it('prices the point value on the payable AGP only — failed AGP never dilutes it', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $payable = Distributor::factory()->create()->id;
    $failed = Distributor::factory()->create()->id;
    gbbSeedCompanyBv(200_000, '2026-07-10');      // pool = 8,000 paise
    gbbSeedCutoff($payable, '2026-07-10', 1);     // 12 AGP
    gbbSeedCutoff($failed, '2026-07-11', 1);      // 12 AGP, failed at month end
    gbbSeedFailedCycle($failed, dueDate: '2026-07-20');

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));

    expect($result['total_agp'])->toBe(12)
        ->and($result['point_value_paise'])->toBe(600)
        ->and($result['repurchase_failed'])->toBe(1)
        ->and(GbbMonthlyResult::where('distributor_id', $payable)->value('gbb_gross_paise'))->toBe(7_200)
        ->and(GbbMonthlyResult::where('distributor_id', $failed)->value('gbb_gross_paise'))->toBe(0)
        ->and(GbbMonthlyResult::where('distributor_id', $failed)->value('agp_earned'))->toBe(12);
});

it('records a distributor failing both gates as repurchase-failed — the verdict gate runs first', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = Distributor::factory()->create()->id;
    gbbSeedCompanyBv(200_000, '2026-07-10');
    gbbSeedCutoff($dist, '2026-07-10', 1);
    gbbSeedFailedCycle($dist, dueDate: '2026-07-20');
    gbbSeedRepurchaseWalletCredit($dist, 50_000, '2026-07-05 09:00:00');

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));

    expect($result['repurchase_failed'])->toBe(1)
        ->and($result['wallet_blocked'])->toBe(0)
        ->and(GbbMonthlyResult::where('distributor_id', $dist)->value('status'))->toBe(GbbMonthlyResult::STATUS_REPURCHASE_FAILED_BLOCKED);
});

it('never re-judges the verdict on a re-run — a back-dated fulfilment does not reopen the month', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = Distributor::factory()->create()->id;
    gbbSeedCompanyBv(200_000, '2026-07-10');
    gbbSeedCutoff($dist, '2026-07-10', 1);
    $cycle = gbbSeedFailedCycle($dist, dueDate: '2026-07-20');

    $first = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));
    expect($first['repurchase_failed'])->toBe(1);

    // Corrected after the freeze: the cycle was fulfilled on 25 Jul, so asked
    // live the verdict on 31 Jul would now be eligible.
    $cycle->update(['fulfilled_on' => '2026-07-25']);

    $second = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));

    expect($second['credited'])->toBe(0)
        ->and($second['total_agp'])->toBe(0)
        ->and($second['repurchase_failed'])->toBe(1)
        ->and(GbbMonthlyResult::where('distributor_id', $dist)->value('status'))->toBe(GbbMonthlyResult::STATUS_REPURCHASE_FAILED_BLOCKED)
        ->and(WalletLedgerEntry::where('distributor_id', $dist)->where('type', 'gbb_credit')->count())->toBe(0)
        // The refusal to pay a now-clean distributor is recorded, never silent.
        ->and(DB::table('audit_log')->where('action', 'gbb.result.excluded_from_frozen_denominator')->count())->toBe(1);
});

it('does not block on a failed cycle while the repurchase engine is off (fail open)', function (): void {
    Feature::for(null)->deactivate(RepurchaseEngineFeature::class);
    $dist = Distributor::factory()->create()->id;
    gbbSeedCompanyBv(200_000, '2026-07-10');
    gbbSeedCutoff($dist, '2026-07-10', 1);
    gbbSeedFailedCycle($dist, dueDate: '2026-07-20');

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));

    expect($result['credited'])->toBe(1)
        ->and($result['repurchase_failed'])->toBe(0)
        ->and(GbbMonthlyResult::where('distributor_id', $dist)->value('status'))->toBe(GbbMonthlyResult::STATUS_CREDITED);
});
