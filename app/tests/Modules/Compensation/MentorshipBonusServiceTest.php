<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\GsbCutoffDeferral;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compensation\Models\MsbDailyPool;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\MentorshipBonusService;
use App\Modules\Compensation\Services\RepurchaseCycleService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function makeSponsorship(Distributor $sponsor, Distributor $sponsee): void
{
    DB::table('sponsorship')->insert([
        'sponsor_id' => $sponsor->id,
        'distributor_id' => $sponsee->id,
        'created_at' => now(),
    ]);
}

/** Give a sponsor exactly the minimum 600 BV (60,000 paise) needed for bonus eligibility. */
function giveSponsorMinBv(Distributor $sponsor): void
{
    BvLedgerEntry::create([
        'distributor_id' => $sponsor->id,
        'order_id' => 700_000 + $sponsor->id,
        'bv_paise' => 60_000,
        'type' => 'accrual',
        'effective_at' => now(),
    ]);
}

/** A credited GSB cut-off for $sponsee matching $slab. */
function makeCreditedCutoff(Distributor $sponsee, int $slab, int $grossGsbPaise = 100_000, string $status = 'credited'): GsbCutoffResult
{
    return GsbCutoffResult::create([
        'distributor_id' => $sponsee->id,
        'cutoff_date' => today()->toDateString(),
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'slab' => $slab, 'gross_gsb_paise' => $grossGsbPaise,
        'admin_charge_paise' => 0, 'tds_paise' => 0, 'net_gsb_paise' => $grossGsbPaise,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => $status,
    ]);
}

/**
 * Freeze today's MSB pool at a chosen point value. The single-distributor path
 * never freezes a pool itself, so every credit test needs the day's economics
 * to exist first — exactly as the nightly run leaves them.
 */
function freezeMsbPoolAt(int $pointValuePaise, int $totalPoints = 60): MsbDailyPool
{
    $payout = $pointValuePaise * $totalPoints;

    return MsbDailyPool::create([
        'cutoff_date' => today()->toDateString(),
        'company_bv_paise' => $payout === 0 ? 0 : intdiv($payout * 10_000, 300),
        'pool_rate_bp' => 300,
        'pool_paise' => $payout,
        'total_points' => $totalPoints,
        'point_value_paise' => $pointValuePaise,
        'payout_paise' => $payout,
        'leftover_paise' => 0,
    ]);
}

it('credits the sponsor with slab points × the day\'s pooled point value (slab 1 → 21 × ₹250 = ₹5,250)', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);
    freezeMsbPoolAt(25_000);

    $mb = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, makeCreditedCutoff($sponsee, 1));

    expect($mb)->not->toBeNull();
    expect($mb->slab)->toBe(1);
    expect($mb->msb_points)->toBe(21);
    expect($mb->msb_point_value_paise)->toBe(25_000);
    expect($mb->mb_gross_paise)->toBe(525_000);   // 21 × 25,000 paise = ₹5,250
    // Deductions are deferred to payout time.
    expect($mb->mb_admin_charge_paise)->toBe(0);
    expect($mb->mb_tds_paise)->toBe(0);
    expect($mb->status)->toBe('credited');

    // Sponsor wallet credited with gross, stamped with the CUT-OFF day: the
    // weekly payout's earning week windows on it, and Mentorship rides the same
    // Wednesday-to-Tuesday week as GSB (spec 2026-09-07 §3, A3).
    $entry = WalletLedgerEntry::where('distributor_id', $sponsor->id)
        ->where('type', 'mb_credit')
        ->sole();
    expect((int) $entry->amount_paise)->toBe(525_000)
        ->and($entry->earned_on->toDateString())->toBe(today()->toDateString());
});

it('pays each slab its own points at one shared value (slab 3 → 15 pts; slab 7 → 3 pts, both @ ₹250)', function () {
    $sponsor = Distributor::factory()->create();
    $sponseeA = Distributor::factory()->create();
    $sponseeB = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponseeA);
    makeSponsorship($sponsor, $sponseeB);
    giveSponsorMinBv($sponsor);
    freezeMsbPoolAt(25_000);

    $svc = app(MentorshipBonusService::class);
    $mbA = $svc->processForSponsee($sponseeA->id, makeCreditedCutoff($sponseeA, 3));
    $mbB = $svc->processForSponsee($sponseeB->id, makeCreditedCutoff($sponseeB, 7));

    expect($mbA->msb_points)->toBe(15);
    expect($mbA->mb_gross_paise)->toBe(375_000);
    expect($mbB->msb_points)->toBe(3);
    expect($mbB->mb_gross_paise)->toBe(75_000);
    expect((int) WalletLedgerEntry::where('distributor_id', $sponsor->id)->sum('amount_paise'))->toBe(450_000);
});

it('prices every credit at the day\'s frozen pool value, whatever the slab', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    // A busier day resolves to ₹100/point.
    freezeMsbPoolAt(10_000);

    $mb = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, makeCreditedCutoff($sponsee, 2));

    expect($mb->msb_points)->toBe(18);
    expect($mb->msb_point_value_paise)->toBe(10_000);
    expect($mb->mb_gross_paise)->toBe(180_000);   // 18 × ₹100 = ₹1,800
});

