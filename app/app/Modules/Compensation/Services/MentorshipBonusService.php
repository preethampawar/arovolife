<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compensation\Models\MsbDailyPool;
use App\Modules\Compensation\Services\DTOs\MsbAccrual;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Computes and credits the Mentorship Bonus for a sponsee's GSB cut-off result.
 *
 * Daily pool engine (KP 2026-07-30, replacing the per-slab fixed ₹250 point
 * value): when a directly sponsored sponsee's cut-off credits GSB slab N, the
 * sponsor accrues that slab's msb_score points (21/18/15/12/9/6/3). Once every
 * distributor has settled, the day's MSB pool (3% of company BV) is divided by
 * the day's total accrued points to give ONE point value for everybody, and
 * each sponsor is paid points × that value. The points and the value are
 * snapshotted per row so later plan edits never change history.
 *
 * The work is therefore split in two so the caller can sum the denominator in
 * between:
 *
 *   accrueForSponsee()  — pure, zero writes, returns the points owed
 *   creditAccrual()     — all writes, prices against the frozen day pool
 *   processForSponsee() — the two composed, for single-distributor retries
 *
 * Nothing about an accrual is persisted before it is credited. That is safe
 * because accruals are deterministically reconstructible: a crash mid-run is
 * recovered by re-running the whole day, where already-settled cut-offs return
 * their credited rows, already-paid sponsors are skipped by the idempotency
 * check below, and the idempotent pool freeze returns the original frozen row —
 * so the surviving accruals price at exactly the same value.
 *
 * The repurchase deduction is taken at credit time — Mentorship is the fifth
 * deduction source (client, 2026-09-10) — and is frozen on the result row.
 * Admin charge and TDS are applied at payout time, not at credit time.
 */
final class MentorshipBonusService
{
    /**
     * Ids per `whereIn` while warming: a placeholder each, and MySQL refuses a
     * prepared statement past 65,535 of them (R-90).
     */
    private const WARM_CHUNK = 500;

    public function __construct(
        private readonly WalletService $wallet,
        private readonly BvLedgerService $bvLedger,
        private readonly CompensationPlanSettingsService $plan,
        private readonly MsbDailyPoolService $pools,
        private readonly IncomeEligibilityService $eligibility,
        private readonly RepurchaseCycleService $cycles,
    ) {}

    /**
     * Warm, on THIS instance, everything the repurchase gate reads for the
     * sponsors of $sponseeIds on $date: their repurchase cycles and their rank
     * as of the date. One sponsorship query, one cycle query and one rank query
     * per chunk instead of three per accrual. Strict accelerators — an id not
     * warmed falls through to the live query.
     *
     * Also the nightly's first read of the royalty-rank setting: a value outside
     * 1–9 throws here, before the MSB pool is frozen or any MB row is written, so
     * the run fails whole (F-6) instead of one sponsor's accrual failing inside
     * the per-accrual catch while everyone else is priced without them.
     *
     * @param  array<int, int>  $sponseeIds
     *
     * @throws \RuntimeException when comp.msb.royalty_min_rank is outside 1–9
     */
    public function warmSponsorsFor(array $sponseeIds, Carbon $date): void
    {
        // Start clean: the command is a process-lifetime singleton under the
        // scheduler and the recompute replay, and an exception escaping the
        // previous run (e.g. the MSB cap refusing to price) would otherwise
        // leave that run's cycles and ranks here to be read as this run's.
        $this->forgetSponsors();

        $this->plan->msbRoyaltyMinRank();

        if ($sponseeIds === []) {
            return;
        }

        $sponsorIds = [];

        foreach (array_chunk($sponseeIds, self::WARM_CHUNK) as $chunk) {
            foreach (DB::table('sponsorship')->whereIn('distributor_id', $chunk)->pluck('sponsor_id') as $sponsorId) {
                if ($sponsorId !== null) {
                    $sponsorIds[(int) $sponsorId] = (int) $sponsorId;
                }
            }
        }

        $sponsorIds = array_values($sponsorIds);

        if ($sponsorIds === []) {
            return;
        }

        if ($this->eligibility->engineActive()) {
            $this->eligibility->warmCycleCache($sponsorIds);
        }

        $this->cycles->warmRanksAsOf($sponsorIds, $date);
    }

