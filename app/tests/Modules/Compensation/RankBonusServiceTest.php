<?php

declare(strict_types=1);

use App\Modules\Compensation\Exceptions\RepurchaseVerdictsPending;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\RankAogoGrant;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Models\RankMonthlyPass;
use App\Modules\Compensation\Models\RankMonthlyPool;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\RankBonusService;
use App\Modules\Compensation\Services\Rebuild\MonthRebuilder;
use App\Modules\Compliance\Models\AuditLog;
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
 * Pool arithmetic (client 2026-10-05): envelope = BV × 20%; pass 1 (AO-GO +
 * Ranks 1–3) divides the envelope by its points, pass 2 (Ranks 4–9) the
 * remainder; each point value floored to the rupee and capped at ₹200.
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

it('prices a Rank-1 achiever from the 20% envelope of company BV, capped at ₹200 a point', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    // Company BV = 200,000,000 paise (20,00,000 BV). Envelope = 20% =
    // 40,000,000. Pass 1 holds one achiever's 72 RAP → raw ₹5,555 a point,
    // capped at ₹200 → 72 × ₹200 = ₹14,400.
    seedRankCompanyBv(200_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $result = RankBonusResult::where('distributor_id', $dist->id)
        ->where('rank_number', 1)
        ->first();

    expect($result)->not->toBeNull();
    expect($result->company_turnover_paise)->toBe(200_000_000);
    expect($result->pool_paise)->toBe(1_440_000); // the rank's allotment
    expect($result->gross_paise)->toBe(1_440_000);
    expect(RankMonthlyPass::where('month_start', $monthStart)->where('pass', 1)->value('envelope_paise'))->toBe(40_000_000);
});

it('10,00,000 BV → 20% envelope ₹2,00,000 → one Rank-1 achiever paid 72 × ₹200 = ₹14,400', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    // 10,00,000 BV = 100,000,000 paise → envelope 20,000,000 paise.
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: '2026-06-01', occurrence: 1);

    $result = app(RankBonusService::class)->runForMonth($month);

    expect($result['turnover_paise'])->toBe(100_000_000);
    expect($result['by_rank'][1]['pool_paise'])->toBe(1_440_000);
    expect($result['passes'][1]['pool_paise'])->toBe(20_000_000)
        ->and($result['passes'][2]['leftover_paise'])->toBe(20_000_000 - 1_440_000);

    $row = RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 1)->first();
    expect($row->pool_paise)->toBe(1_440_000)
        ->and($row->gross_paise)->toBe(1_440_000)
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

    // Small pool: 1,000,000 BV paise × 20% envelope = 200,000 paise ÷ 72 RAP
    // → ₹27 a point (uncapped) → 72 × ₹27 = 194,400 paise.
    seedRankCompanyBv(1_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $result = RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 1)->first();

    // 194,400 gross → 10% repurchase = 19,440 taken at credit time; admin
    // charge and TDS are payout-time figures and stay at zero here.
    expect($result->gross_paise)->toBe(194_400);
    expect($result->repurchase_deduction_paise)->toBe(19_440);
    expect($result->net_paise)->toBe(174_960);
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
 * Two qualifiers were paid; the third arriving re-divided the pool on top of
 * what was already credited.
 */