it('returns null without crediting when the day has no frozen pool', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    // No freezeMsbPoolAt() — e.g. a date whose nightly ran before MSB was on.
    $mb = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, makeCreditedCutoff($sponsee, 1));

    expect($mb)->toBeNull();
    expect(MentorshipBonusResult::count())->toBe(0);
    expect(WalletLedgerEntry::where('distributor_id', $sponsor->id)->count())->toBe(0);
});

it('writes a ₹0 row with no wallet entry when the day priced at zero', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    // A day nobody accrued on: the pool went unspent and froze at ₹0.
    freezeMsbPoolAt(0, 0);

    $mb = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, makeCreditedCutoff($sponsee, 1));

    expect($mb)->not->toBeNull();
    expect($mb->msb_points)->toBe(21);
    expect($mb->msb_point_value_paise)->toBe(0);
    expect($mb->mb_gross_paise)->toBe(0);
    expect(WalletLedgerEntry::where('distributor_id', $sponsor->id)->count())->toBe(0);
});

it('keeps historical rows unchanged when admin later edits the slab points or the pool rate', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);
    freezeMsbPoolAt(25_000);

    $mb = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, makeCreditedCutoff($sponsee, 1));
    expect($mb->mb_gross_paise)->toBe(525_000);

    DB::table('gsb_slabs')->where('slab', 1)->update(['msb_score' => 99]);
    DB::table('settings')->updateOrInsert(['key' => 'comp.msb.pool_rate_bp'], ['value' => '1']);

    $fresh = MentorshipBonusResult::findOrFail($mb->id);
    expect($fresh->msb_points)->toBe(21);
    expect($fresh->msb_point_value_paise)->toBe(25_000);
    expect($fresh->mb_gross_paise)->toBe(525_000);
});

it('returns null when the slab carries zero MSB points', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    DB::table('gsb_slabs')->where('slab', 1)->update(['msb_score' => 0]);
    freezeMsbPoolAt(25_000);

    $mb = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, makeCreditedCutoff($sponsee, 1));

    expect($mb)->toBeNull();
    expect(MentorshipBonusResult::count())->toBe(0);
    expect(WalletLedgerEntry::where('distributor_id', $sponsor->id)->count())->toBe(0);
});

it('returns null for a non-credited cut-off', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    $mb = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, makeCreditedCutoff($sponsee, 1, 100_000, 'repurchase_forfeited'));

    expect($mb)->toBeNull();
    expect(MentorshipBonusResult::count())->toBe(0);
});

it('is idempotent — calling twice for the same cutoff does not double-credit', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    freezeMsbPoolAt(25_000);
    $cutoffResult = makeCreditedCutoff($sponsee, 1);

    $svc = app(MentorshipBonusService::class);
    $svc->processForSponsee($sponsee->id, $cutoffResult);
    $second = $svc->processForSponsee($sponsee->id, $cutoffResult);  // second call — returns the existing row

    expect($second)->not->toBeNull();
    expect(MentorshipBonusResult::count())->toBe(1);
    expect(WalletLedgerEntry::where('distributor_id', $sponsor->id)->where('type', 'mb_credit')->count())->toBe(1);
    expect((int) WalletLedgerEntry::where('distributor_id', $sponsor->id)->sum('amount_paise'))->toBe(525_000);
});

it('takes the repurchase deduction from the MB credit — the fifth deduction source (client 2026-09-10)', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);
    freezeMsbPoolAt(25_000);

    $mb = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, makeCreditedCutoff($sponsee, 1));

    // 10% of ₹5,250 gross moves to the repurchase wallet at credit time.
    expect($mb->mb_gross_paise)->toBe(525_000)
        ->and($mb->repurchase_deduction_paise)->toBe(52_500)
        ->and($mb->mb_net_paise)->toBe(472_500);

    // Three entries, so the statement shows what was earned, what was withheld
    // and where it went.
    $entries = WalletLedgerEntry::where('distributor_id', $sponsor->id)
        ->get()
        ->groupBy('type')
        ->map(fn ($rows) => (int) $rows->sum('amount_paise'));

    expect($entries['mb_credit'])->toBe(525_000)
        ->and($entries['repurchase_transfer'])->toBe(-52_500)
        ->and($entries['repurchase_deduction'])->toBe(52_500);

    // All three carry the earning day, so the weekly batch sweeps them together.
    expect(WalletLedgerEntry::where('distributor_id', $sponsor->id)
        ->whereDate('earned_on', today()->toDateString())
        ->count())->toBe(3);
});

it('honours the monthly repurchase cap: a sponsor already at the ceiling is credited gross', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);
    freezeMsbPoolAt(25_000);

    // The month's ₹10,000 deduction ceiling is already spent by earlier bonuses.
    WalletLedgerEntry::create([
        'distributor_id' => $sponsor->id,
        'type' => 'repurchase_deduction',
        'amount_paise' => 1_000_000,
        'reference_id' => 1,
        'reference_type' => 'gsb_cutoff_result',
        'bonus_month' => today()->startOfMonth()->toDateString(),
        'earned_on' => today()->toDateString(),
    ]);

    $mb = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, makeCreditedCutoff($sponsee, 1));

    expect($mb->repurchase_deduction_paise)->toBe(0)
        ->and($mb->mb_net_paise)->toBe(525_000)
        ->and(WalletLedgerEntry::where('distributor_id', $sponsor->id)->where('type', 'repurchase_transfer')->count())->toBe(0);
});

