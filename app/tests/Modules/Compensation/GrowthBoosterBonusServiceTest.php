<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\GbbMonthlyPool;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\GrowthBoosterBonusService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
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
    expect($pool->pool_paise)->toBe(50_000);       // 5% of 10,00,000 paise
    expect($pool->total_agp)->toBe(0);
    expect($pool->point_value_paise)->toBe(0);
    expect($pool->payout_paise)->toBe(0);
    expect($pool->leftover_paise)->toBe(50_000);   // the whole pool goes unspent
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
    gbbSeedCompanyBv(10_000_000);          // ₹1,00,000 BV → ₹5,000 pool
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

it('caps AGP at 120 per distributor even with many slab 1 occurrences', function () {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(10_000_000);

    // 11 × slab 1 = 132 AGP raw → should be capped at 120.
    for ($i = 1; $i <= 11; $i++) {
        gbbSeedCutoff($dist->id, '2026-06-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 1);
    }

    app(GrowthBoosterBonusService::class)->runForMonth($month);

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();
    expect($row->agp_earned)->toBe(120);
});

it('distributes pool proportionally between two distributors', function () {
    $d1 = Distributor::factory()->create();
    $d2 = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    // Pool: 5% of 2,00,000 paise BV = 10,000 paise.
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($d1->id, '2026-06-05', 1);  // 12 AGP
    gbbSeedCutoff($d2->id, '2026-06-06', 2);  //  5 AGP

    $result = app(GrowthBoosterBonusService::class)->runForMonth($month);

    // Total AGP = 17. 10,000 ÷ 17 = 588.2 paise → floored to ₹5 (500 paise).
    $row1 = GbbMonthlyResult::where('distributor_id', $d1->id)->first();
    $row2 = GbbMonthlyResult::where('distributor_id', $d2->id)->first();

    expect($row1->gbb_gross_paise)->toBe(500 * 12);  // 6000
    expect($row2->gbb_gross_paise)->toBe(500 * 5);   // 2500
    expect($result['total_agp'])->toBe(17);
    expect($result['point_value_paise'])->toBe(500);
    expect($result['credited'])->toBe(2);
});

it('sets the pool to 5% of monthly company BV, floors the point value to whole rupees and keeps the residual as leftover', function () {
    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(150_000, '2026-06-03');
    gbbSeedCompanyBv(50_000, '2026-06-20');
    // A June-30 entry is inside the month; a July-1 entry must not count.
    gbbSeedCompanyBv(100_000, '2026-07-01');

    gbbSeedCutoff($dist->id, '2026-06-05', 1);  // 12 AGP

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    $pool = GbbMonthlyPool::first();

    expect($pool->company_bv_paise)->toBe(200_000);
    expect($pool->pool_rate_bp)->toBe(500);
    expect($pool->pool_paise)->toBe(10_000);            // 5% of 2,00,000
    expect($pool->total_agp)->toBe(12);
    expect($pool->point_value_paise)->toBe(800);        // 833.3 floored to ₹8
    expect($pool->payout_paise)->toBe(9_600);
    expect($pool->leftover_paise)->toBe(400);           // flooring residual
    expect($result['point_value_paise'])->toBe(800);

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();
    expect($row->point_value_paise)->toBe(800);
    expect($row->gbb_gross_paise)->toBe(9_600);
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
    expect($pool->pool_paise)->toBe(10_000);
    expect($pool->total_agp)->toBe(12);
    expect($pool->point_value_paise)->toBe(800);
    expect($result['point_value_paise'])->toBe(800);

    // The already-paid distributor is untouched; the newcomer has no roster row
    // and is refused — paying them would spend a pool already fully divided.
    expect(GbbMonthlyResult::where('distributor_id', $d1->id)->first()->gbb_gross_paise)->toBe($firstGross);
    expect(GbbMonthlyResult::where('distributor_id', $d2->id)->exists())->toBeFalse();
    expect($result['qualified_after_freeze'])->toBe(1);
});

it('refuses a distributor whose AGP lands after the freeze and never overspends the frozen pool', function () {
    $d1 = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    gbbSeedCompanyBv(200_000, '2026-06-03');   // pool = 10,000 paise
    gbbSeedCutoff($d1->id, '2026-06-05', 1);   // 12 AGP → point value ₹8

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
        ->and($details['refused_gross_paise'])->toBe(9_600);
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

it('excludes a distributor who held a qualified rank in the previous month', function () {
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

it('excludes a prior-month carry-forward rank too — a paid carry row still means ranked', function () {
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
    gbbSeedRank($dist->id, '2026-06-01');  // this month only — no prior-month row

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($result['credited'])->toBe(1);
    expect(GbbMonthlyResult::where('distributor_id', $dist->id)->first()->status)
        ->toBe(GbbMonthlyResult::STATUS_CREDITED);
});

it('makes a distributor ranked in M-2 but not M-1 eligible again', function () {
    // Documents the literal reading of the spec: only the IMMEDIATELY previous
    // month is checked, so a lapsed ranker re-enters the Growth Booster.
    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);
    gbbSeedCutoff($dist->id, '2026-06-05', 1);
    gbbSeedRank($dist->id, '2026-04-01');  // M-2, nothing in May

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($result['credited'])->toBe(1);
    expect($result['total_agp'])->toBe(12);
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
    expect($pool->point_value_paise)->toBe(800);
    expect($result['credited'])->toBe(1);
    expect(GbbMonthlyResult::where('distributor_id', $dist->id)->first()->gbb_gross_paise)->toBe(9_600);

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

it('pays GBB on AGP from compliant days even when the cycle is failed at month end', function () {
    // Client spec 2026-09-07 §2.3 (A2): the repurchase CYCLE never holds GBB. A
    // failed day simply produced no GSB match, so no AGP came from it; the AGP
    // that did survive is paid in full.
    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000);                // pool = 10,000 paise
    gbbSeedCutoff($dist->id, '2026-06-05', 1);  // 12 AGP earned on a compliant day
    gbbSeedCycle($dist->id, RepurchaseCycle::STATUS_SUSPENDED);  // still failed at month end

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    expect($result['total_agp'])->toBe(12);
    expect($result['credited'])->toBe(1);
    expect($result['wallet_blocked'])->toBe(0);

    $row = GbbMonthlyResult::where('distributor_id', $dist->id)->first();
    expect($row->status)->toBe(GbbMonthlyResult::STATUS_CREDITED);
    expect((int) WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'gbb_credit')->sum('amount_paise'))
        ->toBe(9_600);
});

it('keeps wallet-blocked AGP outside the denominator so it never dilutes the payable', function () {
    $payable = Distributor::factory()->create();
    $blocked = Distributor::factory()->create();
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    gbbSeedCompanyBv(200_000);                     // pool = 10,000 paise
    gbbSeedCutoff($payable->id, '2026-06-05', 1);  // 12 AGP, payable
    gbbSeedCutoff($blocked->id, '2026-06-06', 2);  //  5 AGP, wallet not cleared
    gbbSeedRepurchaseWalletCredit($blocked->id, 50_000, '2026-06-20 09:00:00');

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-06-01'));

    // 12, not 17 — blocked AGP can never be paid, so it must not price the pool.
    expect($result['total_agp'])->toBe(12);
    expect($result['point_value_paise'])->toBe(800);
    expect($result['wallet_blocked'])->toBe(1);

    expect(GbbMonthlyResult::where('distributor_id', $payable->id)->first()->gbb_gross_paise)->toBe(9_600);
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
