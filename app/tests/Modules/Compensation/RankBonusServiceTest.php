<?php

declare(strict_types=1);

use App\Modules\Compensation\Events\IncomeReactivated;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\RankAogoGrant;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Models\RankMonthlyPool;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\RankBonusService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Models\Distributor;
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

it('freezes the repurchase deduction on the row and credits gross minus it', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    // Small pool: 1,000,000 BV paise × 20% envelope × 7% = 14,000 paise.
    seedRankCompanyBv(1_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $result = RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 1)->first();

    // 14,000 gross → 10% repurchase = 1,400 taken at credit time; admin
    // charge and TDS are payout-time figures and stay at zero here.
    expect($result->gross_paise)->toBe(14_000);
    expect($result->repurchase_deduction_paise)->toBe(1_400);
    expect($result->net_paise)->toBe(12_600);
    expect($result->admin_charge_paise)->toBe(0);
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

it('credits net_paise equal to gross_paise minus the repurchase deduction', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $result = RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 1)->first();

    expect($result->tds_paise)->toBe(0);
    expect($result->net_paise)->toBe($result->gross_paise - $result->repurchase_deduction_paise);
    expect($result->repurchase_deduction_paise)->toBe((int) floor($result->gross_paise / 10));
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

/**
 * The defect the rank_monthly_pools freeze exists to kill: the engine used to
 * recompute the pool AND the roster on every run, so a held qualifier clearing
 * their §8 conditions later re-divided a pool that had already been paid out.
 * Two qualifiers paid the whole ₹14,000; the third arriving turned that into
 * ₹14,000 × 3 ÷ 3 on top of what was already credited.
 */
it('cannot pay more than the frozen pool when a held qualifier clears later', function (): void {
    $month = Carbon::parse('2026-06-01');
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5)); // Rank-1 pool ₹14,000

    $firstTimerA = Distributor::factory()->create();
    $firstTimerB = Distributor::factory()->create();
    $repeat = Distributor::factory()->create();

    seedRankQualification($firstTimerA->id, rank: 1, monthStart: '2026-06-01');
    seedRankQualification($firstTimerB->id, rank: 1, monthStart: '2026-06-01');
    // A repeat achiever with no June personal BV → §8 requalification held.
    seedRankQualification($repeat->id, rank: 1, monthStart: '2026-05-01');
    seedRankQualification($repeat->id, rank: 1, monthStart: '2026-06-01');

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    // The held achiever now completes their repurchase obligation for June.
    seedRankMonthlyBv($repeat->id, 100_000, '2026-06-25');

    $svc->runForMonth($month);

    $pool = RankMonthlyPool::where('month_start', '2026-06-01')->where('rank_number', 1)->firstOrFail();
    $creditedGross = (int) RankBonusResult::where('month_start', '2026-06-01')
        ->where('rank_number', 1)
        ->where('status', RankBonusResult::STATUS_CREDITED)
        ->sum('gross_paise');

    expect($creditedGross)->toBeLessThanOrEqual((int) $pool->pool_paise)
        ->and($creditedGross)->toBe(1_400_000)
        ->and((int) $pool->leftover_paise)->toBe(0)
        ->and((int) $pool->leftover_paise)->toBeGreaterThanOrEqual(0);

    // The status decided at freeze stands; the hold is never back-paid.
    expect(RankBonusResult::where('distributor_id', $repeat->id)->value('status'))
        ->toBe(RankBonusResult::STATUS_REQUALIFICATION_HELD);
    expect(WalletLedgerEntry::where('type', 'rank_credit')->count())->toBe(2);

    // Credited and non-credited rows of the month agree on the economics.
    expect(RankBonusResult::where('month_start', '2026-06-01')->where('rank_number', 1)
        ->distinct()->pluck('pool_paise')->all())->toBe([1_400_000]);
});