it('blocks MB credit when sponsor personal BV is below the minimum threshold', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);

    // Sponsor has only 599 BV (59,900 paise) — one BV below the 600 BV gate.
    BvLedgerEntry::create([
        'distributor_id' => $sponsor->id,
        'order_id' => 700_000 + $sponsor->id,
        'bv_paise' => 59_900,
        'type' => 'accrual',
        'effective_at' => now(),
    ]);

    $mb = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, makeCreditedCutoff($sponsee, 1));

    expect($mb)->toBeNull();
    expect(MentorshipBonusResult::count())->toBe(0);
    expect(WalletLedgerEntry::where('distributor_id', $sponsor->id)->count())->toBe(0);
});

it('never mutates the sponsee GSB row or the sponsor personal BV ledger', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    freezeMsbPoolAt(25_000);
    $bvBefore = (int) BvLedgerEntry::where('distributor_id', $sponsor->id)->sum('bv_paise');
    $cutoffResult = makeCreditedCutoff($sponsee, 1);

    app(MentorshipBonusService::class)->processForSponsee($sponsee->id, $cutoffResult);

    expect((int) BvLedgerEntry::where('distributor_id', $sponsor->id)->sum('bv_paise'))->toBe($bvBefore);
    expect((int) $cutoffResult->fresh()->gross_gsb_paise)->toBe(100_000);
});

it('reserves the slab\'s MSB points for a sponsee with an eligible sponsor', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    expect(app(MentorshipBonusService::class)->reservedPointsFor($sponsee->id, 3, today()))->toBe(15);
});

it('reserves nothing when the sponsor is under the minimum BV or there is no sponsor', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    $orphan = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);

    expect(app(MentorshipBonusService::class)->reservedPointsFor($sponsee->id, 3, today()))->toBe(0)
        ->and(app(MentorshipBonusService::class)->reservedPointsFor($orphan->id, 3, today()))->toBe(0);
});

// ── Repurchase gate (client 2026-10-09) ─────────────────────────────────────
// A sponsor below the royalty rank who is failed on their repurchase condition
// on the cut-off day earns no Mentorship points that day; from the royalty rank
// the accrual stands. The verdict is frozen on a `repurchase_gated` row (F-3)
// and the rank is the one decided before the cut-off's month (F-2).

/** @return array{0: Distributor, 1: Distributor} [sponsor, sponsee]; the sponsor holds the 600 BV minimum. */
function msbSponsorPair(): array
{
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);
    giveSponsorMinBv($sponsor);

    return [$sponsor, $sponsee];
}

/** A credited GSB cut-off for $sponsee on $date matching $slab. */
function msbCreditedCutoff(Distributor $sponsee, int $slab, string $date): GsbCutoffResult
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

/** A BV-short cycle due on $due, resolved the next day: forfeits every day from $due + 1. */
function msbSeedFailedCycle(Distributor $distributor, string $due): RepurchaseCycle
{
    $dueDate = Carbon::parse($due);

    return RepurchaseCycle::create([
        'distributor_id' => $distributor->id,
        'cycle_start_date' => $dueDate->copy()->subDays(29)->toDateString(),
        'due_date' => $dueDate->toDateString(),
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => 0,
        'status' => RepurchaseCycle::STATUS_SUSPENDED,
        'failure_reason' => RepurchaseCycle::REASON_BV_SHORT,
        'resolved_at' => $dueDate->copy()->addDay()->toDateTimeString(),
    ]);
}

function msbSeedRankQualification(int $distributorId, int $rank, string $monthStart): void
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

function msbFreezePoolOn(string $date, int $pointValuePaise, int $totalPoints = 60): MsbDailyPool
{
    $payout = $pointValuePaise * $totalPoints;

    return MsbDailyPool::create([
        'cutoff_date' => $date,
        'company_bv_paise' => $payout === 0 ? 0 : intdiv($payout * 10_000, 300),
        'pool_rate_bp' => 300,
        'pool_paise' => $payout,
        'total_points' => $totalPoints,
        'point_value_paise' => $pointValuePaise,
        'payout_paise' => $payout,
        'leftover_paise' => 0,
    ]);
}

function msbSetRoyaltyMinRank(string $value): void
{
    DB::table('settings')->updateOrInsert(['key' => 'comp.msb.royalty_min_rank'], ['value' => $value]);
    app()->forgetInstance(CompensationPlanSettingsService::class);
    app()->forgetInstance(MentorshipBonusService::class);
}