it('cannot pay more than the frozen pool when a held qualifier clears later', function (): void {
    $month = Carbon::parse('2026-06-01');
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5)); // envelope ₹2,00,000; 2 × 72 RAP at the ₹200 cap = ₹28,800

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
        ->and($creditedGross)->toBe(2_880_000)
        ->and((int) $pool->leftover_paise)->toBe(0)
        ->and((int) $pool->leftover_paise)->toBeGreaterThanOrEqual(0)
        ->and((int) RankMonthlyPass::where('month_start', '2026-06-01')->sum('payout_paise'))->toBe(2_880_000);

    // The status decided at freeze stands; the hold is never back-paid.
    expect(RankBonusResult::where('distributor_id', $repeat->id)->value('status'))
        ->toBe(RankBonusResult::STATUS_REQUALIFICATION_HELD);
    expect(WalletLedgerEntry::where('type', 'rank_credit')->count())->toBe(2);

    // Credited and non-credited rows of the month agree on the economics.
    expect(RankBonusResult::where('month_start', '2026-06-01')->where('rank_number', 1)
        ->distinct()->pluck('pool_paise')->all())->toBe([2_880_000]);
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
        ->toBe(1_440_000);

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
        ->toBe(1_440_000);
    // The premature pass rows went with the pools: exactly two, re-priced.
    expect(RankMonthlyPass::where('month_start', '2026-06-01')->count())->toBe(2)
        ->and(RankMonthlyPass::where('month_start', '2026-06-01')->where('pass', 1)->value('envelope_paise'))->toBe(20_000_000);

    $row = RankBonusResult::where('distributor_id', $dist->id)->firstOrFail();
    expect($row->status)->toBe(RankBonusResult::STATUS_CREDITED)
        ->and((int) $row->gross_paise)->toBe(1_440_000);

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
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5)); // 2 × 72 RAP at ₹200 = ₹28,800

    $paidA = Distributor::factory()->create();
    $paidB = Distributor::factory()->create();

    seedRankQualification($paidA->id, rank: 1, monthStart: '2026-06-01');
    seedRankQualification($paidB->id, rank: 1, monthStart: '2026-06-01');

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $creditedBefore = (int) RankBonusResult::where('month_start', '2026-06-01')
        ->where('status', RankBonusResult::STATUS_CREDITED)->sum('gross_paise');
    expect($creditedBefore)->toBe(2_880_000);

    // Reproduce a legacy month: the credited rows survive, the pool and pass
    // rows never existed. A third achiever the old run never saw is on
    // today's roster.
    RankMonthlyPool::query()->delete();
    RankMonthlyPass::query()->delete();
    $newcomer = Distributor::factory()->create();
    seedRankQualification($newcomer->id, rank: 1, monthStart: '2026-06-01');

    expect(fn () => $svc->runForMonth($month))
        ->toThrow(RuntimeException::class, '2026-06-01');

    // Nothing was frozen, nothing was written, nothing was paid a second time.
    expect(RankMonthlyPool::count())->toBe(0)
        ->and(RankMonthlyPass::count())->toBe(0)
        ->and(RankBonusResult::where('distributor_id', $newcomer->id)->exists())->toBeFalse()
        ->and(WalletLedgerEntry::where('type', 'rank_credit')->count())->toBe(2);

    $creditedAfter = (int) RankBonusResult::where('month_start', '2026-06-01')
        ->where('status', RankBonusResult::STATUS_CREDITED)->sum('gross_paise');

    expect($creditedAfter)->toBe($creditedBefore)
        ->and($creditedAfter)->toBeLessThanOrEqual(2_880_000);
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
 * Client 2026-09-07 forfeit model (§2.2): the repurchase condition reaches Rank
 * Bonus ONLY through the Genos BV counted at qualification — a failed day's
 * group BV is never added. The bonus itself is never withheld: an achiever who
 * is still failed on the last day of the month is credited on the 1st and paid
 * on the 8th like everyone else.
 */
it('credits a rank achiever whose repurchase cycle is failed at month end — repurchase only filters BV', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $month = Carbon::parse('2026-06-01');
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5)); // 72 RAP at the ₹200 cap = ₹14,400

    $failed = Distributor::factory()->create();
    seedRankQualification($failed->id, rank: 1, monthStart: '2026-06-01');

    // Cycle due 20 June, never fulfilled — so 21–30 June are forfeited days and
    // the distributor is still failed on 30 June, the date the month is judged at.
    RepurchaseCycle::create([
        'distributor_id' => $failed->id,
        'cycle_start_date' => '2026-05-21',
        'due_date' => '2026-06-20',
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => 0,
        'wallet_balance_paise' => 50_000,
        'wallet_zeroed' => false,
        'status' => RepurchaseCycle::STATUS_SUSPENDED,
        'failure_reason' => RepurchaseCycle::REASON_BOTH,
        'resolved_at' => Carbon::parse('2026-06-21 00:05:00'),
    ]);

    app(RankBonusService::class)->runForMonth($month);

    $row = RankBonusResult::where('distributor_id', $failed->id)->firstOrFail();

    expect($row->status)->toBe(RankBonusResult::STATUS_CREDITED)
        ->and((int) $row->gross_paise)->toBe(1_440_000)
        ->and($row->credited_at)->not->toBeNull();

    // Real money moved: the rank credit exists in the ledger.
    expect(WalletLedgerEntry::query()
        ->where('distributor_id', $failed->id)
        ->where('reference_type', 'rank_bonus_result')
        ->where('reference_id', $row->id)
        ->exists())->toBeTrue();

    // The pool still reconciles against the frozen roster.
    $pool = RankMonthlyPool::where('month_start', '2026-06-01')->where('rank_number', 1)->firstOrFail();
    expect((int) $pool->payable_count)->toBe(1)
        ->and((int) $pool->payout_paise)->toBe(1_440_000)
        ->and((int) $pool->leftover_paise)->toBe(0);
});

