<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\GsbPersonalBvTopup;
use App\Modules\Compensation\Services\DTOs\GsbSlabProgress;
use App\Modules\Compensation\Services\DTOs\GsbSlabRow;
use Illuminate\Support\Carbon;

/**
 * Builds the distributor-facing GSB slab ladder: every active slab with its
 * matched-BV threshold and title requirement, how many times the distributor
 * has earned it (credited cut-offs only), and their live progress toward the
 * next unearned slab.
 *
 * Read-only mirror of GsbCutoffService's measurement rules — thresholds and
 * effective BV must be computed exactly the way the cut-off will compute them
 * tonight, never re-invented:
 *  - power CF joins today's BV on its own side;
 *  - slab 1 progress = lifetime weaker CF + today's matched BV, capped by the
 *    other side (15K/15K requires both sides);
 *  - slabs 2-7 progress = today's fresh matched BV only (no carry-forward).
 */
final class GsbSlabProgressService
{
    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
        private readonly BvLedgerService $bvLedger,
        private readonly PersonalBvTitleService $titleService,
        private readonly GsbPersonalBvTopupService $topup,
    ) {}

    public function forDistributor(int $distributorId): GsbSlabProgress
    {
        $personalBvPaise = $this->bvLedger->totalPersonalBvPaise($distributorId);
        $gsbMinBvPaise = $this->plan->gsbMinBvPaise();
        $eligible = $personalBvPaise >= $gsbMinBvPaise;
        $title = $this->titleService->forBvPaise($personalBvPaise);

        $leftEffective = 0;
        $rightEffective = 0;
        $slab1Cf = 0;
        $personalBvTopupPaise = 0;
        $topupSide = null;
        $powerCfPaise = 0;
        $powerCfSide = null;

        if ($eligible) {
            $today = Carbon::today('Asia/Kolkata')->toDateString();
            $daily = GroupBvDaily::where('distributor_id', $distributorId)
                ->whereDate('date', $today)
                ->first();
            $cf = GsbCarryforward::where('distributor_id', $distributorId)->first();

            $leftEffective = ($daily->left_bv_paise ?? 0)
                + ($cf !== null && $cf->power_side === 'L' ? $cf->power_side_bv_paise : 0);
            $rightEffective = ($daily->right_bv_paise ?? 0)
                + ($cf !== null && $cf->power_side === 'R' ? $cf->power_side_bv_paise : 0);
            $slab1Cf = $cf->slab1_weaker_bv_paise ?? 0;
            $powerCfPaise = $cf->power_side_bv_paise ?? 0;
            $powerCfSide = $cf->power_side ?? null;

            // Preview the conditional personal-BV weaker-leg topup (KP 2026-07-21).
            // Topups already applied today are in the accumulator; expose them.
            $appliedTopups = GsbPersonalBvTopup::where('distributor_id', $distributorId)
                ->whereDate('date', $today)
                ->whereNull('reversed_at')
                ->get(['side', 'bv_paise']);

            if ($appliedTopups->isNotEmpty()) {
                $personalBvTopupPaise = (int) $appliedTopups->sum('bv_paise');
                $topupSide = $appliedTopups->first()->side;
            } else {
                // Not yet applied. The cut-off will credit the full pending pool to
                // the weaker leg ONLY if a leg has touched the smallest slab (incl.
                // CF). Preview only when that trigger is met; tie ⇒ weaker = Right.
                $minSlabMatched = $this->plan->gsbMinSlabMatchedBvPaise();
                if ($minSlabMatched > 0 && max($leftEffective, $rightEffective) >= $minSlabMatched) {
                    $pendingBv = $this->topup->pendingBvPaise($distributorId, Carbon::parse($today));
                    if ($pendingBv > 0) {
                        $topupSide = $leftEffective < $rightEffective ? 'L' : 'R';
                        $personalBvTopupPaise = $pendingBv;
                        // Reflect the imminent credit in the effective weaker side so
                        // the ladder progress/next-target mirror tonight's measurement.
                        if ($topupSide === 'L') {
                            $leftEffective += $pendingBv;
                        } else {
                            $rightEffective += $pendingBv;
                        }
                    }
                }
            }
        }

        $weakerEffective = min($leftEffective, $rightEffective);
        $strongerEffective = max($leftEffective, $rightEffective);
        $slab1Progress = min($weakerEffective + $slab1Cf, $strongerEffective);
        // Tie ⇒ Right is the weaker side (the engine treats Left as power).
        $weakerIsLeft = $leftEffective < $rightEffective;

        /** @var array<int, int> $earnedCounts slab => number of credited cut-offs */
        $earnedCounts = GsbCutoffResult::query()
            ->where('distributor_id', $distributorId)
            ->where('status', GsbCutoffResult::STATUS_CREDITED)
            ->whereNotNull('slab')
            ->selectRaw('slab, COUNT(*) as cnt')
            ->groupBy('slab')
            ->pluck('cnt', 'slab')
            ->map(fn ($cnt): int => (int) $cnt)
            ->all();

        $highestEarnedSlab = $earnedCounts === [] ? null : max(array_keys($earnedCounts));

        $rows = [];
        $nextAssigned = false;

        foreach ($this->plan->gsbSlabs() as $slabRow) {
            if (! $slabRow['is_active']) {
                continue;
            }

            $slab = $slabRow['slab'];
            $threshold = $slabRow['matched_bv_paise'];
            $earnedCount = $earnedCounts[$slab] ?? 0;
            $isNext = ! $nextAssigned && $earnedCount === 0;
            $nextAssigned = $nextAssigned || $isNext;

            $progress = $slab === 1 ? $slab1Progress : $weakerEffective;

            // Per-side split of the same measurement: only slab 1 carries the
            // side-less weaker accumulator, and only onto the weaker side, so
            // min(left, right) reproduces $progress on every rung.
            $slab1CfForSlab = $slab === 1 ? $slab1Cf : 0;
            $leftProgress = $leftEffective + ($weakerIsLeft ? $slab1CfForSlab : 0);
            $rightProgress = $rightEffective + ($weakerIsLeft ? 0 : $slab1CfForSlab);

            $rows[] = new GsbSlabRow(
                slab: $slab,
                titleRequired: $slabRow['title'],
                titleMinBvPaise: $slabRow['title_min_bv_paise'],
                matchedBvPaise: $threshold,
                earnedCount: $earnedCount,
                lockedByTitle: $slab > $title->maxGsbSlab,
                isNext: $isNext,
                progressPaise: $progress,
                remainingPaise: max(0, $threshold - $progress),
                leftProgressPaise: $leftProgress,
                rightProgressPaise: $rightProgress,
            );
        }

        return new GsbSlabProgress(
            rows: $rows,
            genosBvEligible: $eligible,
            gsbMinBvPaise: $gsbMinBvPaise,
            leftEffectivePaise: $leftEffective,
            rightEffectivePaise: $rightEffective,
            title: $title->title,
            titleMaxSlab: $title->maxGsbSlab,
            highestEarnedSlab: $highestEarnedSlab,
            personalBvTopupPaise: $personalBvTopupPaise,
            topupSide: $topupSide,
            // Zero on the ineligible path, exactly like left/right effective:
            // below the personal-BV minimum nothing is counted for the ladder.
            slab1WeakerCfPaise: $slab1Cf,
            powerCfPaise: $powerCfPaise,
            powerCfSide: $powerCfSide,
        );
    }
}
