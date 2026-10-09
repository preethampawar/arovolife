<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\GbbMonthlyPool;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compensation\Models\MsbDailyPool;
use App\Modules\Compensation\Models\RankAogoGrant;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Models\RankMonthlyPass;
use App\Modules\Compensation\Models\RankMonthlyPool;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\GrowthBoosterBonusService;
use App\Modules\Compensation\Services\MentorshipBonusService;
use App\Modules\Compensation\Services\MsbDailyPoolService;
use App\Modules\Compensation\Services\RankBonusService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/*
 * Cross-engine invariants of the 2026-10-09 R.S.P. plan (Task 12, step 2a):
 * the identities every frozen period must satisfy, read back from the rows the
 * engines wrote. Each test names the fail-safe finding it pins.
 *
 * Helpers are local copies with an `inv` prefix — Pest helpers are global
 * functions, so the ones in the engine test files cannot be shared or redefined.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    // Every month and day below is closed, so no freeze is premature.
    Carbon::setTestNow('2026-10-02 04:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// ── Shared fixtures ─────────────────────────────────────────────────────────

/** Company-wide BV on a date, booked against a sentinel distributor (no personal-BV collisions). */
function invSeedCompanyBv(int $bvPaise, string $date): void
{
    static $fakeOrderId = 940000;

    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => 999001,
        'order_id' => $fakeOrderId++,
        'bv_paise' => $bvPaise,
        'type' => $bvPaise < 0 ? 'reversal' : 'accrual',
        'effective_at' => $date.' 12:00:00',
        'created_at' => $date.' 12:00:00',
        'updated_at' => $date.' 12:00:00',
    ]);
}

function invSeedRankQualification(int $distributorId, int $rank, string $monthStart): void
{
    RankQualification::create([
        'distributor_id' => $distributorId,
        'rank_number' => $rank,
        'month_start' => $monthStart,
        'occurrence_in_month' => 1,
        'is_carry_forward' => false,
        'status' => RankQualification::STATUS_QUALIFIED,
    ]);
}

/** A BV-short cycle due on $due, resolved failed at 00:05 the next day: forfeits every day from $due + 1. */
function invSeedFailedCycle(int $distributorId, string $due): RepurchaseCycle
{
    $dueDate = Carbon::parse($due);

    return RepurchaseCycle::create([
        'distributor_id' => $distributorId,
        'cycle_start_date' => $dueDate->copy()->subDays(29)->toDateString(),
        'due_date' => $dueDate->toDateString(),
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => 0,
        'wallet_balance_paise' => 0,
        'wallet_zeroed' => true,
        'status' => RepurchaseCycle::STATUS_SUSPENDED,
        'failure_reason' => RepurchaseCycle::REASON_BV_SHORT,
        'resolved_at' => $dueDate->copy()->addDay()->setTime(0, 5)->toDateTimeString(),
    ]);
}

// ── Rank Bonus fixtures (F-9, F-10) ─────────────────────────────────────────

/**
 * Qualify $n fresh distributors at $rank for the month.
 *
 * @return list<int>
 */
function invSeedRankCohort(int $n, int $rank, string $monthStart): array
{
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $id = Distributor::factory()->create()->id;
        invSeedRankQualification($id, $rank, $monthStart);
        $ids[] = $id;
    }

    return $ids;
}

/**
 * One live AO-GO grant (36 points) per distributor for the month — written
 * directly, as the engine reuses a month's live grants.
 *
 * @param  array<int>  $distributorIds
 */
function invSeedAogoGrants(array $distributorIds, string $monthStart): void
{
    foreach ($distributorIds as $id) {
        RankAogoGrant::create([
            'distributor_id' => $id,
            'month_start' => $monthStart,
            'grant_number' => 1,
            'points' => 36,
            'previous_rank_number' => 1,
            'status' => RankAogoGrant::STATUS_GRANTED,
        ]);
    }
}

/** $n fresh distributors with one AO-GO grant each. */
function invSeedAogoCohort(int $n, string $monthStart): void
{
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $ids[] = Distributor::factory()->create()->id;
    }

    invSeedAogoGrants($ids, $monthStart);
}