it('keeps a premature freeze once something it funded was credited', function (): void {
    $month = Carbon::parse('2026-06-01');
    $dist = Distributor::factory()->create();
    seedRankQualification($dist->id, rank: 1, monthStart: '2026-06-01');
    // Envelope 200,000 paise ÷ 72 RAP → ₹27 a point (under the cap) → 194,400.
    seedRankCompanyBv(1_000_000, Carbon::parse('2026-06-05'));

    Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));
    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    // More BV lands before the month closes — it must NOT re-price a pool that
    // a wallet has already moved on (a re-freeze would price ₹55 a point).
    Carbon::setTestNow(Carbon::parse('2026-07-01 04:00:00'));
    seedRankCompanyBv(1_000_000, Carbon::parse('2026-06-20'));

    $svc->runForMonth($month);

    expect((int) RankMonthlyPool::where('month_start', '2026-06-01')->where('rank_number', 1)->value('pool_paise'))
        ->toBe(194_400);
    expect((int) RankMonthlyPass::where('month_start', '2026-06-01')->where('pass', 1)->value('envelope_paise'))
        ->toBe(200_000);
    expect(WalletLedgerEntry::where('type', 'rank_credit')->count())->toBe(1);
    expect((int) RankBonusResult::where('distributor_id', $dist->id)->value('gross_paise'))->toBe(194_400);
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

    // Envelope = 30,000 × 20% = 6,000 paise over 72 RAP → ₹0 point value.
    seedRankCompanyBv(30_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: '2026-06-01');

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);
    $svc->runForMonth($month);
    $svc->runForMonth($month);

    expect((int) RankMonthlyPass::where('month_start', '2026-06-01')->where('pass', 1)->value('pool_paise'))
        ->toBe(6_000)
        ->and((int) RankMonthlyPool::where('month_start', '2026-06-01')->where('rank_number', 1)->value('pool_paise'))
        ->toBe(0);

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

/**
 * Rank 3 qualified in July and August: tranche A opens in July, tranche B in
 * August (the client, 2026-10-09 — A on the 1st qualification, B on the 2nd).
 * August is a 2nd lifetime qualification, so it carries the §8 requalification
 * purchase (rank 3 = 1,200 BV).
 */
function qualifyRankThreeInJulyAndAugust(int $distributorId): void
{
    foreach (['2026-07-01', '2026-08-01'] as $m) {
        seedRankCompanyBv(10_000_000_00, Carbon::parse($m)->addDays(5));
        seedRankQualification($distributorId, 3, $m);
        if ($m === '2026-08-01') {
            seedRankMonthlyBv($distributorId, 120_000, '2026-08-10');
        }
        app(RankBonusService::class)->runForMonth(Carbon::parse($m));
    }
}

it('opens award tranche A on the first qualification and tranche B on the second (rank 3)', function (): void {
    $d = Distributor::factory()->create()->id;

    foreach (['2026-07-01', '2026-08-01'] as $i => $m) {
        seedRankCompanyBv(10_000_000_00, Carbon::parse($m)->addDays(5));
        seedRankQualification($d, 3, $m);
        if ($i === 1) {
            seedRankMonthlyBv($d, 120_000, '2026-08-10');
        }
        app(RankBonusService::class)->runForMonth(Carbon::parse($m));

        expect(LifetimeAwardMilestone::where('distributor_id', $d)->where('rank_number', 3)->pluck('tranche')->sort()->values()->all())
            ->toBe(range(1, $i + 1));
    }

    $a = LifetimeAwardMilestone::where('distributor_id', $d)->where('rank_number', 3)->where('tranche', 1)->firstOrFail();
    $b = LifetimeAwardMilestone::where('distributor_id', $d)->where('rank_number', 3)->where('tranche', 2)->firstOrFail();

    expect($a->amount_paise)->toBe(4_860_000)
        ->and($a->qualification_count)->toBe(2)
        ->and($a->triggered_month->toDateString())->toBe('2026-07-01')
        ->and($a->award_description)->toContain('tranche A');
    expect($b->amount_paise)->toBe(5_940_000)
        ->and($b->isReleasable())->toBeTrue()
        ->and($b->triggered_month->toDateString())->toBe('2026-08-01')
        ->and($b->award_description)->toContain('tranche B');
});

it('releases a tranche once the rank has been qualified at least tranche times', function (): void {
    $milestone = new LifetimeAwardMilestone(['tranche' => 3, 'qualification_count' => 2]);
    expect($milestone->isReleasable())->toBeFalse();

    $milestone->qualification_count = 3;
    expect($milestone->isReleasable())->toBeTrue();
});

it('refuses before any write when a rank with an achiever has no award tranche rows', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: '2026-06-01');
    DB::table('lifetime_award_tranches')->where('rank_number', 1)->delete();

    expect(fn () => app(RankBonusService::class)->runForMonth($month))
        ->toThrow(RuntimeException::class, 'lifetime_award_tranches has no rows for rank 1');

    expect(RankMonthlyPool::count())->toBe(0)
        ->and(RankBonusResult::count())->toBe(0)
        ->and(LifetimeAwardMilestone::count())->toBe(0)
        ->and(WalletLedgerEntry::where('type', 'rank_credit')->count())->toBe(0);
});