it('refuses and reports a distributor who qualifies after the pool was frozen', function (): void {
    $month = Carbon::parse('2026-06-01');
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));

    $onTime = Distributor::factory()->create();
    seedRankQualification($onTime->id, rank: 1, monthStart: '2026-06-01');

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    // A rank qualification recorded for the month AFTER the freeze.
    $late = Distributor::factory()->create();
    seedRankQualification($late->id, rank: 1, monthStart: '2026-06-01');

    $result = $svc->runForMonth($month);

    expect($result['qualified_after_freeze'])->toBe(1)
        ->and($result['by_rank'][1]['qualified_after_freeze'])->toBe(1)
        ->and($svc->qualifiedAfterFreeze($month))->toBe([1 => [$late->id]]);

    // Refused: no row, no money, and the on-time achiever keeps the whole pool.
    expect(RankBonusResult::where('distributor_id', $late->id)->exists())->toBeFalse();
    expect(WalletLedgerEntry::where('distributor_id', $late->id)->count())->toBe(0);
    expect((int) RankBonusResult::where('distributor_id', $onTime->id)->value('gross_paise'))
        ->toBe(1_400_000);

    // R-35: withholding a month's income permanently is an audit fact with an
    // 8-year retention, not a log line that rotates away.
    $audit = DB::table('audit_log')
        ->where('action', 'rank.result.qualified_after_freeze')
        ->where('subject_type', 'distributor')
        ->where('subject_id', $late->id)
        ->get();

    expect($audit)->toHaveCount(1);

    $details = json_decode((string) $audit->first()->details, true);
    expect($details['month_start'])->toBe('2026-06-01')
        ->and($details['rank_number'])->toBe(1)
        ->and($details['distributor_id'])->toBe($late->id);
});

it('replaces a premature freeze when nothing it funded was credited', function (): void {
    $month = Carbon::parse('2026-06-01');
    $dist = Distributor::factory()->create();
    seedRankQualification($dist->id, rank: 1, monthStart: '2026-06-01');

    // Mid-month manual run: no BV yet, so the month freezes a ₹0 pool.
    Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));
    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    expect((int) RankMonthlyPool::where('month_start', '2026-06-01')->where('rank_number', 1)->value('pool_paise'))
        ->toBe(0);

    // The month closes with real BV; the scheduled run must re-freeze.
    Carbon::setTestNow(Carbon::parse('2026-07-01 04:00:00'));
    seedRankCompanyBv(100_000_000, Carbon::parse('2026-06-20'));

    $svc->runForMonth($month);

    expect(RankMonthlyPool::where('month_start', '2026-06-01')->count())->toBe(9);
    expect((int) RankMonthlyPool::where('month_start', '2026-06-01')->where('rank_number', 1)->value('pool_paise'))
        ->toBe(1_400_000);

    $row = RankBonusResult::where('distributor_id', $dist->id)->firstOrFail();
    expect($row->status)->toBe(RankBonusResult::STATUS_CREDITED)
        ->and((int) $row->gross_paise)->toBe(1_400_000);

    // The hard delete must stay reconstructable from audit_log alone: the
    // discarded rows are snapshotted, not merely counted.
    $refrozen = DB::table('audit_log')->where('action', 'rank.pool.refrozen')->sole();
    $details = json_decode((string) $refrozen->details, true);

    expect($details['discarded_results'])->toBe(1)
        ->and($details['discarded_rows'])->toHaveCount(1)
        ->and($details['discarded_rows'][0]['distributor_id'])->toBe($dist->id)
        ->and($details['discarded_rows'][0]['rank_number'])->toBe(1)
        ->and($details['discarded_rows'][0])->toHaveKeys(['id', 'status', 'gross_paise']);
});

/**
 * The pre-freeze engine left every month it ran with credited results and no
 * rank_monthly_pools row — indistinguishable from a never-run month. Freezing
 * such a month prices a fresh pool against today's roster while
 * writeRosterRow() skips the rows already credited, so anyone the new roster
 * adds is paid on top of a pool the month has already spent.
 */