it('awards no MB points to a sponsor (rank ≤ 5) who is failed on the cut-off day, recording a gated row', function () {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    [$sponsor, $sponsee] = msbSponsorPair();
    msbSeedFailedCycle($sponsor, '2026-08-06');          // forfeits from 7 Aug
    msbSeedRankQualification($sponsor->id, 5, '2026-06-01');
    $pool = msbFreezePoolOn('2026-08-10', 10_000);

    $svc = app(MentorshipBonusService::class);
    $cutoff = msbCreditedCutoff($sponsee, 1, '2026-08-10');

    // Gated sponsors leave the day's denominator, exactly like the BV gate.
    expect($svc->reservedPointsFor($sponsee->id, 1, Carbon::parse('2026-08-10')))->toBe(0);

    $accrual = $svc->accrueForSponsee($sponsee->id, $cutoff);
    expect($accrual)->not->toBeNull()
        ->and($accrual->repurchaseGated)->toBeTrue()
        ->and($accrual->points)->toBe(21)
        ->and($accrual->countsInDenominator())->toBeFalse()
        ->and($accrual->gateReason)->toBe(RepurchaseCycle::REASON_BV_SHORT)
        ->and($accrual->sponsorRankAsOf)->toBe(5);

    $row = $svc->creditAccrual($accrual, $pool);

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe(MentorshipBonusResult::STATUS_REPURCHASE_GATED)
        ->and($row->msb_points)->toBe(21)
        ->and($row->msb_point_value_paise)->toBe(10_000)
        ->and($row->mb_gross_paise)->toBe(0)
        ->and($row->mb_net_paise)->toBe(0)
        ->and($row->repurchase_deduction_paise)->toBe(0)
        ->and($row->failure_reason)->toBe('bv_short')
        // F-3: a gated sponsor is by definition failed; the royalty cap never
        // touches a gated row.
        ->and($row->sponsor_repurchase_failed)->toBeTrue()
        ->and($row->sponsor_verdict_stale)->toBeFalse()
        ->and($row->royalty_cap_paise)->toBeNull()
        ->and($row->royalty_cap_withheld_paise)->toBe(0);
    expect(WalletLedgerEntry::where('distributor_id', $sponsor->id)->count())->toBe(0);

    $audit = DB::table('audit_log')->where('action', 'msb.credit.repurchase_gated')->sole();
    $details = json_decode((string) $audit->details, true);
    expect((int) $audit->subject_id)->toBe($sponsor->id)
        ->and($details['sponsor_id'])->toBe($sponsor->id)
        ->and($details['sponsee_id'])->toBe($sponsee->id)
        ->and($details['cutoff_date'])->toBe('2026-08-10')
        ->and($details['msb_points'])->toBe(21)
        ->and($details['slab'])->toBe(1)
        ->and($details['sponsor_rank_as_of'])->toBe(5)
        ->and($details['royalty_min_rank'])->toBe(6)
        ->and($details['verdict_reason'])->toBe('bv_short');

    // Idempotent: a re-run neither re-accrues nor writes a second gated row,
    // and the single-distributor path answers with the recorded verdict.
    expect($svc->accrueForSponsee($sponsee->id, $cutoff))->toBeNull();
    $again = $svc->processForSponsee($sponsee->id, $cutoff);
    expect($again?->id)->toBe($row->id);
    expect(MentorshipBonusResult::count())->toBe(1);
});

it('records a gated row even when the day has no frozen pool', function () {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    [$sponsor, $sponsee] = msbSponsorPair();
    msbSeedFailedCycle($sponsor, '2026-08-06');

    $row = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, msbCreditedCutoff($sponsee, 1, '2026-08-10'));

    expect($row?->status)->toBe(MentorshipBonusResult::STATUS_REPURCHASE_GATED)
        ->and($row->msb_point_value_paise)->toBeNull()
        ->and($row->mb_gross_paise)->toBe(0);
    expect(DB::table('audit_log')->where('action', 'msb.pool.missing')->exists())->toBeFalse();
});

it('still accrues for a failed sponsor at rank 6 or above (Mentorship Royalty)', function () {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    [$sponsor, $sponsee] = msbSponsorPair();
    msbSeedFailedCycle($sponsor, '2026-08-06');
    msbSeedRankQualification($sponsor->id, 6, '2026-07-01');
    $pool = msbFreezePoolOn('2026-08-10', 10_000);

    $svc = app(MentorshipBonusService::class);
    $accrual = $svc->accrueForSponsee($sponsee->id, msbCreditedCutoff($sponsee, 1, '2026-08-10'));

    expect($accrual->repurchaseGated)->toBeFalse()
        ->and($accrual->points)->toBe(21)
        ->and($accrual->countsInDenominator())->toBeTrue();

    $row = $svc->creditAccrual($accrual, $pool);
    expect($row->status)->toBe(MentorshipBonusResult::STATUS_CREDITED)
        ->and($row->mb_gross_paise)->toBe(210_000);
    expect(WalletLedgerEntry::where('distributor_id', $sponsor->id)->where('type', 'mb_credit')->count())->toBe(1);
});

it('keeps the royalty for life once Rank 6 is reached, even after lower-rank months', function () {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    [$sponsor, $sponsee] = msbSponsorPair();
    msbSeedFailedCycle($sponsor, '2026-08-06');
    // Rank 6 in May, then only Rank 3 in June and July (client 2026-10-10:
    // royalty for life, whatever the later months' rank).
    msbSeedRankQualification($sponsor->id, 6, '2026-05-01');
    msbSeedRankQualification($sponsor->id, 3, '2026-06-01');
    msbSeedRankQualification($sponsor->id, 3, '2026-07-01');

    expect(app(RepurchaseCycleService::class)->rankAsOf($sponsor->id, Carbon::parse('2026-08-10')))->toBe(6);

    $accrual = app(MentorshipBonusService::class)->accrueForSponsee($sponsee->id, msbCreditedCutoff($sponsee, 1, '2026-08-10'));

    expect($accrual->repurchaseGated)->toBeFalse()
        ->and($accrual->points)->toBe(21);
});