/**
 * F-9: every identity a frozen, credited Rank Bonus month must satisfy, read
 * back from rank_monthly_passes, rank_monthly_pools, rank_bonus_results and
 * the wallet ledger.
 */
function invAssertRankPassIdentities(string $monthStart, int $seededTurnoverPaise): void
{
    $passes = RankMonthlyPass::where('month_start', $monthStart)->get()->keyBy('pass');
    $pools = RankMonthlyPool::where('month_start', $monthStart)->get()->keyBy('rank_number');

    expect($passes->keys()->sort()->values()->all())->toBe([1, 2])
        ->and($pools)->toHaveCount(9);

    $pass1 = $passes->get(1);
    $pass2 = $passes->get(2);
    expect($pass1)->toBeInstanceOf(RankMonthlyPass::class)
        ->and($pass2)->toBeInstanceOf(RankMonthlyPass::class);
    assert($pass1 instanceof RankMonthlyPass && $pass2 instanceof RankMonthlyPass);

    $turnover = (int) $pass1->company_turnover_paise;
    $envelope = (int) $pass1->envelope_paise;

    // The frozen turnover is the month's signed company BV, and the envelope is
    // integer arithmetic on it — no float, never negative.
    expect($turnover)->toBe($seededTurnoverPaise)
        ->and($envelope)->toBe(max(0, intdiv($turnover * (int) $pass1->envelope_bp, 10_000)))
        ->and((int) $pass2->company_turnover_paise)->toBe($turnover)
        ->and((int) $pass2->envelope_bp)->toBe((int) $pass1->envelope_bp)
        ->and((int) $pass2->envelope_paise)->toBe($envelope);

    // One envelope, two passes: pass 1 divides all of it, pass 2 what pass 1 left.
    expect((int) $pass1->pool_paise)->toBe($envelope)
        ->and((int) $pass2->pool_paise)->toBe($envelope - (int) $pass1->payout_paise)
        ->and((int) $pools->sum('pool_paise') + (int) $pass2->leftover_paise)->toBe($envelope)
        ->and((int) $pools->sum('payout_paise'))->toBe((int) $pass1->payout_paise + (int) $pass2->payout_paise)
        ->and((int) $pass1->payout_paise + (int) $pass2->payout_paise)->toBeLessThanOrEqual($envelope);

    foreach ([$pass1, $pass2] as $pass) {
        $passPools = $pools->where('pass', (int) $pass->pass);

        expect((int) $pass->point_value_paise)->toBeGreaterThanOrEqual(0)
            ->and((int) $pass->raw_point_value_paise)->toBeGreaterThanOrEqual(0)
            ->and((int) $pass->payout_paise)->toBeGreaterThanOrEqual(0)
            ->and((int) $pass->leftover_paise)->toBeGreaterThanOrEqual(0)
            ->and((int) $pass->point_value_paise)->toBeLessThanOrEqual((int) $pass->point_value_cap_paise)
            ->and((int) $pass->point_value_paise)->toBeLessThanOrEqual((int) $pass->raw_point_value_paise)
            ->and((int) $pass->point_value_paise % 100)->toBe(0)
            ->and((int) $pass->payout_paise)->toBeLessThanOrEqual((int) $pass->pool_paise)
            ->and((int) $pass->payout_paise)->toBe((int) $pass->total_points * (int) $pass->point_value_paise)
            ->and((int) $pass->leftover_paise)->toBe((int) $pass->pool_paise - (int) $pass->payout_paise)
            ->and((int) $passPools->sum('total_points'))->toBe((int) $pass->total_points)
            ->and((int) $passPools->sum('payout_paise'))->toBe((int) $pass->payout_paise);

        foreach ($passPools as $pool) {
            expect((int) $pool->point_value_paise)->toBe((int) $pass->point_value_paise)
                ->and((int) $pool->pool_paise)->toBeGreaterThanOrEqual(0)
                ->and((int) $pool->payout_paise)->toBeLessThanOrEqual((int) $pass->pool_paise);
        }
    }

    $rows = RankBonusResult::where('month_start', $monthStart)
        ->whereIn('status', [RankBonusResult::STATUS_PENDING, RankBonusResult::STATUS_CREDITED])
        ->get();

    foreach ($rows as $row) {
        $pool = $pools->get((int) $row->rank_number);
        assert($pool instanceof RankMonthlyPool);
        $pass = $pool->pass === 1 ? $pass1 : $pass2;
        $points = (int) ($row->aogo_points ?? $row->rap_points);

        expect((int) $row->gross_paise)->toBe($points * (int) $pass->point_value_paise)
            ->and((int) $row->point_value_paise)->toBe((int) $pass->point_value_paise)
            ->and((int) $row->gross_paise)->toBeGreaterThanOrEqual(0);

        // A positive gross is always credited; a zero gross never reaches the wallet.
        expect($row->status)->toBe((int) $row->gross_paise > 0 ? RankBonusResult::STATUS_CREDITED : RankBonusResult::STATUS_PENDING);
    }

    // Σ roster gross is exactly what the two passes paid out.
    expect((int) $rows->sum('gross_paise'))->toBe((int) $pass1->payout_paise + (int) $pass2->payout_paise);

    $creditedIds = $rows->where('status', RankBonusResult::STATUS_CREDITED)->pluck('id')->all();
    $pendingIds = $rows->where('status', RankBonusResult::STATUS_PENDING)->pluck('id')->all();

    expect(WalletLedgerEntry::where('type', 'rank_credit')->whereIn('reference_id', $creditedIds)->count())->toBe(count($creditedIds))
        ->and(WalletLedgerEntry::where('type', 'rank_credit')->whereIn('reference_id', $pendingIds)->exists())->toBeFalse()
        ->and(WalletLedgerEntry::where('type', 'rank_credit')->where('amount_paise', '<=', 0)->exists())->toBeFalse()
        // The ledger carries exactly the gross the passes paid — not net, not doubled, not over the pool.
        ->and((int) WalletLedgerEntry::where('type', 'rank_credit')->whereIn('reference_id', $creditedIds)->sum('amount_paise'))
        ->toBe((int) $rows->where('status', RankBonusResult::STATUS_CREDITED)->sum('gross_paise'));
}