it('refuses a re-run of a frozen, credited month before any write when the rank lost its tranche rows', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: '2026-06-01');
    app(RankBonusService::class)->runForMonth($month);

    expect(RankBonusResult::where('distributor_id', $dist->id)->value('status'))->toBe(RankBonusResult::STATUS_CREDITED);

    // A late qualifier: the re-run would write a qualified_after_freeze audit row
    // if it got that far.
    $late = Distributor::factory()->create();
    seedRankQualification($late->id, rank: 1, monthStart: '2026-06-01');

    DB::table('lifetime_award_tranches')->where('rank_number', 1)->delete();
    // Drop any resolved plan cache so the re-run reads the table as it now is.
    app()->forgetInstance(CompensationPlanSettingsService::class);
    app()->forgetInstance(RankBonusService::class);

    $milestones = LifetimeAwardMilestone::count();
    $results = RankBonusResult::count();
    $lateAudits = AuditLog::where('action', 'rank.result.qualified_after_freeze')->count();
    $credits = WalletLedgerEntry::count();

    expect(fn () => app(RankBonusService::class)->runForMonth($month))
        ->toThrow(RuntimeException::class, 'lifetime_award_tranches has no rows for rank 1');

    expect(LifetimeAwardMilestone::count())->toBe($milestones)
        ->and(RankBonusResult::count())->toBe($results)
        ->and(AuditLog::where('action', 'rank.result.qualified_after_freeze')->count())->toBe($lateAudits)
        ->and(WalletLedgerEntry::count())->toBe($credits);
});

it('removes a pending tranche the rank no longer earns, with an audit row, and keeps tranche A', function (): void {
    $d = Distributor::factory()->create()->id;
    qualifyRankThreeInJulyAndAugust($d);

    // August's roster is gone (as a rebuild that re-derives it without the
    // distributor would leave it) while tranche B, written by August, survives.
    RankBonusResult::where('month_start', '2026-08-01')->delete();
    expect(LifetimeAwardMilestone::where('distributor_id', $d)->where('tranche', 2)->exists())->toBeTrue();

    app(RankBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));

    expect(LifetimeAwardMilestone::where('distributor_id', $d)->where('rank_number', 3)->pluck('tranche')->all())->toBe([1]);
    $a = LifetimeAwardMilestone::where('distributor_id', $d)->where('tranche', 1)->firstOrFail();
    expect($a->status)->toBe(LifetimeAwardMilestone::STATUS_PENDING)
        ->and($a->qualification_count)->toBe(1);

    $audit = AuditLog::where('action', 'awards.tranche.unearned_pending_removed')->where('subject_id', $d)->firstOrFail();
    expect($audit->details['rank'])->toBe(3)
        ->and($audit->details['qualification_count'])->toBe(1)
        ->and(array_column($audit->details['removed'], 'tranche'))->toBe([2]);
});

it('never removes a delivered tranche the rank no longer earns', function (): void {
    $d = Distributor::factory()->create()->id;
    qualifyRankThreeInJulyAndAugust($d);

    LifetimeAwardMilestone::where('distributor_id', $d)->where('tranche', 2)
        ->update(['status' => LifetimeAwardMilestone::STATUS_DELIVERED, 'delivered_at' => now()]);
    RankBonusResult::where('month_start', '2026-08-01')->delete();

    app(RankBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));

    expect(LifetimeAwardMilestone::where('distributor_id', $d)->where('rank_number', 3)->orderBy('tranche')->pluck('tranche')->all())->toBe([1, 2])
        ->and(AuditLog::where('action', 'awards.tranche.unearned_pending_removed')->exists())->toBeFalse();
});

it('a month rebuild of August takes tranche B with it and a July re-run leaves tranche A pending', function (): void {
    $d = Distributor::factory()->create()->id;
    qualifyRankThreeInJulyAndAugust($d);

    // MonthRebuilder::pendingMilestones() matches tranche B by its triggered
    // month, so the wipe removes it before the engine ever sees it as orphaned.
    app(MonthRebuilder::class)->wipe(Carbon::parse('2026-08-01'), 1, fn (string $line) => null, fn () => null);

    expect(LifetimeAwardMilestone::where('distributor_id', $d)->where('tranche', 2)->exists())->toBeFalse();

    app(RankBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));

    expect(LifetimeAwardMilestone::where('distributor_id', $d)->where('rank_number', 3)->pluck('tranche')->all())->toBe([1])
        ->and(LifetimeAwardMilestone::where('distributor_id', $d)->where('tranche', 1)->value('status'))->toBe(LifetimeAwardMilestone::STATUS_PENDING);
});

