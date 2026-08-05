<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\RankAogoGrant;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\RankBonusService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/**
 * Company-wide BV for the month — the Rank Bonus base is the signed
 * bv_ledger_entries sum, not order sales value. Booked against a sentinel
 * distributor id so it never collides with the personal-BV rows that the §8
 * requalification gate and the AO-GO offer read per distributor.
 *
 * Pool arithmetic: pool = BV × 20% envelope × the rank's pool_pct.
 */
function seedRankCompanyBv(int $bvPaise, Carbon $effectiveAt): void
{
    static $fakeOrderId = 960000;
    $timestamp = $effectiveAt->format('Y-m-d H:i:s');

    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => 999001,
        'order_id' => $fakeOrderId++,
        'bv_paise' => $bvPaise,
        'type' => $bvPaise < 0 ? 'reversal' : 'accrual',
        'effective_at' => $timestamp,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
}

function seedRankQualification(int $distributorId, int $rank, string $monthStart, int $occurrence = 1, bool $carryForward = false): void
{
    RankQualification::create([
        'distributor_id' => $distributorId,
        'rank_number' => $rank,
        'month_start' => $monthStart,
        'occurrence_in_month' => $occurrence,
        'is_carry_forward' => $carryForward,
        'status' => RankQualification::STATUS_QUALIFIED,
    ]);
}

/** Personal-purchase BV accrual dated inside a specific month (feeds the §8 requalification gate). */
function seedRankMonthlyBv(int $distributorId, int $bvPaise, string $date): void
{
    static $fakeOrderId = 970000;
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'order_id' => $fakeOrderId++,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => $date.' 10:00:00',
        'created_at' => $date.' 10:00:00',
        'updated_at' => $date.' 10:00:00',
    ]);
}

it('returns zero credited when no qualifiers exist', function (): void {
    $month = Carbon::parse('2026-06-01');
    seedRankCompanyBv(10_000_000, $month->copy()->addDays(5));

    $svc = app(RankBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['credited'])->toBe(0);
    expect(RankBonusResult::count())->toBe(0);
});

it('calculates the rank pool as its share of the 20% envelope of company BV', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    // Company BV = 200,000,000 paise (20,00,000 BV). Envelope = 20% =
    // 40,000,000. Rank 1's 7% share of the envelope = 2,800,000 paise.
    seedRankCompanyBv(200_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $result = RankBonusResult::where('distributor_id', $dist->id)
        ->where('rank_number', 1)
        ->first();

    expect($result)->not->toBeNull();
    expect($result->company_turnover_paise)->toBe(200_000_000);
    expect($result->pool_paise)->toBe(2_800_000);
    expect($result->gross_paise)->toBe(2_800_000);
});

it('KP worked example: 10,00,000 BV → 20% envelope → Rank-1 7% share = ₹14,000', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    // 10,00,000 BV = 100,000,000 paise → envelope 20,00,000 → Rank 1 ₹14,000.
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: '2026-06-01', occurrence: 1);

    $result = app(RankBonusService::class)->runForMonth($month);

    expect($result['turnover_paise'])->toBe(100_000_000);
    expect($result['by_rank'][1]['pool_paise'])->toBe(1_400_000);

    $row = RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 1)->first();
    expect($row->pool_paise)->toBe(1_400_000)
        ->and($row->gross_paise)->toBe(1_400_000)
        ->and($row->status)->toBe(RankBonusResult::STATUS_CREDITED);
});

it('floors a refund-heavy (negative company BV) month to a zero pool and credits nobody', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedRankCompanyBv(20_000_000, $month->copy()->addDays(3));
    seedRankCompanyBv(-50_000_000, $month->copy()->addDays(9));
    seedRankQualification($dist->id, rank: 1, monthStart: '2026-06-01', occurrence: 1);

    $result = app(RankBonusService::class)->runForMonth($month);

    expect($result['turnover_paise'])->toBe(-30_000_000);
    expect($result['credited'])->toBe(0);

    foreach (range(1, 9) as $rank) {
        expect($result['by_rank'][$rank]['pool_paise'])->toBe(0);
    }

    expect(WalletLedgerEntry::where('type', 'rank_credit')->count())->toBe(0);
    expect(RankBonusResult::where('status', RankBonusResult::STATUS_CREDITED)->count())->toBe(0);
});

it('applies admin charge as min(3% of gross, ₹30,000)', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    // Small pool: 1,000,000 BV paise × 20% envelope × 7% = 14,000 paise.
    seedRankCompanyBv(1_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $result = RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 1)->first();

    // Deductions are applied at payout time, not at credit time.
    expect($result->admin_charge_paise)->toBe(0);
    expect($result->net_paise)->toBe($result->gross_paise);
});