// ── F-5: Mentorship Royalty daily cap ───────────────────────────────────────

/**
 * A failed rank-6 sponsor with two slab-1 sponsees on a ₹120-point day: each
 * accrual is worth 21 × ₹120 = ₹2,520, together ₹5,040 — over the ₹3,600 cap.
 *
 * @return array{0: Distributor, 1: Distributor, 2: Distributor, 3: MsbDailyPool} [sponsor, a, b, pool]
 */
function invFailedRoyaltySponsor(): array
{
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $sponsor = Distributor::factory()->create();
    $a = invSponseeFor($sponsor);
    $b = invSponseeFor($sponsor);
    invGiveSponsorMinBv($sponsor);
    invSeedFailedCycle($sponsor->id, '2026-08-06');                     // forfeited from 7 Aug
    invSeedRankQualification($sponsor->id, 6, '2026-07-01');            // royalty rank before August

    $pool = MsbDailyPool::create([
        'cutoff_date' => '2026-08-10',
        'company_bv_paise' => intdiv(12_000 * 42 * 10_000, 300),
        'pool_rate_bp' => 300,
        'pool_paise' => 12_000 * 42,
        'total_points' => 42,
        'point_value_paise' => 12_000,
        'payout_paise' => 12_000 * 42,
        'leftover_paise' => 0,
    ]);

    return [$sponsor, $a, $b, $pool];
}

function invSponseeFor(Distributor $sponsor): Distributor
{
    $sponsee = Distributor::factory()->create();
    DB::table('sponsorship')->insert([
        'sponsor_id' => $sponsor->id,
        'distributor_id' => $sponsee->id,
        'created_at' => now(),
    ]);

    return $sponsee;
}

/** The 600 BV personal minimum every bonus needs, dated today (never on a priced day). */
function invGiveSponsorMinBv(Distributor $sponsor): void
{
    BvLedgerEntry::create([
        'distributor_id' => $sponsor->id,
        'order_id' => 710_000 + $sponsor->id,
        'bv_paise' => 60_000,
        'type' => 'accrual',
        'effective_at' => now(),
    ]);
}