it('divides pass 1 by points — 3 achievers × 72 + 2 AO-GO × 36 = 288 points, ₹100 per point', function (): void {
    $month = Carbon::parse('2026-06-01');
    // June company BV must total exactly 14,400,000 paise (1,44,000 BV) so the
    // envelope is 14,400,000 × 20% = ₹28,800 = 288 points × ₹100 (under the
    // ₹200 cap). The two AO-GO ex-rankers below each add 1,000 BV (100,000
    // paise) of their own, so the sentinel row carries the remaining 1,42,000 BV.
    seedRankCompanyBv(14_400_000 - 200_000, $month->copy()->addDays(5));

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

    // 3 achievers × 72 RAP + 2 AO-GO × 36 = 288 points → ₹100/point.
    expect($result['by_rank'][1]['total_points'])->toBe(288);
    expect($result['by_rank'][1]['point_value_paise'])->toBe(10_000);
    expect($result['by_rank'][1]['aogo_grants'])->toBe(2);

    foreach ($achievers as $achiever) {
        $row = RankBonusResult::where('distributor_id', $achiever->id)->where('rank_number', 1)->first();
        expect($row->gross_paise)->toBe(720_000) // ₹7,200
            ->and($row->rap_points)->toBe(72)
            ->and($row->aogo_points)->toBeNull()
            ->and($row->status)->toBe(RankBonusResult::STATUS_CREDITED);
    }

    foreach ($exRankers as $exRanker) {
        $row = RankBonusResult::where('distributor_id', $exRanker->id)->where('rank_number', 1)->first();
        expect($row->gross_paise)->toBe(360_000) // ₹3,600
            ->and($row->aogo_points)->toBe(36)
            ->and($row->rap_points)->toBeNull();

        $grant = RankAogoGrant::where('distributor_id', $exRanker->id)->first();
        expect($grant->status)->toBe(RankAogoGrant::STATUS_CREDITED)
            ->and($grant->grant_number)->toBe(1)
            ->and($grant->point_value_paise)->toBe(10_000)
            ->and($grant->income_paise)->toBe(360_000);
    }

    // Whole envelope spent: 3 × 7,200 + 2 × 3,600 = ₹28,800.
    expect($result['by_rank'][1]['gross_total'])->toBe(2_880_000)
        ->and($result['passes'][2]['leftover_paise'])->toBe(0);

    // Idempotent rerun: nobody is double-credited.
    $svc->runForMonth($month);
    expect(WalletLedgerEntry::where('type', 'rank_credit')->count())->toBe(5);
});

it('holds a repeat qualification missing the requalification conditions and excludes it from the pool (KP §8)', function (): void {
    $month = Carbon::parse('2026-06-01');
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5)); // envelope ₹2,00,000

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
    // the first-timer's 72 RAP alone → 72 × the ₹200 cap.
    expect($result['by_rank'][1]['total_points'])->toBe(72);
    expect($result['by_rank'][1]['held'])->toBe(1);
    $paidRow = RankBonusResult::where('distributor_id', $firstTimer->id)->where('rank_number', 1)->first();
    expect($paidRow->gross_paise)->toBe(1_440_000)
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

it('pays Rank 2 achievers their own RAP × the pass-1 point value, with the points columns filled', function (): void {
    $month = Carbon::parse('2026-06-01');
    // Envelope = 10,00,000 BV × 20% = 20,000,000 paise ÷ 2 × 189 RAP → raw
    // ₹529 a point, capped at ₹200 → 189 × ₹200 = ₹37,800 each.
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));

    $a = Distributor::factory()->create();
    $b = Distributor::factory()->create();
    seedRankQualification($a->id, rank: 2, monthStart: '2026-06-01');
    seedRankQualification($b->id, rank: 2, monthStart: '2026-06-01');

    app(RankBonusService::class)->runForMonth($month);

    foreach ([$a, $b] as $dist) {
        $row = RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 2)->first();
        expect($row->gross_paise)->toBe(3_780_000)
            ->and($row->rap_points)->toBe(189)
            ->and($row->total_points)->toBe(378)
            ->and($row->point_value_paise)->toBe(20_000)
            ->and($row->status)->toBe(RankBonusResult::STATUS_CREDITED);
    }
});