    /** Release what {@see warmSponsorsFor()} loaded. */
    public function forgetSponsors(): void
    {
        $this->eligibility->forgetCycleCache();
        $this->cycles->forgetRanksAsOf();
    }

    /**
     * Compute (without writing) what the sponsor of $sponseeId is owed for this
     * cut-off, in MSB score points. Returns null — and so contributes nothing
     * to the day's denominator — if the sponsee has no sponsor, did not earn
     * GSB, the sponsor is below the personal-BV gate, the matched slab carries
     * no MSB points, or the sponsor was already credited (or gated) for this
     * date.
     *
     * A sponsor below the royalty rank who is failed on their repurchase
     * condition on the cut-off day is NOT null: the accrual comes back with
     * repurchaseGated = true, so creditAccrual() records the verdict on a row
     * while countsInDenominator() keeps the points out of the day's divisor.
     */
    public function accrueForSponsee(int $sponseeId, GsbCutoffResult $cutoffResult): ?MsbAccrual
    {
        if ($cutoffResult->status !== GsbCutoffResult::STATUS_CREDITED || $cutoffResult->slab === null) {
            return null;
        }

        $owed = $this->sponsorPointsFor($sponseeId, (int) $cutoffResult->slab, $cutoffResult->cutoff_date);

        if ($owed === null) {
            return null;
        }

        if ($this->existingCredit($sponseeId, $cutoffResult, $owed['points']) !== null) {
            return null;
        }

        return new MsbAccrual(
            sponsorId: $owed['sponsor_id'],
            sponseeId: $sponseeId,
            slab: (int) $cutoffResult->slab,
            points: $owed['points'],
            sponseeGsbPaise: (int) $cutoffResult->gross_gsb_paise,
            cutoffDate: $cutoffResult->cutoff_date->toDateString(),
            repurchaseGated: $owed['gated'],
            gateReason: $owed['reason'],
            sponsorRankAsOf: $owed['rank_as_of'],
        );
    }

    /**
     * The MSB points a sponsee's matched slab would earn their sponsor, from a
     * computation rather than a credited row — what the full cut-off reserves
     * in the day's denominator for a distributor whose settle it defers.
     * 0 when the sponsee has no sponsor, the sponsor is under the min BV, the
     * slab carries no MSB points, or the sponsor is repurchase-gated on
     * $cutoffDate; the same gates accrueForSponsee() applies.
     */
    public function reservedPointsFor(int $sponseeId, int $slab, Carbon $cutoffDate): int
    {
        $owed = $this->sponsorPointsFor($sponseeId, $slab, $cutoffDate);

        return $owed === null || $owed['gated'] ? 0 : $owed['points'];
    }

