<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use Illuminate\Support\Facades\DB;

/**
 * Computes and credits the Mentorship Bonus for a sponsee's GSB cut-off result.
 *
 * Rate ladder: starts at 10%, drops 1% per ₹30,000 (3,000,000 paise) of
 * cumulative GSB earned by the sponsee, floors at 1% permanently.
 * Each sponsor-sponsee pair is tracked independently.
 *
 * Deductions applied before wallet credit (same as GSB):
 *   - Admin charge: 3% of gross MB, capped at ₹30,000.
 *   - TDS: 5% of (gross − admin charge).
 */
final class MentorshipBonusService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly BvLedgerService $bvLedger,
        private readonly CompensationPlanSettingsService $plan,
        private readonly BonusDeductionService $deductions,
    ) {}

    /**
     * Compute and credit MB to the sponsor of $sponseeId for today's cut-off.
     * Returns null if the sponsee has no sponsor, or the sponsee did not earn GSB.
     */
    public function processForSponsee(int $sponseeId, GsbCutoffResult $cutoffResult): ?MentorshipBonusResult
    {
        if ($cutoffResult->status !== GsbCutoffResult::STATUS_CREDITED) {
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

        // Idempotency: if already credited for this cutoff date, return existing row.
        $alreadyCredited = MentorshipBonusResult::where('sponsee_id', $sponseeId)
            ->whereDate('cutoff_date', $cutoffResult->cutoff_date->toDateString())
            ->where('status', MentorshipBonusResult::STATUS_CREDITED)
            ->first();

        if ($alreadyCredited !== null) {
            return $alreadyCredited;
        }

        // Cumulative sponsee GSB seen by this sponsor-sponsee pair (from previous MB results).
        $prevCumulative = (int) MentorshipBonusResult::where('sponsor_id', $sponsorId)
            ->where('sponsee_id', $sponseeId)
            ->max('sponsee_cumulative_gsb_paise') ?? 0;

        $newCumulative = $prevCumulative + $cutoffResult->gross_gsb_paise;

        // Rate = start% − (floor(prevCumulative / step) × 1%), floored at the
        // configured lifetime minimum. All three figures are admin-editable.
        $stepsCompleted = (int) floor($prevCumulative / $this->plan->mbStepPaise());
        $rate = max($this->plan->mbFloorRatePct(), $this->plan->mbStartRatePct() - $stepsCompleted);

        $mbGross = (int) round($cutoffResult->gross_gsb_paise * $rate / 100);
        $deduction = $this->deductions->for(BonusType::Mentorship, $mbGross);
        $adminCharge = $deduction->adminChargePaise;
        $tds = $deduction->tdsPaise;
        $mbNet = $deduction->netPaise;

        return DB::transaction(function () use ($sponsorId, $sponseeId, $cutoffResult, $rate, $mbGross, $adminCharge, $tds, $mbNet, $newCumulative): MentorshipBonusResult {
            $result = MentorshipBonusResult::create([
                'sponsor_id' => $sponsorId,
                'sponsee_id' => $sponseeId,
                'cutoff_date' => $cutoffResult->cutoff_date->toDateString(),
                'sponsee_gsb_paise' => $cutoffResult->gross_gsb_paise,
                'mb_rate_pct' => $rate,
                'mb_gross_paise' => $mbGross,
                'mb_admin_charge_paise' => $adminCharge,
                'mb_tds_paise' => $tds,
                'mb_paise' => $mbNet,
                'sponsee_cumulative_gsb_paise' => $newCumulative,
                'status' => MentorshipBonusResult::STATUS_CREDITED,
            ]);

            $this->wallet->credit(
                distributorId: (int) $sponsorId,
                amountPaise: $mbNet,
                type: 'mb_credit',
                referenceId: $result->id,
                referenceType: 'mentorship_bonus_result',
            );

            return $result;
        });
    }
}