it('pays ranks 3–9 on the first occurrence — pyp no longer filters payment', function (): void {
    $month = Carbon::parse('2026-06-01');
    // Envelope ₹2,00,000 ÷ 468 RAP → raw ₹427, capped at ₹200 → ₹93,600.
    seedRankCompanyBv(100_000_000, $month->copy()->addDays(5));

    $dist = Distributor::factory()->create();
    // Single occurrence; rank 3's Q-Period is 2 — payment must not require it.
    seedRankQualification($dist->id, rank: 3, monthStart: '2026-06-01', occurrence: 1);

    app(RankBonusService::class)->runForMonth($month);

    $row = RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 3)->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe(RankBonusResult::STATUS_CREDITED)
        ->and($row->gross_paise)->toBe(9_360_000);
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
    // Envelope = 13,050,000 × 20% = 2,610,000 paise.
    seedRankCompanyBv(13_050_000, $month->copy()->addDays(5));

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

    // Pass 1 holds the pure R1 achiever's 72 RAP and the dual achiever's Rank-2
    // 189 RAP only — never their Rank-1 points too: 261 points → ₹100/point.
    expect($result['passes'][1]['total_points'])->toBe(261);
    $silverRow = RankBonusResult::where('distributor_id', $silverOnly->id)->firstOrFail();
    expect($silverRow->rank_number)->toBe(1)
        ->and($silverRow->rap_points)->toBe(72)
        ->and((int) $silverRow->point_value_paise)->toBe(10_000)
        ->and((int) $silverRow->gross_paise)->toBe(720_000)
        // 10% repurchase deduction frozen on the row at credit time.
        ->and((int) $silverRow->repurchase_deduction_paise)->toBe(72_000)
        ->and((int) $silverRow->net_paise)->toBe(648_000);

    // Rank 2: 189 × ₹100 = ₹18,900.
    expect((int) $pearlRows->first()->gross_paise)->toBe(1_890_000)
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
    // Envelope = 13,050,000 × 20% = 2,610,000 paise (the exclusive test's).
    seedRankCompanyBv(13_050_000, $month->copy()->addDays(5));

    $silverOnly = Distributor::factory()->create();
    seedRankQualification($silverOnly->id, rank: 1, monthStart: '2026-06-01');

    $pearl = Distributor::factory()->create();
    seedRankQualification($pearl->id, rank: 1, monthStart: '2026-06-01');
    seedRankQualification($pearl->id, rank: 2, monthStart: '2026-06-01');

    $result = app(RankBonusService::class)->runForMonth($month);

    // Cumulative: the dual achiever holds TWO result rows (R1 + R2).
    expect(RankBonusResult::where('distributor_id', $pearl->id)->count())->toBe(2)
        ->and($result['credited'])->toBe(3);

    // Pass 1 counts the dual achiever's Rank-1 points too: 2 × 72 + 189 = 333
    // points → floor(₹26,100 ÷ 333) = ₹78/point (₹100 when exclusive).
    expect($result['passes'][1]['total_points'])->toBe(333);
    $silverRow = RankBonusResult::where('distributor_id', $silverOnly->id)->firstOrFail();
    expect((int) $silverRow->point_value_paise)->toBe(7_800)
        ->and((int) $silverRow->gross_paise)->toBe(561_600)
        ->and((int) $silverRow->net_paise)->toBe(505_440);
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

// ── Two-pass pool at a capped point value (client 2026-10-05) ───────────────

/**
 * Qualify $n fresh distributors at $rank for the month.
 *
 * @return list<int>
 */
function seedRankCohort(int $n, int $rank, string $monthStart): array
{
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $id = Distributor::factory()->create()->id;
        seedRankQualification($id, $rank, $monthStart);
        $ids[] = $id;
    }

    return $ids;
}

/**
 * $n AO-GO grants for the month, 36 points each — written directly, as the
 * engine reuses a month's live grants (AogoOfferService::grantForMonth()).
 *
 * @return list<int>
 */
function seedAogoGrants(int $n, string $monthStart): array
{
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $id = Distributor::factory()->create()->id;
        RankAogoGrant::create([
            'distributor_id' => $id,
            'month_start' => $monthStart,
            'grant_number' => 1,
            'points' => 36,
            'previous_rank_number' => 1,
            'status' => RankAogoGrant::STATUS_GRANTED,
        ]);
        $ids[] = $id;
    }

    return $ids;
}

/**
 * F-9 identities on a frozen month: Σ rank allotments + pass-2 leftover =
 * envelope; pass-2 pool = envelope − pass-1 payout; every payout ≤ its pool;
 * Σ pool payout = Σ pass payout ≤ envelope; every pending roster row's gross =
 * its points × the point value of its pass.
 */
function rankAssertPassIdentities(string $monthStart): void
{
    $passes = RankMonthlyPass::where('month_start', $monthStart)->get()->keyBy('pass');
    $pools = RankMonthlyPool::where('month_start', $monthStart)->get()->keyBy('rank_number');

    expect($passes->keys()->sort()->values()->all())->toBe([1, 2])
        ->and($pools)->toHaveCount(9);

    $envelope = (int) $passes[1]->envelope_paise;

    expect((int) $passes[2]->envelope_paise)->toBe($envelope)
        ->and((int) $pools->sum('pool_paise') + (int) $passes[2]->leftover_paise)->toBe($envelope)
        ->and((int) $passes[1]->pool_paise)->toBe($envelope)
        ->and((int) $passes[2]->pool_paise)->toBe($envelope - (int) $passes[1]->payout_paise)
        ->and((int) $pools->sum('payout_paise'))->toBe((int) $passes->sum('payout_paise'))
        ->and((int) $passes->sum('payout_paise'))->toBeLessThanOrEqual($envelope);

    foreach ($passes as $pass) {
        expect((int) $pass->payout_paise)->toBeLessThanOrEqual((int) $pass->pool_paise)
            ->and((int) $pass->payout_paise)->toBe((int) $pass->total_points * (int) $pass->point_value_paise)
            ->and((int) $pass->point_value_paise)->toBeLessThanOrEqual((int) $pass->point_value_cap_paise);
    }

    $rows = RankBonusResult::where('month_start', $monthStart)
        ->whereIn('status', [RankBonusResult::STATUS_PENDING, RankBonusResult::STATUS_CREDITED])
        ->get();

    foreach ($rows as $row) {
        $pass = $passes[(int) $pools[(int) $row->rank_number]->pass];
        $points = (int) ($row->aogo_points ?? $row->rap_points);

        expect((int) $row->gross_paise)->toBe($points * (int) $pass->point_value_paise)
            ->and((int) $row->point_value_paise)->toBe((int) $pass->point_value_paise);
    }
}