it('accrues normally for an eligible sponsor', function () {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    [, $sponsee] = msbSponsorPair();

    $svc = app(MentorshipBonusService::class);
    $accrual = $svc->accrueForSponsee($sponsee->id, msbCreditedCutoff($sponsee, 2, '2026-08-10'));

    expect($accrual->points)->toBe(18)
        ->and($accrual->repurchaseGated)->toBeFalse()
        ->and($svc->reservedPointsFor($sponsee->id, 2, Carbon::parse('2026-08-10')))->toBe(18);
});

it('judges the gate on the rank decided before the cut-off month — a later qualification never moves the day (F-2)', function () {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    [$sponsor, $sponsee] = msbSponsorPair();
    msbSeedFailedCycle($sponsor, '2026-08-06');
    msbSeedRankQualification($sponsor->id, 5, '2026-06-01');
    $day = Carbon::parse('2026-08-10');

    $gate = fn (): int => app(MentorshipBonusService::class)->reservedPointsFor($sponsee->id, 1, $day);
    $rankAsOf = fn (): int => app(RepurchaseCycleService::class)->rankAsOf($sponsor->id, $day);

    expect($rankAsOf())->toBe(5)->and($gate())->toBe(0);

    // August's Rank 6 is decided on 1 September: unknown on any August day.
    msbSeedRankQualification($sponsor->id, 6, '2026-08-01');
    expect($rankAsOf())->toBe(5)->and($gate())->toBe(0);
    // currentRank() is the lifetime maximum with no date — it would say 6 and
    // pay royalty on a replay of a day the live run gated.
    expect(app(RepurchaseCycleService::class)->currentRank($sponsor->id))->toBe(6);

    // July's Rank 6 was decided on 1 August: the 10th is royalty.
    msbSeedRankQualification($sponsor->id, 6, '2026-07-01');
    expect($rankAsOf())->toBe(6)->and($gate())->toBe(21);
});

it('does not gate a failed sponsor while the repurchase engine is off', function () {
    [$sponsor, $sponsee] = msbSponsorPair();
    msbSeedFailedCycle($sponsor, '2026-08-06');

    $svc = app(MentorshipBonusService::class);

    expect($svc->reservedPointsFor($sponsee->id, 1, Carbon::parse('2026-08-10')))->toBe(21)
        ->and($svc->accrueForSponsee($sponsee->id, msbCreditedCutoff($sponsee, 1, '2026-08-10'))->repurchaseGated)->toBeFalse();
});

it('switches the gate off when the royalty rank is set to 1', function () {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    [$sponsor, $sponsee] = msbSponsorPair();
    msbSeedFailedCycle($sponsor, '2026-08-06');
    msbSetRoyaltyMinRank('1');

    $accrual = app(MentorshipBonusService::class)->accrueForSponsee($sponsee->id, msbCreditedCutoff($sponsee, 1, '2026-08-10'));

    expect($accrual->repurchaseGated)->toBeFalse()->and($accrual->points)->toBe(21);
});

it('refuses to gate Mentorship with a royalty rank outside 1–9, writing nothing', function (string $value) {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    [$sponsor, $sponsee] = msbSponsorPair();
    msbSeedFailedCycle($sponsor, '2026-08-06');
    msbSetRoyaltyMinRank($value);

    expect(fn () => app(CompensationPlanSettingsService::class)->msbRoyaltyMinRank())
        ->toThrow(RuntimeException::class, 'comp.msb.royalty_min_rank must be between 1 and 9');

    // The nightly reads the setting when it warms the sponsors — before the
    // MSB pool freeze and before any MB, deferral or settle write — so the
    // whole run fails there (F-6), even on a night with nobody to warm.
    expect(fn () => app(MentorshipBonusService::class)->warmSponsorsFor([], Carbon::parse('2026-08-10')))
        ->toThrow(RuntimeException::class, 'comp.msb.royalty_min_rank must be between 1 and 9');

    $cutoff = msbCreditedCutoff($sponsee, 1, '2026-08-10');
    expect(fn () => app(MentorshipBonusService::class)->accrueForSponsee($sponsee->id, $cutoff))
        ->toThrow(RuntimeException::class);

    expect(MentorshipBonusResult::count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'msb.credit.repurchase_gated')->exists())->toBeFalse();
})->with(['zero' => '0', 'ten' => '10']);

it('reads the sponsors\' cycles and ranks once per warm, not once per accrual', function () {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $day = Carbon::parse('2026-08-10');

    $sponsees = [];
    foreach (range(1, 5) as $i) {
        $sponsor = Distributor::factory()->create();
        giveSponsorMinBv($sponsor);
        if ($i <= 2) {
            msbSeedFailedCycle($sponsor, '2026-08-06');
        }
        msbSeedRankQualification($sponsor->id, $i, '2026-06-01');

        foreach (range(1, 10) as $ignored) {
            $sponsee = Distributor::factory()->create();
            makeSponsorship($sponsor, $sponsee);
            $sponsees[] = (int) $sponsee->id;
        }
    }

    $svc = app(MentorshipBonusService::class);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $svc->warmSponsorsFor($sponsees, $day);

    $reserved = 0;
    foreach ($sponsees as $sponseeId) {
        $reserved += $svc->reservedPointsFor($sponseeId, 1, $day);
    }

    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // Two failed sponsors below rank 6 are gated: 30 sponsees × 21 points remain.
    expect($reserved)->toBe(30 * 21);
    expect($queries->filter(fn (string $q): bool => str_contains($q, 'repurchase_cycles'))->count())->toBeLessThanOrEqual(2)
        ->and($queries->filter(fn (string $q): bool => str_contains($q, 'rank_qualifications'))->count())->toBeLessThanOrEqual(2);

    $svc->forgetSponsors();
});