    /**
     * The sponsor, the points they are owed for the sponsee matching $slab and
     * the repurchase gate's verdict for $cutoffDate, or null when any of the
     * other gates shuts it.
     *
     * @return array{sponsor_id: int, points: int, gated: bool, reason: string|null, rank_as_of: int|null}|null
     */
    private function sponsorPointsFor(int $sponseeId, int $slab, Carbon $cutoffDate): ?array
    {
        // Look up the sponsee's sponsor.
        $sponsorId = DB::table('sponsorship')
            ->where('distributor_id', $sponseeId)
            ->value('sponsor_id');

        if ($sponsorId === null) {
            return null;
        }

        // Sponsor must have minimum personal BV to be eligible for any bonus.
        // Sponsors who fail this gate are deliberately excluded from the day's
        // denominator: they can never be paid for this day, so counting their
        // points would dilute the pool for everyone who can.
        $sponsorBvPaise = $this->bvLedger->totalPersonalBvPaise((int) $sponsorId);

        if ($sponsorBvPaise < $this->plan->gsbMinBvPaise()) {
            return null;
        }

        $slabRow = $this->plan->gsbSlab($slab);
        $points = (int) ($slabRow['msb_score'] ?? 0);

        if ($points <= 0) {
            return null;
        }

        // Client 2026-10-09: a sponsor who is failed on their repurchase
        // condition on the cut-off day earns nothing from their sponsees' slabs
        // — unless they hold the royalty rank (6+), where the accrual stands and
        // only the daily royalty cap applies at credit time (creditAccrual()).
        // Failed sub-royalty sponsors leave the day's denominator like the BV
        // gate above: they can never be paid for the day. The rank is the one
        // decided BEFORE the cut-off's month (F-2), so a later qualification
        // never changes the answer for a day already judged.
        // Task 4 records `sponsor_verdict_stale` when the sponsor has an open
        // gsb_cutoff_deferrals row for the date (F-4).
        $verdict = $this->eligibility->verdictAsOf((int) $sponsorId, $cutoffDate);
        $gated = false;
        $rankAsOf = null;

        if (! $verdict->isEligible()) {
            $royaltyMinRank = $this->plan->msbRoyaltyMinRank();
            $rankAsOf = $this->cycles->rankAsOf((int) $sponsorId, $cutoffDate);
            // 1 is the documented off switch (F-12: everyone is royalty), and
            // that includes sponsors who have never ranked (rank 0).
            $gated = $royaltyMinRank > 1 && $rankAsOf < $royaltyMinRank;
        }

        return [
            'sponsor_id' => (int) $sponsorId,
            'points' => $points,
            'gated' => $gated,
            'reason' => $verdict->reason,
            'rank_as_of' => $rankAsOf,
        ];
    }

