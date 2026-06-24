<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use Illuminate\Support\Facades\DB;

/**
 * Computes and credits the Mentorship Bonus for a sponsee's GSB cut-off result.
 *
 * Rate ladder: starts at 10%, drops 1% per ₹30,000 (3,000,000 paise) of
 * cumulative GSB earned by the sponsee, floors at 1% permanently.
 * Each sponsor-sponsee pair is tracked independently.
 */
final class MentorshipBonusService
{
    /** Rate step: ₹30,000 cumulative GSB = 3,000,000 paise per rate decrement. */
    private const STEP_PAISE = 3_000_000;

    public function __construct(private readonly WalletService $wallet) {}

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

        // Cumulative sponsee GSB seen by this sponsor-sponsee pair (from previous MB results).
        $prevCumulative = (int) MentorshipBonusResult::where('sponsor_id', $sponsorId)
            ->where('sponsee_id', $sponseeId)
            ->max('sponsee_cumulative_gsb_paise') ?? 0;

        $newCumulative = $prevCumulative + $cutoffResult->gross_gsb_paise;

        // Rate = 10% - (floor(prevCumulative / 30K step) × 1%), minimum 1%.
        $stepsCompleted = (int) floor($prevCumulative / self::STEP_PAISE);
        $rate = max(1, 10 - $stepsCompleted);

        $mbPaise = (int) round($cutoffResult->gross_gsb_paise * $rate / 100);

        $result = MentorshipBonusResult::create([
            'sponsor_id' => $sponsorId,
            'sponsee_id' => $sponseeId,
            'cutoff_date' => $cutoffResult->cutoff_date->toDateString(),
            'sponsee_gsb_paise' => $cutoffResult->gross_gsb_paise,
            'mb_rate_pct' => $rate,
            'mb_paise' => $mbPaise,
            'sponsee_cumulative_gsb_paise' => $newCumulative,
            'status' => MentorshipBonusResult::STATUS_CREDITED,
        ]);

        $this->wallet->credit(
            distributorId: (int) $sponsorId,
            amountPaise: $mbPaise,
            type: 'mb_credit',
            referenceId: $result->id,
            referenceType: 'mentorship_bonus_result',
        );

        return $result;
    }
}
