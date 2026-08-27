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
 *  - slabs 2-7 progress = today's fresh matched BV only (no carry-forward);
 *  - personal-purchase BV is NOT part of any side until the cut-off actually
 *    credits it (it is exposed separately as a pending figure).
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
        $pendingPersonalBvTopupPaise = 0;
        $pendingTopupSide = null;
        $powerCfPaise = 0;
        $powerCfSide = null;

        if ($eligible) {
            $today = Carbon::today('Asia/Kolkata')->toDateString();
            $daily = GroupBvDaily::where('distributor_id', $distributorId)
                ->whereDate('date', $today)
                ->first();
            $cf = GsbCarryforward::where('distributor_id', $distributorId)->first();

            // Opening balances for today's cut-off. Normally that is the rolling
            // gsb_carryforward store (last night's closing state). But when
            // today's cut-off has already run — the recompute catch-up owns the
            // in-flight period — the store already holds today's *closing*
            // state: adding it to today's daily BV would double-count the BV
            // that cut-off consumed. Rebuild the day's opening state from the
            // cut-off row's own *_before snapshot instead.
            $openingPowerCfPaise = $cf->power_side_bv_paise ?? 0;
            $openingPowerSide = $cf->power_side ?? null;
            $openingSlab1CfPaise = $cf->slab1_weaker_bv_paise ?? 0;

            $todayCutoff = GsbCutoffResult::query()
                ->where('distributor_id', $distributorId)
                ->whereDate('cutoff_date', $today)
                ->orderBy('id')
                ->get()
                ->first(fn (GsbCutoffResult $result): bool => $result->advancedCarryForward());

            if ($todayCutoff !== null) {
                $openingPowerCfPaise = $todayCutoff->power_cf_before_paise;
                $openingPowerSide = $todayCutoff->power_side_before;
                $openingSlab1CfPaise = $todayCutoff->slab1_weaker_cf_before_paise;
            }

            $leftEffective = ($daily->left_bv_paise ?? 0)
                + ($openingPowerSide === 'L' ? $openingPowerCfPaise : 0);
            $rightEffective = ($daily->right_bv_paise ?? 0)
                + ($openingPowerSide === 'R' ? $openingPowerCfPaise : 0);
            $slab1Cf = $openingSlab1CfPaise;
            // The carry-forward cards ("remaining after your last slab match" /
            // tomorrow's opening balance) always show the rolling store.
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
                // Not yet applied. Personal-purchase BV must NOT join the carry over
                // before the cut-off (client, 2026-08-25): GsbCutoffService credits it
                // to the weaker leg at 23:59, and only when a leg has touched the
                // smallest slab (incl. CF). So it is surfaced as a separate *pending*
                // figure and deliberately left out of the effective side totals, the
                // matched BV and the ladder progress. Tie ⇒ weaker = Right.
                $minSlabMatched = $this->plan->gsbMinSlabMatchedBvPaise();
                if ($minSlabMatched > 0 && max($leftEffective, $rightEffective) >= $minSlabMatched) {
                    $pendingBv = $this->topup->pendingBvPaise($distributorId, Carbon::parse($today));
                    if ($pendingBv > 0) {
                        $pendingPersonalBvTopupPaise = $pendingBv;
                        $pendingTopupSide = $leftEffective < $rightEffective ? 'L' : 'R';
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
            pendingPersonalBvTopupPaise: $pendingPersonalBvTopupPaise,
            pendingTopupSide: $pendingTopupSide,
            // Zero on the ineligible path, exactly like left/right effective:
            // below the personal-BV minimum nothing is counted for the ladder.
            slab1WeakerCfPaise: $slab1Cf,
            powerCfPaise: $powerCfPaise,
            powerCfSide: $powerCfSide,
        );
    }
}