// ── Mentorship Royalty daily cap (client 2026-10-09) ────────────────────────
// From the royalty rank a failed sponsor keeps earning Mentorship, capped at
// ₹3,600 per cut-off day across all sponsees; the excess is withheld for good.
// Every row freezes the verdict it was judged with (F-3) and the cap it was
// priced with (F-5).

/** A further sponsee directly sponsored by $sponsor. */
function msbSponseeFor(Distributor $sponsor): Distributor
{
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);

    return $sponsee;
}

function msbSetRoyaltyFailedDailyCap(string $value): void
{
    DB::table('settings')->updateOrInsert(['key' => 'comp.msb.royalty_failed_daily_cap_paise'], ['value' => $value]);
    app()->forgetInstance(CompensationPlanSettingsService::class);
    app()->forgetInstance(MentorshipBonusService::class);
}

/**
 * A failed rank-6 sponsor with two sponsees and a ₹120-point day: each slab-1
 * sponsee is worth 21 × ₹120 = ₹2,520, so the second one overruns the ₹3,600 cap.
 *
 * @return array{0: Distributor, 1: Distributor, 2: Distributor, 3: MsbDailyPool} [sponsor, a, b, pool]
 */
function msbFailedRoyaltySponsor(bool $failed = true): array
{
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    [$sponsor, $a] = msbSponsorPair();
    $b = msbSponseeFor($sponsor);
    if ($failed) {
        msbSeedFailedCycle($sponsor, '2026-08-06');
    }
    msbSeedRankQualification($sponsor->id, 6, '2026-07-01');

    return [$sponsor, $a, $b, msbFreezePoolOn('2026-08-10', 12_000, 42)];
}

function msbCredit(Distributor $sponsee, MsbDailyPool $pool): ?MentorshipBonusResult
{
    $svc = app(MentorshipBonusService::class);

    return $svc->creditAccrual($svc->accrueForSponsee($sponsee->id, msbCreditedCutoff($sponsee, 1, '2026-08-10')), $pool);
}

it('caps a failed rank-6+ sponsor at ₹3,600 across all accruals of the day, withholding the rest', function () {
    [$sponsor, $a, $b, $pool] = msbFailedRoyaltySponsor();

    $r1 = msbCredit($a, $pool);   // 21 × ₹120 = ₹2,520
    $r2 = msbCredit($b, $pool);   // ₹2,520 → only ₹1,080 fits

    expect($r1->status)->toBe(MentorshipBonusResult::STATUS_CREDITED)
        ->and($r1->mb_gross_paise)->toBe(252_000)
        ->and($r1->royalty_cap_withheld_paise)->toBe(0)
        ->and($r1->royalty_cap_paise)->toBe(360_000)
        ->and($r1->sponsor_repurchase_failed)->toBeTrue()
        ->and($r1->sponsor_verdict_stale)->toBeFalse();
    expect($r2->status)->toBe(MentorshipBonusResult::STATUS_CREDITED)
        ->and($r2->mb_gross_paise)->toBe(108_000)
        ->and($r2->royalty_cap_withheld_paise)->toBe(144_000)
        ->and($r2->royalty_cap_paise)->toBe(360_000)
        ->and($r2->sponsor_repurchase_failed)->toBeTrue()
        // Cap first, then the 10% repurchase deduction on what is left.
        ->and($r2->repurchase_deduction_paise)->toBe(10_800)
        ->and($r2->mb_net_paise)->toBe(97_200);

    expect((int) WalletLedgerEntry::where('distributor_id', $sponsor->id)->where('type', 'mb_credit')->sum('amount_paise'))
        ->toBe(360_000);

    $audit = DB::table('audit_log')->where('action', 'msb.royalty.cap_withheld')->sole();
    $details = json_decode((string) $audit->details, true);
    expect((int) $audit->subject_id)->toBe($sponsor->id)
        ->and($details['cutoff_date'])->toBe('2026-08-10')
        ->and($details['sponsee_id'])->toBe($b->id)
        ->and($details['cap_paise'])->toBe(360_000)
        ->and($details['already_paid_paise'])->toBe(252_000)
        ->and($details['withheld_paise'])->toBe(144_000);
});

it('settles the cap to the same day total whichever sponsee is credited first (F-5)', function () {
    [, $a, $b, $pool] = msbFailedRoyaltySponsor();

    $first = msbCredit($b, $pool);
    $second = msbCredit($a, $pool);

    expect($first->mb_gross_paise)->toBe(252_000)
        ->and($second->mb_gross_paise)->toBe(108_000)
        ->and($second->royalty_cap_withheld_paise)->toBe(144_000);
    expect((int) MentorshipBonusResult::sum('mb_gross_paise'))->toBe(360_000)
        ->and((int) MentorshipBonusResult::sum('royalty_cap_withheld_paise'))->toBe(144_000);
});

