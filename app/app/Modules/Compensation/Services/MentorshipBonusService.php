<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Computes and credits the Mentorship Bonus for a sponsee's GSB cut-off result.
 *
 * Points engine (KP 2026-07-25, replacing the 10%→1% cumulative-GSB rate
 * ladder): when a directly sponsored sponsee's cut-off credits GSB slab N, the
 * sponsor earns that slab's msb_score points (21/18/15/12/9/6/3), each worth
 * the slab's msb_score_value_paise (default ₹250, per-slab admin-configurable).
 * MB gross = points × value, snapshotted per row so later admin edits never
 * change history.
 *
 * Deductions (admin charge, TDS) are applied at payout time, not at credit time.
 */
final class MentorshipBonusService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly BvLedgerService $bvLedger,
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    /**
     * Compute and credit MB to the sponsor of $sponseeId for today's cut-off.
     * Returns null if the sponsee has no sponsor, the sponsee did not earn GSB,
     * or the matched slab carries no MSB points.
     */
    public function processForSponsee(int $sponseeId, GsbCutoffResult $cutoffResult): ?MentorshipBonusResult
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
        $sponsorBvPaise = $this->bvLedger->totalPersonalBvPaise((int) $sponsorId);

        if ($sponsorBvPaise < $this->plan->gsbMinBvPaise()) {
            return null;
        }

        $slabRow = $this->plan->gsbSlab((int) $cutoffResult->slab);
        $points = $slabRow['msb_score'] ?? 0;
        $pointValuePaise = $slabRow['msb_score_value_paise'] ?? 0;

        // Idempotency: if already credited for this cutoff date, return existing row.
        $alreadyCredited = MentorshipBonusResult::where('sponsee_id', $sponseeId)
            ->whereDate('cutoff_date', $cutoffResult->cutoff_date->toDateString())
            ->where('status', MentorshipBonusResult::STATUS_CREDITED)
            ->first();

        if ($alreadyCredited !== null) {
            // A re-run against a DIFFERENT slab (admin corrected the GSB cut-off
            // after MB was credited) cannot be silently absorbed — the wallet
            // credit already went out at the old points. Surface it for manual
            // reconciliation.
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

        if ($points <= 0 || $pointValuePaise <= 0) {
            return null;
        }

        $mbGross = $points * $pointValuePaise;

        return DB::transaction(function () use ($sponsorId, $sponseeId, $cutoffResult, $points, $pointValuePaise, $mbGross): MentorshipBonusResult {
            $result = MentorshipBonusResult::create([
                'sponsor_id' => $sponsorId,
                'sponsee_id' => $sponseeId,
                'cutoff_date' => $cutoffResult->cutoff_date->toDateString(),
                'sponsee_gsb_paise' => $cutoffResult->gross_gsb_paise,
                'slab' => $cutoffResult->slab,
                'msb_points' => $points,
                'msb_point_value_paise' => $pointValuePaise,
                'mb_rate_pct' => null,
                'mb_gross_paise' => $mbGross,
                'mb_admin_charge_paise' => 0,
                'mb_tds_paise' => 0,
                'sponsee_cumulative_gsb_paise' => null,
                'status' => MentorshipBonusResult::STATUS_CREDITED,
            ]);

            $this->wallet->credit(
                distributorId: (int) $sponsorId,
                amountPaise: $mbGross,
                type: 'mb_credit',
                referenceId: $result->id,
                referenceType: 'mentorship_bonus_result',
            );

            return $result;
        });
    }
}