it('refuses a month the pre-freeze engine already paid rather than re-pricing it', function (): void {
    $month = Carbon::parse('2026-06-01');
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5)); // Rank-1 pool ₹14,000

    $paidA = Distributor::factory()->create();
    $paidB = Distributor::factory()->create();

    seedRankQualification($paidA->id, rank: 1, monthStart: '2026-06-01');
    seedRankQualification($paidB->id, rank: 1, monthStart: '2026-06-01');

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $creditedBefore = (int) RankBonusResult::where('month_start', '2026-06-01')
        ->where('status', RankBonusResult::STATUS_CREDITED)->sum('gross_paise');
    expect($creditedBefore)->toBe(1_400_000);

    // Reproduce a legacy month: the credited rows survive, the pool row never
    // existed. A third achiever the old run never saw is on today's roster.
    RankMonthlyPool::query()->delete();
    $newcomer = Distributor::factory()->create();
    seedRankQualification($newcomer->id, rank: 1, monthStart: '2026-06-01');

    expect(fn () => $svc->runForMonth($month))
        ->toThrow(RuntimeException::class, '2026-06-01');

    // Nothing was frozen, nothing was written, nothing was paid a second time.
    expect(RankMonthlyPool::count())->toBe(0)
        ->and(RankBonusResult::where('distributor_id', $newcomer->id)->exists())->toBeFalse()
        ->and(WalletLedgerEntry::where('type', 'rank_credit')->count())->toBe(2);

    $creditedAfter = (int) RankBonusResult::where('month_start', '2026-06-01')
        ->where('status', RankBonusResult::STATUS_CREDITED)->sum('gross_paise');

    expect($creditedAfter)->toBe($creditedBefore)
        ->and($creditedAfter)->toBeLessThanOrEqual(1_400_000);
});

it('still freezes a month whose legacy rows moved no money', function (): void {
    $month = Carbon::parse('2026-06-01');
    $dist = Distributor::factory()->create();

    // A held row: recorded by the old engine, never credited, so re-pricing the
    // month cannot pay anything twice.
    RankBonusResult::create([
        'distributor_id' => $dist->id,
        'month_start' => '2026-06-01',
        'rank_number' => 1,
        'company_turnover_paise' => 0,
        'pool_paise' => 0,
        'qualifier_count' => 0,
        'gross_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_paise' => 0,
        'status' => RankBonusResult::STATUS_REQUALIFICATION_HELD,
    ]);

    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: '2026-06-01');

    app(RankBonusService::class)->runForMonth($month);

    expect(RankMonthlyPool::where('month_start', '2026-06-01')->count())->toBe(9);
});

/**
 * Client 2026-09-06 rule 7 adds Rank Bonus to the withheld four, and rule 8 pays
 * the withheld amount on fulfilment — so a repurchase-held achiever is priced at
 * the month's full rate and stays in the denominator. The frozen pool must still
 * reconcile: payout + leftover = pool, and payout = Σ roster gross.
 */