function invCreditedCutoff(Distributor $sponsee, int $slab, string $date): GsbCutoffResult
{
    return GsbCutoffResult::create([
        'distributor_id' => $sponsee->id,
        'cutoff_date' => $date,
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'slab' => $slab, 'gross_gsb_paise' => 100_000,
        'admin_charge_paise' => 0, 'tds_paise' => 0, 'net_gsb_paise' => 100_000,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => GsbCutoffResult::STATUS_CREDITED,
    ]);
}

it('F-5: settles a failed royalty sponsor\'s day to the ₹3,600 cap whichever sponsee is credited first', function (string $order): void {
    [$sponsor, $a, $b, $pool] = invFailedRoyaltySponsor();
    $svc = app(MentorshipBonusService::class);

    foreach ($order === 'A→B' ? [$a, $b] : [$b, $a] as $sponsee) {
        $accrual = $svc->accrueForSponsee($sponsee->id, invCreditedCutoff($sponsee, 1, '2026-08-10'));
        expect($accrual)->not->toBeNull();
        assert($accrual !== null);
        $svc->creditAccrual($accrual, $pool);
    }

    $rows = MentorshipBonusResult::where('sponsor_id', $sponsor->id)
        ->whereDate('cutoff_date', '2026-08-10')
        ->get();
    $cap = 360_000;

    expect($rows)->toHaveCount(2)
        // The day's total is the cap — never more, the same in both orders.
        ->and((int) $rows->sum('mb_gross_paise'))->toBe($cap)
        // Nothing vanished: paid + withheld is exactly what the points were worth.
        ->and((int) $rows->sum('mb_gross_paise') + (int) $rows->sum('royalty_cap_withheld_paise'))
        ->toBe((int) $rows->sum(fn (MentorshipBonusResult $r): int => $r->msb_points * (int) $r->msb_point_value_paise))
        ->and((int) $rows->sum('royalty_cap_withheld_paise'))->toBe(2 * 21 * 12_000 - $cap);

    foreach ($rows as $row) {
        expect($row->status)->toBe(MentorshipBonusResult::STATUS_CREDITED)
            ->and($row->sponsor_repurchase_failed)->toBeTrue()
            ->and($row->royalty_cap_paise)->toBe($cap)
            ->and($row->mb_gross_paise)->toBeGreaterThanOrEqual(0)
            ->and($row->royalty_cap_withheld_paise)->toBeGreaterThanOrEqual(0)
            ->and($row->mb_gross_paise + $row->royalty_cap_withheld_paise)->toBe($row->msb_points * (int) $row->msb_point_value_paise);
    }

    // The wallet received the cap and no more; the withholding is audited.
    expect((int) WalletLedgerEntry::where('distributor_id', $sponsor->id)->where('type', 'mb_credit')->sum('amount_paise'))->toBe($cap);

    $withheldAudited = DB::table('audit_log')->where('action', 'msb.royalty.cap_withheld')->get()
        ->sum(fn (object $audit): int => (int) json_decode((string) $audit->details, true)['withheld_paise']);
    expect($withheldAudited)->toBe((int) $rows->sum('royalty_cap_withheld_paise'));
})->with(['A→B', 'B→A']);

// ── F-9: Rank Bonus two-pass identities ─────────────────────────────────────

it('F-9: example A2 — AGO + Rank 1 share the whole envelope and every pass identity holds', function (): void {
    $m = '2026-09-01';
    invSeedCompanyBv(95_000_000, '2026-09-10');      // 9,50,000 BV → envelope ₹1,90,000
    invSeedRankCohort(9, 1, $m);
    invSeedAogoCohort(10, $m);

    $out = app(RankBonusService::class)->runForMonth(Carbon::parse($m));

    expect($out['passes'][1]['total_points'])->toBe(1_008)
        ->and($out['passes'][1]['point_value_paise'])->toBe(18_800)
        ->and($out['passes'][2]['leftover_paise'])->toBe(49_600);

    invAssertRankPassIdentities($m, 95_000_000);
});