it('withholds a further sponsee in full once the day\'s cap is spent, with no wallet entry', function () {
    [$sponsor, $a, $b, $pool] = msbFailedRoyaltySponsor();
    $c = msbSponseeFor($sponsor);

    msbCredit($a, $pool);
    msbCredit($b, $pool);
    $r3 = msbCredit($c, $pool);

    expect($r3->status)->toBe(MentorshipBonusResult::STATUS_CREDITED)
        ->and($r3->mb_gross_paise)->toBe(0)
        ->and($r3->royalty_cap_withheld_paise)->toBe(252_000);
    expect(WalletLedgerEntry::where('reference_type', 'mentorship_bonus_result')->where('reference_id', $r3->id)->exists())->toBeFalse();
    expect((int) WalletLedgerEntry::where('distributor_id', $sponsor->id)->where('type', 'mb_credit')->sum('amount_paise'))
        ->toBe(360_000);
    expect(DB::table('audit_log')->where('action', 'msb.royalty.cap_withheld')->count())->toBe(2);
});

it('does not cap an eligible rank-6 sponsor', function () {
    [$sponsor, $a, $b, $pool] = msbFailedRoyaltySponsor(failed: false);

    $r1 = msbCredit($a, $pool);
    $r2 = msbCredit($b, $pool);

    foreach ([$r1, $r2] as $row) {
        expect($row->mb_gross_paise)->toBe(252_000)
            ->and($row->royalty_cap_withheld_paise)->toBe(0)
            ->and($row->royalty_cap_paise)->toBeNull()
            ->and($row->sponsor_repurchase_failed)->toBeFalse();
    }
    expect((int) WalletLedgerEntry::where('distributor_id', $sponsor->id)->where('type', 'mb_credit')->sum('amount_paise'))
        ->toBe(504_000);
    expect(DB::table('audit_log')->where('action', 'msb.royalty.cap_withheld')->exists())->toBeFalse();
});

it('keeps each row\'s frozen cap when the setting changes mid-day (F-5)', function () {
    [, $a, $b, $pool] = msbFailedRoyaltySponsor();

    $r1 = msbCredit($a, $pool);
    msbSetRoyaltyFailedDailyCap('300000');
    $r2 = msbCredit($b, $pool);

    expect($r1->fresh()->royalty_cap_paise)->toBe(360_000)
        ->and($r1->fresh()->mb_gross_paise)->toBe(252_000);
    // Room = the NEW ₹3,000 cap − the ₹2,520 already paid.
    expect($r2->royalty_cap_paise)->toBe(300_000)
        ->and($r2->mb_gross_paise)->toBe(48_000)
        ->and($r2->royalty_cap_withheld_paise)->toBe(204_000);
});

it('refuses to credit a failed royalty sponsor with a cap under ₹1, writing nothing (F-6)', function (string $value) {
    [$sponsor, $a, , $pool] = msbFailedRoyaltySponsor();
    msbSetRoyaltyFailedDailyCap($value);

    expect(fn () => app(CompensationPlanSettingsService::class)->msbRoyaltyFailedDailyCapPaise())
        ->toThrow(RuntimeException::class, 'comp.msb.royalty_failed_daily_cap_paise must be');
    expect(fn () => msbCredit($a, $pool))->toThrow(RuntimeException::class);

    expect(MentorshipBonusResult::count())->toBe(0)
        ->and(WalletLedgerEntry::where('distributor_id', $sponsor->id)->exists())->toBeFalse()
        ->and(DB::table('audit_log')->where('action', 'msb.royalty.cap_withheld')->exists())->toBeFalse();
})->with(['zero' => '0', 'fifty' => '50']);

it('refuses the whole nightly when the royalty cap is under ₹1, before any MSB write (F-6)', function () {
    [, $a] = msbFailedRoyaltySponsor();
    msbSetRoyaltyFailedDailyCap('50');

    expect(fn () => app(MentorshipBonusService::class)->warmSponsorsFor([$a->id], Carbon::parse('2026-08-10')))
        ->toThrow(RuntimeException::class, 'comp.msb.royalty_failed_daily_cap_paise must be');
    expect(MentorshipBonusResult::count())->toBe(0);
});

it('marks a repurchase-gated row too when the sponsor\'s own evaluation was deferred that night (F-4)', function () {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    [$sponsor, $sponsee] = msbSponsorPair();
    msbSeedFailedCycle($sponsor, '2026-08-06');
    msbSeedRankQualification($sponsor->id, 5, '2026-06-01');
    GsbCutoffDeferral::create([
        'distributor_id' => $sponsor->id,
        'cutoff_date' => '2026-08-10',
        'cause' => GsbCutoffDeferral::CAUSE_EVALUATION_FAILED,
        'reserved_gsb_paise' => 0,
        'reserved_msb_points' => 0,
    ]);

    $row = app(MentorshipBonusService::class)->processForSponsee($sponsee->id, msbCreditedCutoff($sponsee, 1, '2026-08-10'));

    expect($row?->status)->toBe(MentorshipBonusResult::STATUS_REPURCHASE_GATED)
        ->and($row->sponsor_verdict_stale)->toBeTrue()
        ->and($row->sponsor_repurchase_failed)->toBeTrue();
});