it('prices a repurchase-held achiever at the full rate and still reconciles the frozen pool', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $month = Carbon::parse('2026-06-01');
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5)); // Rank-1 pool ₹14,000

    $paid = Distributor::factory()->create();
    $held = Distributor::factory()->create();
    $rank2Paid = Distributor::factory()->create();
    $rank2Held = Distributor::factory()->create();

    seedRankQualification($paid->id, rank: 1, monthStart: '2026-06-01');
    seedRankQualification($held->id, rank: 1, monthStart: '2026-06-01');
    seedRankQualification($rank2Paid->id, rank: 2, monthStart: '2026-06-01');
    seedRankQualification($rank2Held->id, rank: 2, monthStart: '2026-06-01');

    // A repurchase window that closed on 30 May unfulfilled — so these two were
    // still failed on 30 June, the date the month is judged at.
    foreach ([$held->id, $rank2Held->id] as $distributorId) {
        RepurchaseCycle::create([
            'distributor_id' => $distributorId,
            'cycle_start_date' => '2026-05-01',
            'due_date' => '2026-05-30',
            'required_bv_paise' => 60_000,
            'completed_bv_paise' => 0,
            'wallet_balance_paise' => 50_000,
            'wallet_zeroed' => false,
            'status' => RepurchaseCycle::STATUS_SUSPENDED,
            'failure_reason' => RepurchaseCycle::REASON_BOTH,
            'resolved_at' => Carbon::parse('2026-05-31 00:05:00'),
        ]);
    }

    app(RankBonusService::class)->runForMonth($month);

    foreach ([1, 2] as $rank) {
        $pool = RankMonthlyPool::where('month_start', '2026-06-01')->where('rank_number', $rank)->firstOrFail();
        $rosterGross = (int) RankBonusResult::where('month_start', '2026-06-01')
            ->where('rank_number', $rank)->sum('gross_paise');

        expect((int) $pool->payout_paise)->toBe($rosterGross)
            ->and((int) $pool->payout_paise + (int) $pool->leftover_paise)->toBe((int) $pool->pool_paise)
            ->and((int) $pool->leftover_paise)->toBeGreaterThanOrEqual(0)
            ->and((int) $pool->payable_count)->toBe(2);
    }

    // The whole Rank-1 pool is committed — half of it waiting on a fulfilment.
    $rank1 = RankMonthlyPool::where('month_start', '2026-06-01')->where('rank_number', 1)->firstOrFail();
    expect((int) $rank1->payout_paise)->toBe(1_400_000)
        ->and((int) $rank1->leftover_paise)->toBe(0);

    // Held and paid carry the same gross; only the status differs.
    expect((int) RankBonusResult::where('distributor_id', $held->id)->value('gross_paise'))->toBe(700_000)
        ->and(RankBonusResult::where('distributor_id', $held->id)->value('status'))
        ->toBe(RankBonusResult::STATUS_REPURCHASE_HELD)
        ->and(RankBonusResult::where('distributor_id', $paid->id)->value('status'))
        ->toBe(RankBonusResult::STATUS_CREDITED);

    // Nothing reached the held distributor's wallet.
    expect(app(WalletService::class)->balancePaise($held->id))->toBe(0);
});

it('releases a held Rank Bonus row when the distributor fulfils the repurchase', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $month = Carbon::parse('2026-06-01');
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));

    $held = Distributor::factory()->create();
    seedRankQualification($held->id, rank: 1, monthStart: '2026-06-01');

    $cycle = RepurchaseCycle::create([
        'distributor_id' => $held->id,
        'cycle_start_date' => '2026-05-01',
        'due_date' => '2026-05-30',
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => 0,
        'wallet_balance_paise' => 50_000,
        'wallet_zeroed' => false,
        'status' => RepurchaseCycle::STATUS_SUSPENDED,
        'failure_reason' => RepurchaseCycle::REASON_BOTH,
        'resolved_at' => Carbon::parse('2026-05-31 00:05:00'),
    ]);

    app(RankBonusService::class)->runForMonth($month);

    $row = RankBonusResult::where('distributor_id', $held->id)->firstOrFail();
    expect($row->status)->toBe(RankBonusResult::STATUS_REPURCHASE_HELD);

    event(new IncomeReactivated($held->id, $cycle->id));

    expect($row->fresh()->status)->toBe(RankBonusResult::STATUS_CREDITED)
        ->and(app(WalletService::class)->balancePaise($held->id))->toBeGreaterThan(0);
});