it('F-9: example C1 — pass 1 capped at ₹200 and every pass identity holds', function (): void {
    $m = '2026-09-01';
    invSeedCompanyBv(630_000_000, '2026-09-10');     // 63L BV → envelope ₹12,60,000
    invSeedRankCohort(9, 1, $m);
    invSeedRankCohort(8, 2, $m);
    invSeedRankCohort(7, 3, $m);
    invSeedAogoCohort(10, $m);

    $out = app(RankBonusService::class)->runForMonth(Carbon::parse($m));

    expect($out['passes'][1]['raw_point_value_paise'])->toBe(21_700)
        ->and($out['passes'][1]['point_value_paise'])->toBe(20_000)
        ->and($out['passes'][2]['leftover_paise'])->toBe(10_080_000);

    invAssertRankPassIdentities($m, 630_000_000);
});

it('F-9: example D2 — ranks 4–9 share the remainder and every pass identity holds', function (): void {
    $m = '2026-09-01';
    invSeedCompanyBv(16_000_000_000, '2026-09-10');  // 16 Cr BV → envelope ₹3.2 Cr
    foreach ([1 => 9, 2 => 8, 3 => 7, 4 => 6, 5 => 5, 6 => 4, 7 => 3, 8 => 2, 9 => 1] as $rank => $n) {
        invSeedRankCohort($n, $rank, $m);
    }
    invSeedAogoCohort(10, $m);

    $out = app(RankBonusService::class)->runForMonth(Carbon::parse($m));

    expect($out['passes'][1]['payout_paise'])->toBe(115_920_000)
        ->and($out['passes'][2]['total_points'])->toBe(165_474)
        ->and($out['passes'][2]['point_value_paise'])->toBe(18_600)
        ->and($out['passes'][2]['leftover_paise'])->toBe(6_263_600);

    invAssertRankPassIdentities($m, 16_000_000_000);
});

/*
 * Property draw: 50 seeded draws of a random cohort (0–12 achievers at each of
 * Ranks 1–9, 0–3 AO-GO grants) and a random month turnover between −1 Cr and
 * 20 Cr BV. Distributors are created once and reused; each draw clears the
 * month's rank tables first so the same month freezes afresh, every achiever is
 * a first-time achiever (no §8 requalification hold) and no AO-GO grant is
 * minted from history.
 */
it('F-9: holds every pass identity over 50 random cohorts and turnovers (property draw, seed 20261009)', function (): void {
    $m = '2026-09-01';
    $rankPool = [];
    foreach (range(1, 9) as $rank) {
        $rankPool[$rank] = Distributor::factory()->count(12)->create()->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }
    $aogoPool = Distributor::factory()->count(3)->create()->pluck('id')->map(fn ($id): int => (int) $id)->all();

    mt_srand(20261009);
    $seen = ['negative_turnover' => 0, 'capped_pass' => 0, 'uncapped_pass' => 0, 'pass2_paid' => 0];

    for ($draw = 0; $draw < 50; $draw++) {
        foreach (['rank_bonus_results', 'rank_monthly_pools', 'rank_monthly_passes', 'rank_aogo_grants', 'rank_qualifications'] as $table) {
            DB::table($table)->where('month_start', $m)->delete();
        }
        DB::table('bv_ledger_entries')->where('distributor_id', 999001)->delete();

        foreach (range(1, 9) as $rank) {
            foreach (array_slice($rankPool[$rank], 0, mt_rand(0, 12)) as $id) {
                invSeedRankQualification($id, $rank, $m);
            }
        }
        invSeedAogoGrants(array_slice($aogoPool, 0, mt_rand(0, 3)), $m);

        // −1 Cr … 20 Cr BV in paise (1 Cr BV = 10⁹ paise); mt_rand is 32-bit, so in two parts.
        $turnover = mt_rand(-1_000, 19_999) * 1_000_000 + mt_rand(0, 999_999);
        invSeedCompanyBv($turnover, '2026-09-15');

        app(RankBonusService::class)->runForMonth(Carbon::parse($m));

        invAssertRankPassIdentities($m, $turnover);

        $seen['negative_turnover'] += $turnover < 0 ? 1 : 0;
        foreach (RankMonthlyPass::where('month_start', $m)->get() as $pass) {
            if ((int) $pass->total_points > 0 && (int) $pass->raw_point_value_paise > (int) $pass->point_value_cap_paise) {
                $seen['capped_pass']++;
            } elseif ((int) $pass->total_points > 0 && (int) $pass->point_value_paise > 0) {
                $seen['uncapped_pass']++;
            }
            $seen['pass2_paid'] += (int) $pass->pass === 2 && (int) $pass->payout_paise > 0 ? 1 : 0;
        }
    }

    // The draw must actually exercise the edges it exists for.
    expect($seen['negative_turnover'])->toBeGreaterThan(0)
        ->and($seen['capped_pass'])->toBeGreaterThan(0)
        ->and($seen['uncapped_pass'])->toBeGreaterThan(0)
        ->and($seen['pass2_paid'])->toBeGreaterThan(0);
});

