<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Computes and credits the Mentorship Bonus for a sponsee's GSB cut-off result.
 *
 * Rate ladder (tax-slab style, per KP 2026-06-27): 10% on the first ₹30,000 of
 * the sponsee's cumulative GSB, dropping 1% per further ₹30,000 (3,000,000
 * paise) down to a 1% floor. A single GSB income is split across whatever
 * brackets it spans — e.g. a sponsee at ₹0 earning ₹45,000 yields
 * 10%×₹30,000 + 9%×₹15,000 = ₹4,350. Each sponsor-sponsee pair is tracked
 * independently; all three figures (start, step, floor) are admin-editable.
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
            // A re-run with a DIFFERENT sponsee gross (admin corrected the GSB
            // cut-off after MB was credited) cannot be silently absorbed — the
            // delta would compound into every later bracket via the cumulative
            // tracker. Surface it for manual reconciliation.
            if ((int) $alreadyCredited->sponsee_gsb_paise !== (int) $cutoffResult->gross_gsb_paise) {
                Log::warning('mb.credit.gross_mismatch', [
                    'sponsor_id' => $alreadyCredited->sponsor_id,
                    'sponsee_id' => $sponseeId,
                    'cutoff_date' => $cutoffResult->cutoff_date->toDateString(),
                    'credited_sponsee_gsb_paise' => (int) $alreadyCredited->sponsee_gsb_paise,
                    'current_sponsee_gsb_paise' => (int) $cutoffResult->gross_gsb_paise,
                ]);
            }

            return $alreadyCredited;
        }

        // Cumulative sponsee GSB seen by this sponsor-sponsee pair (from previous MB results).
        $prevCumulative = (int) MentorshipBonusResult::where('sponsor_id', $sponsorId)
            ->where('sponsee_id', $sponseeId)
            ->max('sponsee_cumulative_gsb_paise') ?? 0;

        $income = (int) $cutoffResult->gross_gsb_paise;
        $newCumulative = $prevCumulative + $income;

        // Split this income across the cumulative-GSB rate brackets (tax-slab
        // style). mb_rate_pct records the blended effective rate for display —
        // it equals the single bracket rate when the income doesn't cross a
        // ₹30,000 boundary (the common case).
        $mbGross = $this->mentorshipGross($prevCumulative, $income);
        $rate = $income > 0 ? (int) round($mbGross * 100 / $income) : 0;

        return DB::transaction(function () use ($sponsorId, $sponseeId, $cutoffResult, $rate, $mbGross, $newCumulative): MentorshipBonusResult {
            $result = MentorshipBonusResult::create([
                'sponsor_id' => $sponsorId,
                'sponsee_id' => $sponseeId,
                'cutoff_date' => $cutoffResult->cutoff_date->toDateString(),
                'sponsee_gsb_paise' => $cutoffResult->gross_gsb_paise,
                'mb_rate_pct' => $rate,
                'mb_gross_paise' => $mbGross,
                'mb_admin_charge_paise' => 0,
                'mb_tds_paise' => 0,
                'sponsee_cumulative_gsb_paise' => $newCumulative,
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

    /**
     * Mentorship Bonus on a single GSB income, split across the sponsee's
     * cumulative-GSB rate brackets (tax-slab style). Each step (default ₹30,000)
     * of lifetime GSB lowers the rate 1% from the start rate to the floor.
     *
     * @param  int  $priorCumulativePaise  sponsee's cumulative GSB before this income
     * @param  int  $incomePaise  this cut-off's gross GSB (paise)
     * @return int MB gross in paise
     */
    private function mentorshipGross(int $priorCumulativePaise, int $incomePaise): int
    {
        if ($incomePaise <= 0) {
            return 0;
        }

        $step = $this->plan->mbStepPaise();
        $startRate = $this->plan->mbStartRatePct();
        $floorRate = $this->plan->mbFloorRatePct();

        // Defensive: a non-positive step would break bracketing — bill at start rate.
        if ($step <= 0) {
            return (int) round($incomePaise * $startRate / 100);
        }

        $gross = 0.0;
        $pos = $priorCumulativePaise;
        $remaining = $incomePaise;

        while ($remaining > 0) {
            $rate = max($floorRate, $startRate - intdiv($pos, $step));

            // Once at the floor the rate never drops further — bill the rest at floor.
            if ($rate <= $floorRate) {
                $gross += $remaining * $floorRate / 100;
                break;
            }

            $bracketEnd = (intdiv($pos, $step) + 1) * $step;
            $slice = min($remaining, $bracketEnd - $pos);
            $gross += $slice * $rate / 100;
            $pos += $slice;
            $remaining -= $slice;
        }

        return (int) round($gross);
    }
}