it('keeps a premature freeze once something it funded was credited', function (): void {
    $month = Carbon::parse('2026-06-01');
    $dist = Distributor::factory()->create();
    seedRankQualification($dist->id, rank: 1, monthStart: '2026-06-01');
    seedRankCompanyBv(100_000_000, Carbon::parse('2026-06-05'));

    Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));
    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    // More BV lands before the month closes — it must NOT re-price a pool that
    // a wallet has already moved on.
    Carbon::setTestNow(Carbon::parse('2026-07-01 04:00:00'));
    seedRankCompanyBv(100_000_000, Carbon::parse('2026-06-20'));

    $svc->runForMonth($month);

    expect((int) RankMonthlyPool::where('month_start', '2026-06-01')->where('rank_number', 1)->value('pool_paise'))
        ->toBe(1_400_000);
    expect(WalletLedgerEntry::where('type', 'rank_credit')->count())->toBe(1);
    expect((int) RankBonusResult::where('distributor_id', $dist->id)->value('gross_paise'))->toBe(1_400_000);
});

/**
 * The count used to be an unconditional increment fired once per run for every
 * payable distributor. A row whose gross floors to ₹0 never reaches `credited`,
 * so the credited-guard never short-circuited it and three re-runs of one month
 * counted three qualifications.
 */
it('counts one lifetime qualification across three runs even when the gross floors to zero', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    // Pool = 50,000 × 20% × 7% = 700 paise over 10 RAP → ₹0 point value.
    seedRankCompanyBv(50_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: '2026-06-01');

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);
    $svc->runForMonth($month);
    $svc->runForMonth($month);

    expect((int) RankMonthlyPool::where('month_start', '2026-06-01')->where('rank_number', 1)->value('pool_paise'))
        ->toBe(700);

    $row = RankBonusResult::where('distributor_id', $dist->id)->firstOrFail();
    expect((int) $row->gross_paise)->toBe(0)
        ->and($row->status)->toBe(RankBonusResult::STATUS_PENDING);

    expect(LifetimeAwardMilestone::where('distributor_id', $dist->id)->where('rank_number', 1)->count())->toBe(1)
        ->and(LifetimeAwardMilestone::where('distributor_id', $dist->id)->value('qualification_count'))->toBe(1);
    expect(WalletLedgerEntry::where('type', 'rank_credit')->count())->toBe(0);
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
    expect($result['by_rank'][1]['gross_total'])->toBe(1_400_000);

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

/**
 * A Rank-2 achiever has by definition also cleared Rank 1's bar (8L/side ⊃
 * 3L/side), and the qualification service records BOTH ranks — deliberately,
 * so structural counts ("2 Pearl Partners per side") and the GBB prior-month
 * gate can query cleared ranks directly. The bonus run must therefore pay
 * each distributor ONLY their highest qualified rank: KP's plan is explicit
 * that reaching Rank 2 cancels the Rank-1 benefit. The first real July 2026
 * run paid all seven dual qualifiers from both pools.
 */
it('pays only the highest qualified rank when a distributor cleared several', function (): void {
    $month = Carbon::parse('2026-06-01');
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));

    // One pure Rank-1 achiever, one dual achiever (cleared both bars).
    $silverOnly = Distributor::factory()->create();
    seedRankQualification($silverOnly->id, rank: 1, monthStart: '2026-06-01');

    $pearl = Distributor::factory()->create();
    seedRankQualification($pearl->id, rank: 1, monthStart: '2026-06-01');
    seedRankQualification($pearl->id, rank: 2, monthStart: '2026-06-01');

    $result = app(RankBonusService::class)->runForMonth($month);

    // The dual achiever gets exactly one result row — Rank 2.
    $pearlRows = RankBonusResult::where('distributor_id', $pearl->id)->get();
    expect($pearlRows)->toHaveCount(1)
        ->and($pearlRows->first()->rank_number)->toBe(2);

    // Rank-1 pool = ₹14,000; only the pure R1 achiever's 10 RAP are in the
    // denominator → ₹1,400/point → ₹14,000, all to the silver-only achiever.
    $silverRow = RankBonusResult::where('distributor_id', $silverOnly->id)->firstOrFail();
    expect($silverRow->rank_number)->toBe(1)
        ->and($silverRow->rap_points)->toBe(10)
        ->and((int) $silverRow->point_value_paise)->toBe(140_000)
        ->and((int) $silverRow->gross_paise)->toBe(1_400_000)
        // 10% repurchase deduction frozen on the row at credit time.
        ->and((int) $silverRow->repurchase_deduction_paise)->toBe(140_000)
        ->and((int) $silverRow->net_paise)->toBe(1_260_000);

    // Rank-2 pool = 100,000,000 × 20% × 3.4% = ₹6,800, sole achiever takes it.
    expect((int) $pearlRows->first()->gross_paise)->toBe(680_000)
        ->and($result['credited'])->toBe(2);
});