    /**
     * Price an accrual against the day's frozen pool and credit it.
     *
     * A null pool means the date was never frozen — a cut-off that ran before
     * the Mentorship feature was switched on, or a single-distributor run on a
     * date the nightly never covered. There is no fixed per-slab value left to
     * fall back on, so nothing is credited and the gap is logged for an
     * operator to re-run the day.
     */
    public function creditAccrual(MsbAccrual $accrual, ?MsbDailyPool $pool): ?MentorshipBonusResult
    {
        // A gated sponsor is recorded whether or not the day has a pool: the
        // row is the frozen answer to "why was I not paid on this day" (F-3).
        if ($accrual->repurchaseGated) {
            return $this->recordGated($accrual, $pool);
        }

        if ($pool === null) {
            $details = [
                'sponsor_id' => $accrual->sponsorId,
                'sponsee_id' => $accrual->sponseeId,
                'cutoff_date' => $accrual->cutoffDate,
                'msb_points' => $accrual->points,
            ];
            Log::warning('msb.pool.missing', $details);
            // A sponsor going unpaid is a statutory record, not just a log line:
            // application logs rotate, audit_log is retained.
            AuditLog::create([
                'action' => 'msb.pool.missing',
                'subject_type' => 'distributor',
                'subject_id' => $accrual->sponsorId,
                'details' => $details,
            ]);

            return null;
        }

        $pointValuePaise = (int) $pool->point_value_paise;
        $mbGross = $accrual->points * $pointValuePaise;

        // A ₹0 point value is legitimate: the day's pool was starved, or nobody
        // had accrued points when it was frozen and this is a later retry. The
        // row is still written so the report shows the points were budgeted,
        // but no wallet entry is created for a zero amount.
        if ($pointValuePaise <= 0) {
            $details = [
                'sponsor_id' => $accrual->sponsorId,
                'sponsee_id' => $accrual->sponseeId,
                'cutoff_date' => $accrual->cutoffDate,
                'msb_points' => $accrual->points,
                'pool_paise' => $pool->pool_paise,
                'total_points' => $pool->total_points,
            ];
            Log::warning('msb.credit.zero_value', $details);
            // Points earned but worth nothing that day — retained as a record.
            AuditLog::create([
                'action' => 'msb.credit.zero_value',
                'subject_type' => 'distributor',
                'subject_id' => $accrual->sponsorId,
                'details' => $details,
            ]);
        }

        // Row + wallet credit in one transaction: two overlapping runs can both
        // pass the accrue-time idempotency check, and it is the unique index
        // uniq_mb_result(sponsor_id, sponsee_id, cutoff_date) that stops the
        // second one — the surrounding transaction is what makes that rollback
        // atomic instead of leaving an orphan wallet entry behind.
        return DB::transaction(function () use ($accrual, $pointValuePaise, $mbGross): MentorshipBonusResult {
            $result = MentorshipBonusResult::create([
                'sponsor_id' => $accrual->sponsorId,
                'sponsee_id' => $accrual->sponseeId,
                'cutoff_date' => $accrual->cutoffDate,
                'sponsee_gsb_paise' => $accrual->sponseeGsbPaise,
                'slab' => $accrual->slab,
                'msb_points' => $accrual->points,
                'msb_point_value_paise' => $pointValuePaise,
                'mb_rate_pct' => null,
                'mb_gross_paise' => $mbGross,
                'mb_admin_charge_paise' => 0,
                'mb_tds_paise' => 0,
                'sponsee_cumulative_gsb_paise' => null,
                'status' => MentorshipBonusResult::STATUS_CREDITED,
            ]);

            if ($mbGross > 0) {
                $outcome = $this->wallet->creditWithRepurchaseDeduction(
                    distributorId: $accrual->sponsorId,
                    grossPaise: $mbGross,
                    bonusType: 'mb_credit',
                    referenceId: $result->id,
                    referenceType: 'mentorship_bonus_result',
                    // MB is the fifth repurchase-deduction source (client,
                    // 2026-09-10) and one of the five bonuses under the monthly
                    // income cap; both window on the month the income was
                    // earned for, not the month it is written in.
                    bonusMonth: Carbon::parse($accrual->cutoffDate)->startOfMonth(),
                    // The cut-off DAY: Mentorship rides the same Wednesday→
                    // Tuesday earning week as GSB (spec §3, assumption A3).
                    earnedOn: Carbon::parse($accrual->cutoffDate),
                );

                // Freeze what was withheld and what actually landed: the pages
                // read this row, never the ledger.
                $result->update([
                    'repurchase_deduction_paise' => $outcome->repurchaseDeductionPaise,
                    'mb_net_paise' => $outcome->creditedPaise(),
                ]);
            }

            return $result;
        });
    }

    /**
     * Write the `repurchase_gated` row for a sponsor below the royalty rank who
     * was failed on the cut-off day: the points that would have accrued, at ₹0,
     * with the verdict's reason, plus its audit row. No wallet entry.
     */
    private function recordGated(MsbAccrual $accrual, ?MsbDailyPool $pool): MentorshipBonusResult
    {
        $royaltyMinRank = $this->plan->msbRoyaltyMinRank();

        return DB::transaction(function () use ($accrual, $pool, $royaltyMinRank): MentorshipBonusResult {
            $result = MentorshipBonusResult::create([
                'sponsor_id' => $accrual->sponsorId,
                'sponsee_id' => $accrual->sponseeId,
                'cutoff_date' => $accrual->cutoffDate,
                'sponsee_gsb_paise' => $accrual->sponseeGsbPaise,
                'slab' => $accrual->slab,
                'msb_points' => $accrual->points,
                'msb_point_value_paise' => $pool?->point_value_paise,
                'mb_rate_pct' => null,
                'mb_gross_paise' => 0,
                'repurchase_deduction_paise' => 0,
                'mb_admin_charge_paise' => 0,
                'mb_tds_paise' => 0,
                'mb_net_paise' => 0,
                'sponsee_cumulative_gsb_paise' => null,
                'status' => MentorshipBonusResult::STATUS_REPURCHASE_GATED,
                'failure_reason' => $accrual->gateReason,
            ]);

            AuditLog::create([
                'action' => 'msb.credit.repurchase_gated',
                'subject_type' => 'distributor',
                'subject_id' => $accrual->sponsorId,
                'details' => [
                    'sponsor_id' => $accrual->sponsorId,
                    'sponsee_id' => $accrual->sponseeId,
                    'cutoff_date' => $accrual->cutoffDate,
                    'msb_points' => $accrual->points,
                    'slab' => $accrual->slab,
                    'sponsor_rank_as_of' => (int) $accrual->sponsorRankAsOf,
                    'royalty_min_rank' => $royaltyMinRank,
                    'verdict_reason' => $accrual->gateReason,
                ],
            ]);

            return $result;
        });
    }