it('records zero admin charge and tds in the result (deductions are deferred to payout)', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    seedRankCompanyBv(10_000_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $result = RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 1)->first();

    expect($result->admin_charge_paise)->toBe(0);
    expect($result->tds_paise)->toBe(0);
});

it('credits net_paise equal to gross_paise (deductions deferred to payout)', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $result = RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 1)->first();

    expect($result->tds_paise)->toBe(0);
    expect($result->net_paise)->toBe($result->gross_paise);
});

it('credits wallet with rank_credit type', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $ledger = WalletLedgerEntry::where('distributor_id', $dist->id)
        ->where('type', 'rank_credit')
        ->first();

    expect($ledger)->not->toBeNull();
    expect($ledger->amount_paise)->toBeGreaterThan(0);
});

it('is idempotent — re-running the same month does not double-credit', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);
    $svc->runForMonth($month);

    expect(RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 1)->count())->toBe(1);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'rank_credit')->count())->toBe(1);
});

it('creates a LifetimeAwardMilestone on first rank achievement', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $milestone = LifetimeAwardMilestone::where('distributor_id', $dist->id)
        ->where('rank_number', 1)
        ->first();

    expect($milestone)->not->toBeNull();
    expect($milestone->status)->toBe(LifetimeAwardMilestone::STATUS_PENDING);
    expect($milestone->award_description)->toContain('Silver Partner');
});

it('does not create a duplicate LifetimeAwardMilestone on second qualification', function (): void {
    $dist = Distributor::factory()->create();
    $month1 = Carbon::parse('2026-06-01');
    $month2 = Carbon::parse('2026-07-01');

    seedRankCompanyBv(100_000_000, $month1->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: '2026-06-01', occurrence: 1);

    seedRankCompanyBv(100_000_000, $month2->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: '2026-07-01', occurrence: 1);
    // July is a 2nd lifetime qualification — meet the §8 requalification
    // conditions (1,000 BV personal purchase) so it credits and increments.
    seedRankMonthlyBv($dist->id, 100_000, '2026-07-10');

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month1);
    $svc->runForMonth($month2);

    expect(LifetimeAwardMilestone::where('distributor_id', $dist->id)->where('rank_number', 1)->count())->toBe(1);
    expect(LifetimeAwardMilestone::where('distributor_id', $dist->id)->where('rank_number', 1)->value('qualification_count'))->toBe(2);
});

it('divides the rank-1 pool by points — KP worked example: ₹14,000 pool, 40 points, ₹350 per point', function (): void {
    $month = Carbon::parse('2026-06-01');
    // June company BV must total exactly 100,000,000 paise (10,00,000 BV) so the
    // Rank-1 pool is 100,000,000 × 20% envelope × 7% = ₹14,000. The two AO-GO
    // ex-rankers below each add 1,000 BV (100,000 paise) of their own, so the
    // sentinel row carries the remaining 9,98,000 BV.
    seedRankCompanyBv(100_000_000 - 200_000, $month->copy()->addDays(5));

    $achievers = Distributor::factory()->count(3)->create();
    foreach ($achievers as $achiever) {
        seedRankQualification($achiever->id, rank: 1, monthStart: '2026-06-01');
    }

    // Two degraded ex-rank-holders: achieved Rank 1 in April, unranked in June,
    // and meeting the AO-GO monthly conditions (1,000 BV in June).
    $exRankers = Distributor::factory()->count(2)->create();
    foreach ($exRankers as $exRanker) {
        seedRankQualification($exRanker->id, rank: 1, monthStart: '2026-04-01');
        seedRankMonthlyBv($exRanker->id, 100_000, '2026-06-10');
    }

    $svc = app(RankBonusService::class);
    $result = $svc->runForMonth($month);

    // 3 achievers × 10 RAP + 2 AO-GO × 5 = 40 points → ₹350/point.
    expect($result['by_rank'][1]['total_points'])->toBe(40);
    expect($result['by_rank'][1]['point_value_paise'])->toBe(35_000);
    expect($result['by_rank'][1]['aogo_grants'])->toBe(2);

    foreach ($achievers as $achiever) {
        $row = RankBonusResult::where('distributor_id', $achiever->id)->where('rank_number', 1)->first();
        expect($row->gross_paise)->toBe(350_000) // ₹3,500
            ->and($row->rap_points)->toBe(10)
            ->and($row->aogo_points)->toBeNull()
            ->and($row->status)->toBe(RankBonusResult::STATUS_CREDITED);
    }

    foreach ($exRankers as $exRanker) {
        $row = RankBonusResult::where('distributor_id', $exRanker->id)->where('rank_number', 1)->first();
        expect($row->gross_paise)->toBe(175_000) // ₹1,750
            ->and($row->aogo_points)->toBe(5)
            ->and($row->rap_points)->toBeNull();

        $grant = RankAogoGrant::where('distributor_id', $exRanker->id)->first();
        expect($grant->status)->toBe(RankAogoGrant::STATUS_CREDITED)
            ->and($grant->grant_number)->toBe(1)
            ->and($grant->point_value_paise)->toBe(35_000)
            ->and($grant->income_paise)->toBe(175_000);
    }

    // Whole pool spent: 3 × 3,500 + 2 × 1,750 = ₹14,000.
    expect($result['by_rank'][1]['net_total'])->toBe(1_400_000);

    // Idempotent rerun: nobody is double-credited.
    $svc->runForMonth($month);
    expect(WalletLedgerEntry::where('type', 'rank_credit')->count())->toBe(5);
});