/**
 * The exclusive-vs-cumulative choice is a plan setting awaiting the product
 * owner's ruling. OFF = cumulative: a dual qualifier is paid from every rank
 * pool whose bar they cleared, and they count in each pool's denominator.
 */
it('pays every cleared rank when pay_highest_rank_only is switched off', function (): void {
    DB::table('settings')->insert([
        'key' => 'comp.rank.pay_highest_rank_only', 'value' => 'false', 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $month = Carbon::parse('2026-06-01');
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));

    $silverOnly = Distributor::factory()->create();
    seedRankQualification($silverOnly->id, rank: 1, monthStart: '2026-06-01');

    $pearl = Distributor::factory()->create();
    seedRankQualification($pearl->id, rank: 1, monthStart: '2026-06-01');
    seedRankQualification($pearl->id, rank: 2, monthStart: '2026-06-01');

    $result = app(RankBonusService::class)->runForMonth($month);

    // Cumulative: the dual achiever holds TWO result rows (R1 + R2).
    expect(RankBonusResult::where('distributor_id', $pearl->id)->count())->toBe(2)
        ->and($result['credited'])->toBe(3);

    // R1 pool ₹14,000 across 2 achievers × 10 RAP = 20 points → ₹700/point.
    $silverRow = RankBonusResult::where('distributor_id', $silverOnly->id)->firstOrFail();
    expect((int) $silverRow->point_value_paise)->toBe(70_000)
        ->and((int) $silverRow->gross_paise)->toBe(700_000)
        ->and((int) $silverRow->net_paise)->toBe(630_000);
});

it('refuses the monthly run when the rank qualification check has not succeeded for that month', function () {
    Feature::for(null)->activate(RankBonusFeature::class);

    // No rank.check EngineRun for June: the 00:15 prerequisite never completed.
    $exit = Artisan::call('rank:monthly-run', ['--month' => '2026-06']);

    expect($exit)->toBe(Command::FAILURE);
    expect(Artisan::output())->toContain('rank:check-qualifications --month=2026-06');
    expect(RankBonusResult::where('month_start', '2026-06-01')->count())->toBe(0);
    expect(RankAogoGrant::where('month_start', '2026-06-01')->count())->toBe(0);
});

it('runs the month once the qualification check has succeeded for it', function () {
    Feature::for(null)->activate(RankBonusFeature::class);

    EngineRun::create([
        'engine_key' => 'rank.check',
        'period_start' => '2026-06-01',
        'status' => EngineRun::STATUS_SUCCEEDED,
        'trigger' => EngineRun::TRIGGER_CONSOLE,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    expect(Artisan::call('rank:monthly-run', ['--month' => '2026-06']))->toBe(Command::SUCCESS);
});

it('lets --force run a month whose qualification check never ran', function () {
    Feature::for(null)->activate(RankBonusFeature::class);

    expect(Artisan::call('rank:monthly-run', ['--month' => '2026-06', '--force' => true]))
        ->toBe(Command::SUCCESS);
});
