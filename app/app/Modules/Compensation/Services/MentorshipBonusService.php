<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compensation\Models\MsbDailyPool;
use App\Modules\Compensation\Services\DTOs\MsbAccrual;
use App\Modules\Compliance\Models\AuditLog;
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
 * Deductions (admin charge, TDS) are applied at payout time, not at credit time.
 */
final class MentorshipBonusService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly BvLedgerService $bvLedger,
        private readonly CompensationPlanSettingsService $plan,
        private readonly MsbDailyPoolService $pools,
    ) {}

    /**
     * Compute (without writing) what the sponsor of $sponseeId is owed for this
     * cut-off, in MSB score points. Returns null — and so contributes nothing
     * to the day's denominator — if the sponsee has no sponsor, did not earn
     * GSB, the sponsor is below the personal-BV gate, the matched slab carries
     * no MSB points, or the sponsor was already credited for this date.
     */
    public function accrueForSponsee(int $sponseeId, GsbCutoffResult $cutoffResult): ?MsbAccrual
    {
        if ($cutoffResult->status !== GsbCutoffResult::STATUS_CREDITED || $cutoffResult->slab === null) {
            return null;
        }

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

        $slabRow = $this->plan->gsbSlab((int) $cutoffResult->slab);
        $points = $slabRow['msb_score'] ?? 0;

        if ($points <= 0) {
            return null;
        }

        if ($this->existingCredit($sponseeId, $cutoffResult, $points) !== null) {
            return null;
        }

        return new MsbAccrual(
            sponsorId: (int) $sponsorId,
            sponseeId: $sponseeId,
            slab: (int) $cutoffResult->slab,
            points: $points,
            sponseeGsbPaise: (int) $cutoffResult->gross_gsb_paise,
            cutoffDate: $cutoffResult->cutoff_date->toDateString(),
        );
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
                $this->wallet->credit(
                    distributorId: $accrual->sponsorId,
                    amountPaise: $mbGross,
                    type: 'mb_credit',
                    referenceId: $result->id,
                    referenceType: 'mentorship_bonus_result',
                );
            }

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
            // Either nothing is owed, or it was already paid — in which case
            // return the existing row so a retry reports what was credited.
            return $this->creditedRowFor($sponseeId, $cutoffResult);
        }

        return $this->creditAccrual($accrual, $this->pools->poolForDate($cutoffResult->cutoff_date));
    }

    /**
     * An MB row already credited for this sponsee/date, if any.
     *
     * A re-run against a DIFFERENT slab (admin corrected the GSB cut-off after
     * MB was credited) cannot be silently absorbed — the wallet credit already
     * went out at the old points. Surface it for manual reconciliation.
     */
    private function existingCredit(int $sponseeId, GsbCutoffResult $cutoffResult, int $points): ?MentorshipBonusResult
    {
        $alreadyCredited = $this->creditedRowFor($sponseeId, $cutoffResult);

        if ($alreadyCredited === null) {
            return null;
        }

        if ($alreadyCredited->msb_points !== null && (int) $alreadyCredited->msb_points !== $points) {
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

    /** The credited MB row for this sponsee/date, if one exists. */
    private function creditedRowFor(int $sponseeId, GsbCutoffResult $cutoffResult): ?MentorshipBonusResult
    {
        return MentorshipBonusResult::where('sponsee_id', $sponseeId)
            ->whereDate('cutoff_date', $cutoffResult->cutoff_date->toDateString())
            ->where('status', MentorshipBonusResult::STATUS_CREDITED)
            ->first();
    }
}