it('holds a repeat qualification missing the requalification conditions and excludes it from the pool (KP §8)', function (): void {
    $month = Carbon::parse('2026-06-01');
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5)); // pool ₹14,000

    $repeat = Distributor::factory()->create();
    $firstTimer = Distributor::factory()->create();

    seedRankQualification($repeat->id, rank: 1, monthStart: '2026-05-01'); // prior lifetime achievement
    seedRankQualification($repeat->id, rank: 1, monthStart: '2026-06-01');
    seedRankQualification($firstTimer->id, rank: 1, monthStart: '2026-06-01');
    // $repeat has NO June personal BV → fails the 1,000 BV condition.
    // $firstTimer is a first-time achiever → exempt.

    $result = app(RankBonusService::class)->runForMonth($month);

    $heldRow = RankBonusResult::where('distributor_id', $repeat->id)->where('rank_number', 1)->first();
    expect($heldRow->status)->toBe(RankBonusResult::STATUS_REQUALIFICATION_HELD)
        ->and($heldRow->net_paise)->toBe(0);
    expect(WalletLedgerEntry::where('distributor_id', $repeat->id)->where('type', 'rank_credit')->exists())->toBeFalse();

    // Held achievers do not dilute the pool (MSB precedent): denominator is
    // the first-timer's 10 RAP alone → the whole ₹14,000 goes to them.
    expect($result['by_rank'][1]['total_points'])->toBe(10);
    expect($result['by_rank'][1]['held'])->toBe(1);
    $paidRow = RankBonusResult::where('distributor_id', $firstTimer->id)->where('rank_number', 1)->first();
    expect($paidRow->gross_paise)->toBe(1_400_000)
        ->and($paidRow->status)->toBe(RankBonusResult::STATUS_CREDITED);
});

it('credits a repeat qualification that meets the requalification conditions', function (): void {
    $month = Carbon::parse('2026-06-01');
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));

    $repeat = Distributor::factory()->create();
    seedRankQualification($repeat->id, rank: 1, monthStart: '2026-05-01');
    seedRankQualification($repeat->id, rank: 1, monthStart: '2026-06-01');
    seedRankMonthlyBv($repeat->id, 100_000, '2026-06-12'); // 1,000 BV met

    app(RankBonusService::class)->runForMonth($month);

    $row = RankBonusResult::where('distributor_id', $repeat->id)->where('rank_number', 1)->first();
    expect($row->status)->toBe(RankBonusResult::STATUS_CREDITED);
});

it('splits ranks 2–9 pools equally among achievers with null points columns', function (): void {
    $month = Carbon::parse('2026-06-01');
    // Rank-2 pool = 10,00,000 BV × 20% envelope × 3.4% = 680,000 paise (₹6,800).
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));

    $a = Distributor::factory()->create();
    $b = Distributor::factory()->create();
    seedRankQualification($a->id, rank: 2, monthStart: '2026-06-01');
    seedRankQualification($b->id, rank: 2, monthStart: '2026-06-01');

    app(RankBonusService::class)->runForMonth($month);

    foreach ([$a, $b] as $dist) {
        $row = RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 2)->first();
        expect($row->gross_paise)->toBe(340_000)
            ->and($row->rap_points)->toBeNull()
            ->and($row->total_points)->toBeNull()
            ->and($row->point_value_paise)->toBeNull()
            ->and($row->status)->toBe(RankBonusResult::STATUS_CREDITED);
    }
});

it('pays ranks 3–9 on the first occurrence — pyp no longer filters payment', function (): void {
    $month = Carbon::parse('2026-06-01');
    // R3 pool = 10,00,000 BV × 20% envelope × 2.7% = 540,000 paise (₹5,400).
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));

    $dist = Distributor::factory()->create();
    // Single occurrence; rank 3's Q-Period is 2 — payment must not require it.
    seedRankQualification($dist->id, rank: 3, monthStart: '2026-06-01', occurrence: 1);

    app(RankBonusService::class)->runForMonth($month);

    $row = RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 3)->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe(RankBonusResult::STATUS_CREDITED)
        ->and($row->gross_paise)->toBe(540_000);
});