/** An active cycle due inside the month that repurchase:evaluate has not judged yet. */
function rankSeedPendingCycle(int $distributorId, string $dueDate): RepurchaseCycle
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
        'status' => RepurchaseCycle::STATUS_ACTIVE,
        'failure_reason' => null,
        'resolved_at' => null,
    ]);
}

it('example A2: AGO + Rank 1 share the whole pool at the floored value (188)', function (): void {
    $m = '2026-09-01';
    seedRankCompanyBv(95_000_000, Carbon::parse('2026-09-10')); // 9,50,000 BV → envelope 1,90,000
    $r1 = seedRankCohort(9, 1, $m);
    seedAogoGrants(10, $m); // 10 AO-GO grantees for the month, 36 points each
    $out = app(RankBonusService::class)->runForMonth(Carbon::parse($m));

    expect($out['passes'][1]['total_points'])->toBe(1_008)
        ->and($out['passes'][1]['raw_point_value_paise'])->toBe(18_800)
        ->and($out['passes'][1]['point_value_paise'])->toBe(18_800)
        ->and(RankBonusResult::where('distributor_id', $r1[0])->value('gross_paise'))->toBe(72 * 18_800)   // ₹13,536
        ->and($out['passes'][1]['leftover_paise'])->toBe(49_600)                 // what pass 1 hands to pass 2
        ->and($out['passes'][2]['pool_paise'])->toBe(19_000_000 - 1_008 * 18_800) // ₹496 remainder, nobody in pass 2
        ->and($out['passes'][2]['total_points'])->toBe(0)
        ->and($out['passes'][2]['leftover_paise'])->toBe(49_600);

    rankAssertPassIdentities($m);
});

it('example C1: pass 1 is capped at ₹200 when the raw value exceeds it', function (): void {
    $m = '2026-09-01';
    seedRankCompanyBv(630_000_000, Carbon::parse('2026-09-10')); // 63L BV → envelope 12,60,000
    seedRankCohort(9, 1, $m);
    seedRankCohort(8, 2, $m);
    $r3 = seedRankCohort(7, 3, $m);
    seedAogoGrants(10, $m);
    $out = app(RankBonusService::class)->runForMonth(Carbon::parse($m));

    expect($out['passes'][1]['raw_point_value_paise'])->toBe(21_700)
        ->and($out['passes'][1]['point_value_paise'])->toBe(20_000)
        ->and(RankBonusResult::where('distributor_id', $r3[0])->value('gross_paise'))->toBe(468 * 20_000) // ₹93,600
        ->and($out['passes'][2]['pool_paise'])->toBe(126_000_000 - 115_920_000)                        // ₹1,00,800 left
        ->and($out['passes'][2]['leftover_paise'])->toBe(10_080_000);

    rankAssertPassIdentities($m);
});

it('example D2: ranks 4–9 share the remainder at the floored value (186)', function (): void {
    $m = '2026-09-01';
    seedRankCompanyBv(16_000_000_000, Carbon::parse('2026-09-10')); // 16 Cr BV → envelope 3.2 Cr
    seedRankCohort(9, 1, $m);
    seedRankCohort(8, 2, $m);
    seedRankCohort(7, 3, $m);
    seedAogoGrants(10, $m);
    seedRankCohort(6, 4, $m);
    seedRankCohort(5, 5, $m);
    seedRankCohort(4, 6, $m);
    seedRankCohort(3, 7, $m);
    $r8 = seedRankCohort(2, 8, $m);
    $r9 = seedRankCohort(1, 9, $m);
    $out = app(RankBonusService::class)->runForMonth(Carbon::parse($m));

    expect($out['passes'][1]['point_value_paise'])->toBe(20_000)
        ->and($out['passes'][1]['payout_paise'])->toBe(115_920_000)                 // ₹11,59,200
        ->and($out['passes'][2]['pool_paise'])->toBe(3_200_000_000 - 115_920_000)   // ₹3,08,40,800
        ->and($out['passes'][2]['total_points'])->toBe(165_474)
        ->and($out['passes'][2]['raw_point_value_paise'])->toBe(18_600)
        ->and(RankBonusResult::where('distributor_id', $r8[0])->value('gross_paise'))->toBe(23_877 * 18_600) // ₹44,41,122
        ->and(RankBonusResult::where('distributor_id', $r9[0])->value('gross_paise'))->toBe(39_501 * 18_600) // ₹73,47,186
        ->and($out['passes'][2]['leftover_paise'])->toBe(6_263_600);                 // ₹62,636

    // Per-rank rows carry the rank's allotment; the pass row carries the leftover.
    $r8Pool = RankMonthlyPool::where('month_start', $m)->where('rank_number', 8)->firstOrFail();
    expect($r8Pool->pass)->toBe(2)
        ->and($r8Pool->pool_paise)->toBe(2 * 23_877 * 18_600)
        ->and($r8Pool->leftover_paise)->toBe(0);

    rankAssertPassIdentities($m);
});