// ── F-10: the pass rows are part of the frozen month ────────────────────────

it('writes pass rows and pool rows atomically — a freeze that throws leaves no pass rows (F-10)', function (): void {
    $m = '2026-09-01';
    invSeedCompanyBv(100_000_000, '2026-09-10');
    invSeedRankCohort(1, 1, $m);

    RankMonthlyPool::creating(function (RankMonthlyPool $pool): void {
        if ((int) $pool->rank_number === 9) {
            throw new RuntimeException('forced failure on the 9th pool insert');
        }
    });

    expect(fn () => app(RankBonusService::class)->runForMonth(Carbon::parse($m)))
        ->toThrow(RuntimeException::class, 'forced failure on the 9th pool insert');

    expect(RankMonthlyPass::count())->toBe(0)
        ->and(RankMonthlyPool::count())->toBe(0)
        ->and(RankBonusResult::count())->toBe(0);
});

// ── Task 7: GBB denominator = Σ payable AGP ─────────────────────────────────

function invGbbCutoff(int $distributorId, string $date, int $slab): void
{
    GsbCutoffResult::create([
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

/** 10 slab-1 days (12 AGP each) + 1 slab-2 day (5 AGP) in July = 125 AGP. */
function invGbbEarner(): int
{
    $id = Distributor::factory()->create()->id;
    for ($day = 1; $day <= 10; $day++) {
        invGbbCutoff($id, sprintf('2026-07-%02d', $day), 1);
    }
    invGbbCutoff($id, '2026-07-20', 2);

    return $id;
}

it('Task 7: freezes gbb_monthly_pools.total_agp as the payable AGP only, and pays Σ gross = total_agp × point value ≤ pool', function (int $companyBvPaise, int $expectedPointValuePaise): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    invSeedCompanyBv($companyBvPaise, '2026-07-15');

    $payable = [invGbbEarner(), invGbbEarner(), invGbbEarner()];
    $walletBlocked = invGbbEarner();
    $repurchaseFailed = invGbbEarner();

    // Unspent repurchase wallet at month end → wallet-blocked.
    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => $walletBlocked,
        'type' => 'repurchase_deduction',
        'amount_paise' => 50_000,
        'reference_id' => null,
        'reference_type' => null,
        'memo' => 'test',
        'created_at' => '2026-07-20 09:00:00',
    ]);
    // Failed from 21 Jul, still failed on 31 Jul → repurchase-failed-blocked (A-G1).
    invSeedFailedCycle($repurchaseFailed, '2026-07-20');

    app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));

    $pool = GbbMonthlyPool::where('month_start', '2026-07-01')->sole();
    $rows = GbbMonthlyResult::whereDate('year_month', '2026-07-01')->get()->keyBy('distributor_id');
    $credited = $rows->where('status', GbbMonthlyResult::STATUS_CREDITED);

    // The roster is the mix the test set out to build.
    expect($rows)->toHaveCount(5)
        ->and($credited->keys()->sort()->values()->all())->toBe(collect($payable)->sort()->values()->all())
        ->and($rows->get($walletBlocked)?->status)->toBe(GbbMonthlyResult::STATUS_REPURCHASE_WALLET_BLOCKED)
        ->and($rows->get($repurchaseFailed)?->status)->toBe(GbbMonthlyResult::STATUS_REPURCHASE_FAILED_BLOCKED);

    // The denominator is the payable AGP — blocked AGP never dilutes it.
    expect((int) $pool->total_agp)->toBe((int) $credited->sum('agp_earned'))
        ->and((int) $pool->total_agp)->toBeLessThan((int) $rows->sum('agp_earned'));

    // Pool, value and payout: integer, capped, floored to the rupee, never over.
    expect((int) $pool->company_bv_paise)->toBe($companyBvPaise)
        ->and((int) $pool->pool_paise)->toBe(max(0, intdiv($companyBvPaise * (int) $pool->pool_rate_bp, 10_000)))
        // min(₹240, ⌊pool ÷ 375 AGP⌋) — a cap clamped to 0 or a value priced on the whole roster fails here.
        ->and((int) $pool->point_value_paise)->toBe($expectedPointValuePaise)
        ->and((int) $pool->point_value_paise)->toBeLessThanOrEqual((int) $pool->point_value_cap_paise)
        ->and((int) $pool->point_value_paise)->toBeLessThanOrEqual((int) $pool->raw_point_value_paise)
        ->and((int) $pool->point_value_paise % 100)->toBe(0)
        ->and((int) $pool->payout_paise)->toBe((int) $pool->total_agp * (int) $pool->point_value_paise)
        ->and((int) $credited->sum('gbb_gross_paise'))->toBe((int) $pool->payout_paise)
        ->and((int) $pool->payout_paise)->toBeLessThanOrEqual((int) $pool->pool_paise)
        ->and((int) $pool->leftover_paise)->toBe((int) $pool->pool_paise - (int) $pool->payout_paise);

    foreach ($rows as $row) {
        expect($row->gbb_gross_paise)->toBeGreaterThanOrEqual(0);

        if ($row->status === GbbMonthlyResult::STATUS_CREDITED) {
            expect($row->gbb_gross_paise)->toBe($row->agp_earned * (int) $pool->point_value_paise);
        } else {
            expect($row->gbb_gross_paise)->toBe(0);
        }
    }

    foreach ([(int) $pool->pool_paise, (int) $pool->point_value_paise, (int) $pool->raw_point_value_paise, (int) $pool->leftover_paise] as $figure) {
        expect($figure)->toBeGreaterThanOrEqual(0);
    }

    // Only the payable rows reached the wallet, and the ledger carries exactly the pool's payout.
    expect(WalletLedgerEntry::where('type', 'gbb_credit')->distinct()->pluck('distributor_id')->map(fn ($id): int => (int) $id)->sort()->values()->all())
        ->toBe(collect($payable)->sort()->values()->all())
        ->and((int) WalletLedgerEntry::where('type', 'gbb_credit')->sum('amount_paise'))->toBe((int) $pool->payout_paise);
})->with([
    'raw value over the ₹240 cap' => [500_000_000, 24_000],   // 50L BV → ₹2,00,000 pool ÷ 375 AGP = ₹533 raw → ₹240
    'raw value under the cap' => [2_000_000, 200],            // 20,000 BV → ₹800 pool ÷ 375 AGP = ₹2.13 → ₹2
]);