it('marks the row when the sponsor\'s own evaluation was deferred that night (F-4)', function () {
    [$sponsor, $a, $b, $pool] = msbFailedRoyaltySponsor();

    $fresh = msbCredit($a, $pool);
    expect($fresh->sponsor_verdict_stale)->toBeFalse();

    GsbCutoffDeferral::create([
        'distributor_id' => $sponsor->id,
        'cutoff_date' => '2026-08-10',
        'cause' => GsbCutoffDeferral::CAUSE_EVALUATION_FAILED,
        'reserved_gsb_paise' => 0,
        'reserved_msb_points' => 0,
    ]);

    $svc = app(MentorshipBonusService::class);
    $accrual = $svc->accrueForSponsee($b->id, msbCreditedCutoff($b, 1, '2026-08-10'));
    expect($accrual->sponsorVerdictStale)->toBeTrue()
        ->and($accrual->sponsorRepurchaseFailed)->toBeTrue();

    $stale = $svc->creditAccrual($accrual, $pool);
    expect($stale->sponsor_verdict_stale)->toBeTrue()
        ->and($stale->sponsor_repurchase_failed)->toBeTrue();
});

// ── Warmed stale-verdict lookup (Task 14 L5) ────────────────────────────────
// The F-4 open-deferral lookup is read once per warmed night instead of once
// per accrual; a path that never warmed keeps the per-accrual query. Both give
// the same answer.

/**
 * Three eligible sponsors with two slab-1 sponsees each on 10 Aug; the first
 * sponsor's own evaluation is deferred that night when $deferFirst.
 *
 * @return array{0: list<Distributor>, 1: list<GsbCutoffResult>} [sponsors, cut-offs]
 */
function msbWarmNight(bool $deferFirst = true): array
{
    $sponsors = [];
    $cutoffs = [];

    foreach (range(1, 3) as $i) {
        $sponsor = Distributor::factory()->create();
        giveSponsorMinBv($sponsor);
        $sponsors[] = $sponsor;

        foreach (range(1, 2) as $ignored) {
            $cutoffs[] = msbCreditedCutoff(msbSponseeFor($sponsor), 1, '2026-08-10');
        }
    }

    if ($deferFirst) {
        msbDeferSponsor($sponsors[0]);
    }

    return [$sponsors, $cutoffs];
}

function msbDeferSponsor(Distributor $sponsor): void
{
    GsbCutoffDeferral::create([
        'distributor_id' => $sponsor->id,
        'cutoff_date' => '2026-08-10',
        'cause' => GsbCutoffDeferral::CAUSE_EVALUATION_FAILED,
        'reserved_gsb_paise' => 0,
        'reserved_msb_points' => 0,
    ]);
}

/**
 * Accrue every cut-off and return sponsee id → sponsorVerdictStale, plus how
 * many queries touched gsb_cutoff_deferrals while doing so.
 *
 * @param  list<GsbCutoffResult>  $cutoffs
 * @return array{0: array<int, bool>, 1: int}
 */
function msbAccrueStaleFlags(MentorshipBonusService $svc, array $cutoffs, ?callable $before = null): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    if ($before !== null) {
        $before();
    }

    $flags = [];
    foreach ($cutoffs as $cutoff) {
        $flags[(int) $cutoff->distributor_id] = $svc->accrueForSponsee((int) $cutoff->distributor_id, $cutoff)->sponsorVerdictStale;
    }

    $lookups = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $q): bool => str_contains($q, 'gsb_cutoff_deferrals'))
        ->count();
    DB::disableQueryLog();

    return [$flags, $lookups];
}

it('reads the open deferrals once per warmed night, not once per accrual, with the un-warmed answer (F-4)', function () {
    [$sponsors, $cutoffs] = msbWarmNight();
    $sponseeIds = array_map(fn (GsbCutoffResult $c): int => (int) $c->distributor_id, $cutoffs);
    $svc = app(MentorshipBonusService::class);

    [$warmed, $warmedLookups] = msbAccrueStaleFlags(
        $svc,
        $cutoffs,
        fn () => $svc->warmSponsorsFor($sponseeIds, Carbon::parse('2026-08-10')),
    );

    // One lookup for the whole night, whatever the number of sponsees.
    expect($warmedLookups)->toBe(1);

    $svc->forgetSponsors();
    [$fallback, $fallbackLookups] = msbAccrueStaleFlags($svc, $cutoffs);

    // The never-warmed path asks per accrual and reaches the same verdicts.
    expect($fallbackLookups)->toBe(count($cutoffs))
        ->and($warmed)->toBe($fallback)
        ->and(array_values($warmed))->toBe([true, true, false, false, false, false]);
});

it('sees a deferral written after the warm, as the nightly writes tonight\'s deferrals after warming (F-4)', function () {
    [$sponsors, $cutoffs] = msbWarmNight(deferFirst: false);
    $sponseeIds = array_map(fn (GsbCutoffResult $c): int => (int) $c->distributor_id, $cutoffs);
    $svc = app(MentorshipBonusService::class);

    $svc->warmSponsorsFor($sponseeIds, Carbon::parse('2026-08-10'));
    msbDeferSponsor($sponsors[1]);

    [$flags] = msbAccrueStaleFlags($svc, $cutoffs);

    expect(array_values($flags))->toBe([false, false, true, true, false, false]);

    $svc->forgetSponsors();
});

it('keeps the per-accrual lookup for a date the warm did not cover', function () {
    [, $cutoffs] = msbWarmNight();
    $svc = app(MentorshipBonusService::class);

    $svc->warmSponsorsFor([(int) $cutoffs[0]->distributor_id], Carbon::parse('2026-08-09'));
    [$flags, $lookups] = msbAccrueStaleFlags($svc, $cutoffs);

    expect($lookups)->toBe(count($cutoffs))
        ->and(array_values($flags))->toBe([true, true, false, false, false, false]);

    $svc->forgetSponsors();
});