    /**
     * Accrue and credit in one call, pricing against the date's already-frozen
     * pool. This is the single-distributor path (CLI `--distributor=N` and the
     * admin retry); it never freezes a pool, because one distributor's points
     * are not the day's denominator.
     */
    public function processForSponsee(int $sponseeId, GsbCutoffResult $cutoffResult): ?MentorshipBonusResult
    {
        $accrual = $this->accrueForSponsee($sponseeId, $cutoffResult);

        if ($accrual === null) {
            // Either nothing is owed, or it was already paid or gated — in which
            // case return the existing row so a retry reports what was recorded.
            return $this->recordedRowFor($sponseeId, $cutoffResult);
        }

        return $this->creditAccrual($accrual, $this->pools->poolForDate($cutoffResult->cutoff_date));
    }

    /**
     * An MB row already recorded for this sponsee/date — credited, or gated by
     * the repurchase rule — if any. A gated row is final: the verdict it was
     * judged with stands, and a re-run must not write a second row.
     *
     * A re-run against a DIFFERENT slab (admin corrected the GSB cut-off after
     * MB was credited) cannot be silently absorbed — the wallet credit already
     * went out at the old points. Surface it for manual reconciliation.
     */
    private function existingCredit(int $sponseeId, GsbCutoffResult $cutoffResult, int $points): ?MentorshipBonusResult
    {
        $alreadyCredited = $this->recordedRowFor($sponseeId, $cutoffResult);

        if ($alreadyCredited === null) {
            return null;
        }

        if ($alreadyCredited->status === MentorshipBonusResult::STATUS_CREDITED
            && $alreadyCredited->msb_points !== null
            && (int) $alreadyCredited->msb_points !== $points) {
            $mismatch = [
                'sponsor_id' => $alreadyCredited->sponsor_id,
                'sponsee_id' => $sponseeId,
                'cutoff_date' => $cutoffResult->cutoff_date->toDateString(),
                'credited_msb_points' => (int) $alreadyCredited->msb_points,
                'current_slab_msb_points' => $points,
            ];
            Log::warning('mb.credit.points_mismatch', $mismatch);
            AuditLog::create([
                'action' => 'mb.credit.points_mismatch',
                'subject_type' => 'mentorship_bonus_result',
                'subject_id' => $alreadyCredited->id,
                'details' => $mismatch,
            ]);
        }

        return $alreadyCredited;
    }

    /** The credited or repurchase-gated MB row for this sponsee/date, if one exists. */
    private function recordedRowFor(int $sponseeId, GsbCutoffResult $cutoffResult): ?MentorshipBonusResult
    {
        return MentorshipBonusResult::where('sponsee_id', $sponseeId)
            ->whereDate('cutoff_date', $cutoffResult->cutoff_date->toDateString())
            ->whereIn('status', [MentorshipBonusResult::STATUS_CREDITED, MentorshipBonusResult::STATUS_REPURCHASE_GATED])
            ->first();
    }
}