it('with nobody in pass 1, pass 2 divides the whole envelope', function (): void {
    $m = '2026-09-01';
    seedRankCompanyBv(100_000_000, Carbon::parse('2026-09-10')); // 10L BV → envelope 2,00,000
    $r4 = seedRankCohort(1, 4, $m);
    $out = app(RankBonusService::class)->runForMonth(Carbon::parse($m));
    expect($out['passes'][1]['total_points'])->toBe(0)->and($out['passes'][1]['point_value_paise'])->toBe(0)
        ->and($out['passes'][2]['pool_paise'])->toBe(20_000_000)
        ->and($out['passes'][2]['raw_point_value_paise'])->toBe(17_700) // floor(2,00,000 / 1,125) = 177
        ->and(RankBonusResult::where('distributor_id', $r4[0])->value('gross_paise'))->toBe(1_125 * 17_700);

    rankAssertPassIdentities($m);
});

it('a refund-heavy month prices both passes at zero and credits nothing', function (): void {
    $m = '2026-09-01';
    seedRankCompanyBv(-500_000_000, Carbon::parse('2026-09-10'));
    seedRankCohort(2, 1, $m);
    $out = app(RankBonusService::class)->runForMonth(Carbon::parse($m));
    expect($out['credited'])->toBe(0)->and($out['passes'][1]['point_value_paise'])->toBe(0)
        ->and($out['passes'][2]['pool_paise'])->toBe(0)
        ->and(RankMonthlyPass::where('month_start', $m)->min('envelope_paise'))->toBe(0)
        ->and(WalletLedgerEntry::where('type', 'rank_credit')->count())->toBe(0);

    rankAssertPassIdentities($m);
});

it('refuses a point value cap below ₹1 before any write (fail-safe principle 1)', function (): void {
    DB::table('settings')->updateOrInsert(
        ['key' => 'comp.rank.point_value_cap_paise'],
        ['value' => '50', 'version' => 1, 'created_at' => now(), 'updated_at' => now()],
    );
    $m = '2026-09-01';
    seedRankCompanyBv(100_000_000, Carbon::parse('2026-09-10'));
    seedRankCohort(1, 1, $m);
    seedAogoGrants(1, $m);
    $grantsBefore = RankAogoGrant::count();

    expect(fn () => app(RankBonusService::class)->runForMonth(Carbon::parse($m)))
        ->toThrow(RuntimeException::class, 'comp.rank.point_value_cap_paise must be at least 100 paise');

    expect(RankMonthlyPass::count())->toBe(0)
        ->and(RankMonthlyPool::count())->toBe(0)
        ->and(RankBonusResult::count())->toBe(0)
        ->and(RankAogoGrant::count())->toBe($grantsBefore)
        ->and(WalletLedgerEntry::where('type', 'rank_credit')->count())->toBe(0);
});

it('refuses a rank with payable achievers but no RAP points before any write (fail-safe principle 1)', function (): void {
    DB::table('rank_tiers')->where('rank_number', 4)->update(['rap_points' => 0]);
    $m = '2026-09-01';
    seedRankCompanyBv(100_000_000, Carbon::parse('2026-09-10'));
    seedRankCohort(1, 4, $m);

    expect(fn () => app(RankBonusService::class)->runForMonth(Carbon::parse($m)))
        ->toThrow(RuntimeException::class, 'rank_tiers.rap_points is not set for rank 4');

    expect(RankMonthlyPass::count())->toBe(0)
        ->and(RankMonthlyPool::count())->toBe(0)
        ->and(RankBonusResult::count())->toBe(0)
        ->and(WalletLedgerEntry::where('type', 'rank_credit')->count())->toBe(0);
});

it('refuses to freeze while an achiever has an unresolved repurchase cycle due inside the month (fail-safe principle 2)', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $m = '2026-09-01';
    seedRankCompanyBv(100_000_000, Carbon::parse('2026-09-10'));
    $r1 = seedRankCohort(1, 1, $m);
    rankSeedPendingCycle($r1[0], '2026-09-20');

    expect(fn () => app(RankBonusService::class)->runForMonth(Carbon::parse($m)))
        ->toThrow(RepurchaseVerdictsPending::class, 'Run repurchase:evaluate first');

    expect(RankMonthlyPool::count())->toBe(0)
        ->and(RankMonthlyPass::count())->toBe(0)
        ->and(RankBonusResult::count())->toBe(0)
        ->and(WalletLedgerEntry::count())->toBe(0);
});

it('records a pending repurchase verdict as a failed Rank Bonus run', function (): void {
    Feature::for(null)->activate(RankBonusFeature::class);
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $r1 = seedRankCohort(1, 1, '2026-06-01');
    rankSeedPendingCycle($r1[0], '2026-06-20');

    expect(Artisan::call('rank:monthly-run', ['--month' => '2026-06', '--force' => true]))->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('Run repurchase:evaluate first')
        ->and(RankMonthlyPool::count())->toBe(0);
});

// F-10 (a freeze that throws leaves no pass rows) is pinned in PlanInvariantsTest.php.