// ── Task 5: MSB daily freeze identity ───────────────────────────────────────

it('Task 5: a frozen MSB day pays Σ points × point value less the royalty withholding, within the pool and the cap', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $date = '2026-08-10';
    invSeedCompanyBv(500_000_000, $date);   // 50L BV → 3% = ₹1,50,000 pool

    // Eligible sponsor with a slab-2 sponsee.
    $eligible = Distributor::factory()->create();
    $eligibleSponsee = invSponseeFor($eligible);
    invGiveSponsorMinBv($eligible);

    // Failed rank-6 sponsor (royalty) with two slab-1 sponsees: capped at ₹3,600.
    $royalty = Distributor::factory()->create();
    $royaltySponsees = [invSponseeFor($royalty), invSponseeFor($royalty)];
    invGiveSponsorMinBv($royalty);
    invSeedFailedCycle($royalty->id, '2026-08-06');
    invSeedRankQualification($royalty->id, 6, '2026-07-01');

    // Failed never-ranked sponsor: gated, out of the denominator.
    $gated = Distributor::factory()->create();
    $gatedSponsee = invSponseeFor($gated);
    invGiveSponsorMinBv($gated);
    invSeedFailedCycle($gated->id, '2026-08-06');

    // Accrue every sponsee, freeze the day on the denominator, then credit —
    // the order the nightly cut-off follows.
    $svc = app(MentorshipBonusService::class);
    $accruals = [];
    foreach ([[$eligibleSponsee, 2], [$royaltySponsees[0], 1], [$royaltySponsees[1], 1], [$gatedSponsee, 1]] as [$sponsee, $slab]) {
        $accrual = $svc->accrueForSponsee($sponsee->id, invCreditedCutoff($sponsee, $slab, $date));
        expect($accrual)->not->toBeNull();
        assert($accrual !== null);
        $accruals[] = $accrual;
    }
    $denominator = array_sum(array_map(fn ($a): int => $a->countsInDenominator() ? $a->points : 0, $accruals));

    $pool = app(MsbDailyPoolService::class)->freezePoolForDate(Carbon::parse($date), $denominator);
    foreach ($accruals as $accrual) {
        $svc->creditAccrual($accrual, $pool);
    }

    $pool = MsbDailyPool::whereDate('cutoff_date', $date)->sole();
    $rows = MentorshipBonusResult::whereDate('cutoff_date', $date)->get();
    $credited = $rows->where('status', MentorshipBonusResult::STATUS_CREDITED);
    $withheld = (int) $credited->sum('royalty_cap_withheld_paise');

    // The fixture is the mix it set out to be: one gated row, a royalty cap that bit.
    expect($rows)->toHaveCount(4)
        ->and($rows->where('status', MentorshipBonusResult::STATUS_REPURCHASE_GATED))->toHaveCount(1)
        ->and($withheld)->toBeGreaterThan(0)
        ->and((int) $credited->where('sponsor_id', $royalty->id)->sum('mb_gross_paise'))->toBe(360_000);

    // The frozen denominator is the credited rows' points; their value is the pool's.
    expect((int) $pool->total_points)->toBe((int) $credited->sum('msb_points'))
        ->and((int) $pool->payout_paise)->toBe((int) $pool->total_points * (int) $pool->point_value_paise)
        ->and((int) $credited->sum('mb_gross_paise'))->toBe((int) $pool->payout_paise - $withheld)
        ->and((int) $credited->sum('mb_gross_paise'))->toBeLessThanOrEqual((int) $pool->pool_paise)
        ->and((int) $pool->payout_paise)->toBeLessThanOrEqual((int) $pool->pool_paise)
        ->and((int) $pool->point_value_paise)->toBeLessThanOrEqual((int) $pool->point_value_cap_paise)
        ->and((int) $pool->point_value_paise)->toBeLessThanOrEqual((int) $pool->raw_point_value_paise)
        ->and((int) $pool->pool_paise)->toBe(max(0, intdiv((int) $pool->company_bv_paise * (int) $pool->pool_rate_bp, 10_000)))
        ->and((int) $pool->company_bv_paise)->toBe(500_000_000)
        ->and((int) $pool->leftover_paise)->toBe((int) $pool->pool_paise - (int) $pool->payout_paise)
        ->and((int) $pool->leftover_paise)->toBeGreaterThanOrEqual(0);

    foreach ($credited as $row) {
        expect((int) $row->msb_point_value_paise)->toBe((int) $pool->point_value_paise)
            ->and($row->mb_gross_paise)->toBeGreaterThanOrEqual(0)
            ->and($row->mb_gross_paise + $row->royalty_cap_withheld_paise)->toBe($row->msb_points * (int) $pool->point_value_paise);
    }

    $gatedRow = $rows->firstWhere('status', MentorshipBonusResult::STATUS_REPURCHASE_GATED);
    expect($gatedRow?->mb_gross_paise)->toBe(0)
        ->and(WalletLedgerEntry::where('type', 'mb_credit')->where('distributor_id', $gated->id)->exists())->toBeFalse();
});
