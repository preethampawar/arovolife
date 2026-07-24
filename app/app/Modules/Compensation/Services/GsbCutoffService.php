<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class GsbCutoffService
{
    /** @var array<int,bool> Batch-loaded frozen status keyed by distributor_id. */
    private array $frozenCache = [];

    public function __construct(
        private readonly PersonalBvTitleService $titleService,
        private readonly WalletService $wallet,
        private readonly BvLedgerService $bvLedger,
        private readonly CompensationPlanSettingsService $plan,
        private readonly IncomeEligibilityService $eligibility,
        private readonly GsbPersonalBvTopupService $topup,
    ) {}

    /**
     * Pre-load per-distributor data so the per-distributor loop fires bulk
     * queries instead of N individual ones. Call this once before the loop in
     * {@see GsbDailyCutoffCommand}. Safe to skip — each dependency falls back to
     * a live query when its cache entry is absent.
     *
     * @param  Collection<int,Distributor>  $distributors
     */
    public function warmBatch(Collection $distributors): void
    {
        $ids = $distributors->map(fn (Distributor $d) => (int) $d->id)->all();

        foreach ($distributors as $distributor) {
            $this->frozenCache[(int) $distributor->id] = $distributor->gsb_frozen_at !== null;
        }

        $this->bvLedger->warmPersonalBvCache($ids);

        if ($this->eligibility->engineActive()) {
            $this->eligibility->warmCycleCache($ids);
        }
    }

    /**
     * Run (or re-run) the 23:59 cut-off for one distributor on one date.
     * Idempotent: if a 'credited' result already exists for this date, return it unchanged.
     */
    public function runForDistributor(int $distributorId, Carbon $date): GsbCutoffResult
    {
        // Idempotency: never double-credit.
        $existing = GsbCutoffResult::where('distributor_id', $distributorId)
            ->whereDate('cutoff_date', $date->toDateString())
            ->first();

        if ($existing !== null && $existing->status === GsbCutoffResult::STATUS_CREDITED) {
            return $existing;
        }

        // Resolve frozen status from batch cache; fall back to a live query for
        // single-distributor admin retry runs that skip warmBatch().
        $isFrozen = array_key_exists($distributorId, $this->frozenCache)
            ? $this->frozenCache[$distributorId]
            : Distributor::where('id', $distributorId)->value('gsb_frozen_at') !== null;

        // Eligibility gate: configurable minimum personal BV (default 600 BV).
        $personalBvPaise = $this->bvLedger->totalPersonalBvPaise($distributorId);

        if ($personalBvPaise < $this->plan->gsbMinBvPaise()) {
            return $this->saveResult($existing, [
                'distributor_id' => $distributorId,
                'cutoff_date' => $date->toDateString(),
                'left_bv_paise' => 0,
                'right_bv_paise' => 0,
                'weaker_bv_paise' => 0,
                'gross_gsb_paise' => 0,
                'admin_charge_paise' => 0,
                'tds_paise' => 0,
                'net_gsb_paise' => 0,
                'power_cf_before_paise' => 0,
                'power_cf_after_paise' => 0,
                'slab1_weaker_cf_before_paise' => 0,
                'slab1_weaker_cf_after_paise' => 0,
                'status' => GsbCutoffResult::STATUS_BELOW_600BV,
            ]);
        }

        $title = $this->titleService->forBvPaise($personalBvPaise);

        // Today's accumulated group BV (may be 0 if no orders in their group today).
        $dailyBv = GroupBvDaily::where('distributor_id', $distributorId)
            ->whereDate('date', $date->toDateString())
            ->first();

        $leftToday = $dailyBv?->left_bv_paise ?? 0;
        $rightToday = $dailyBv?->right_bv_paise ?? 0;

        // Carry-forward state (create row if this distributor's first cut-off).
        $cf = GsbCarryforward::firstOrCreate(
            ['distributor_id' => $distributorId],
            ['power_side_bv_paise' => 0, 'power_side' => null, 'slab1_weaker_bv_paise' => 0],
        );

        // Re-run of an already-processed date. The rolling CF store has already
        // absorbed this date's outcome, so recomputing against it would compound
        // today's BV into CF a second time (observed in the wild when a manual
        // run followed the 00:10 scheduled run). Rewind the in-memory store to
        // the before-state recorded on the existing row — the recompute below
        // then lands on identical numbers, keeping "Retry is safe" true.
        if ($existing !== null && $existing->advancedCarryForward()) {
            // Out-of-order guard: if a later date was already processed, the
            // store also contains THAT day's BV — rewinding to this row's
            // before-state would silently erase it. Reprocessing history must
            // go through the recalculate flow, oldest date first.
            $laterRunExists = GsbCutoffResult::where('distributor_id', $distributorId)
                ->whereDate('cutoff_date', '>', $date->toDateString())
                ->exists();
            if ($laterRunExists) {
                throw new \RuntimeException(
                    "Cannot re-run the {$date->toDateString()} cut-off for distributor {$distributorId}: "
                    .'a later cut-off already advanced the carry-forward store. '
                    .'Use Recalculate CF and reprocess dates oldest-first.'
                );
            }

            $cf->power_side_bv_paise = $existing->power_cf_before_paise;
            $cf->slab1_weaker_bv_paise = $existing->slab1_weaker_cf_before_paise;
            // Legacy rows predate power_side_before; fall back to the store's
            // current side (only wrong if the run flipped the power side).
            $cf->power_side = $existing->power_side_before ?? $cf->power_side;
        }

        // Snapshot the pre-run side now that $cf holds the true before-state;
        // saved on the result row so a future re-run can rewind side-accurately.
        $powerSideBefore = $cf->power_side;

        // Add power CF to the side it belongs to.
        $leftEffective = $leftToday + ($cf->power_side === 'L' ? $cf->power_side_bv_paise : 0);
        $rightEffective = $rightToday + ($cf->power_side === 'R' ? $cf->power_side_bv_paise : 0);

        // Conditional personal-BV weaker-leg top-up (KP 2026-07-21). Accumulated
        // personal purchase BV is credited to the weaker leg ONLY on a cut-off
        // where a leg's effective BV (incl. CF) has already touched the smallest
        // slab threshold — otherwise it stays pending for a future day. On a tie
        // the Left leg is the stronger/power side, so the weaker (top-up) side is
        // Right. Idempotent: a re-run finds nothing pending (orders already topped
        // up) and lands on identical numbers.
        $minSlabMatched = $this->plan->gsbMinSlabMatchedBvPaise();
        if ($minSlabMatched > 0 && max($leftEffective, $rightEffective) >= $minSlabMatched) {
            $topupWeakerSide = $leftEffective < $rightEffective ? 'L' : 'R';
            $creditedTopup = $this->topup->applyPendingForDistributor($distributorId, $date, $topupWeakerSide);

            if ($creditedTopup > 0) {
                // The top-up incremented today's weaker-side accumulator by exactly
                // $creditedTopup — mirror it locally so matching sees the new BV.
                if ($topupWeakerSide === 'L') {
                    $leftToday += $creditedTopup;
                    $leftEffective += $creditedTopup;
                } else {
                    $rightToday += $creditedTopup;
                    $rightEffective += $creditedTopup;
                }
            }
        }

        // Stronger/weaker determination. Tie ⇒ Left is the stronger/power side
        // (KP 2026-07-21 tie-break): its excess carries forward, Right settles to 0.
        if ($leftEffective >= $rightEffective) {
            $strongerSide = 'L';
            $strongerEffective = $leftEffective;
            $weakerEffective = $rightEffective;
        } else {
            $strongerSide = 'R';
            $strongerEffective = $rightEffective;
            $weakerEffective = $leftEffective;
        }

        // Slab 1 carry-forward is lifetime-accumulated (spec: "no daily cutoff, no time limit").
        // Slabs 2–7 use fresh weaker BV ONLY (spec: "no carry-forward; calculated fresh each day").
        // $weakerTotal is computed once here — also used by the no-match path to accumulate CF.
        $weakerTotal = $weakerEffective + $cf->slab1_weaker_bv_paise;

        $matchedSlab = null;

        // Slabs 7→2: fresh weaker BV only — slab1 CF must NOT apply here.
        // Inactive slabs (e.g. slab 7 while its bonus is TBD) are skipped.
        foreach ([7, 6, 5, 4, 3, 2] as $slabIndex) {
            $slabRow = $this->plan->gsbSlab($slabIndex);
            if ($slabRow === null || ! $slabRow['is_active'] || $slabRow['bonus_paise'] === null) {
                continue;
            }
            $threshold = $slabRow['matched_bv_paise'];
            $incentive = $slabRow['bonus_paise'];
            if ($slabIndex <= $title->maxGsbSlab && $weakerEffective >= $threshold) {
                $matchedSlab = ['index' => $slabIndex, 'threshold' => $threshold, 'incentive' => $incentive, 'score' => $slabRow['score']];
                break;
            }
        }

        // Slab 1: lifetime accumulation (includes today's fresh + historical CF).
        // Both the accumulated weaker-side total AND the stronger side (effective) must
        // reach the 15,000 BV threshold — "15K/15K" requires both Genos sides to qualify.
        // Slab 1 earning is unlocked from the GSB minimum (600 BV), NOT the
        // Retailer title (3,000 BV). A 600–2,999 BV distributor earns slab 1 only
        // (slabs 2–7 above still gate on $title->maxGsbSlab, which is 0 below
        // Retailer). The resulting income is held in the web account until the
        // Retailer title unlocks bank release — see PayoutService::neftMinBvPaise.
        // Reaching this line already guarantees personalBvPaise >= gsbMinBvPaise
        // (the below-600 path returns early above), so this gate is explicit for
        // safety rather than newly restrictive.
        $slab1 = $this->plan->gsbSlab(1);
        if ($matchedSlab === null
            && $slab1 !== null
            && $slab1['is_active']
            && $slab1['bonus_paise'] !== null
            && $personalBvPaise >= $this->plan->gsbMinBvPaise()
            && $weakerTotal >= $slab1['matched_bv_paise']
            && $strongerEffective >= $slab1['matched_bv_paise']) {
            $matchedSlab = ['index' => 1, 'threshold' => $slab1['matched_bv_paise'], 'incentive' => $slab1['bonus_paise'], 'score' => $slab1['score']];
        }

        if ($matchedSlab === null) {
            // No match — update carry-forward: weaker accumulates for slab 1, power carries forward.
            $newPowerCf = min($strongerEffective, $this->plan->gsbPowerCfCapPaise());
            $newSlab1Cf = $weakerTotal;  // accumulates until 15K matched

            $cfBeforePower = $cf->power_side_bv_paise;
            $cfBeforeSlab1 = $cf->slab1_weaker_bv_paise;

            $cf->update([
                'power_side_bv_paise' => $newPowerCf,
                'power_side' => $strongerSide,
                'slab1_weaker_bv_paise' => $newSlab1Cf,
            ]);

            return $this->saveResult($existing, [
                'distributor_id' => $distributorId,
                'cutoff_date' => $date->toDateString(),
                'left_bv_paise' => $leftToday,
                'right_bv_paise' => $rightToday,
                'weaker_bv_paise' => $weakerTotal,
                'power_cf_before_paise' => $cfBeforePower,
                'power_side_before' => $powerSideBefore,
                'power_cf_after_paise' => $newPowerCf,
                'power_side_after' => $strongerSide,
                'slab1_weaker_cf_before_paise' => $cfBeforeSlab1,
                'slab1_weaker_cf_after_paise' => $newSlab1Cf,
                'status' => GsbCutoffResult::STATUS_NO_MATCH,
            ]);
        }

        // Slab matched. Deductions (admin charge, TDS) are applied at payout time.
        $gross = $matchedSlab['incentive'];
        $adminCharge = 0;
        $tds = 0;

        $newPowerCf = min(
            max(0, $strongerEffective - $matchedSlab['threshold']),
            $this->plan->gsbPowerCfCapPaise(),
        );

        $cfBeforePower = $cf->power_side_bv_paise;
        $cfBeforeSlab1 = $cf->slab1_weaker_bv_paise;

        $baseData = [
            'distributor_id' => $distributorId,
            'cutoff_date' => $date->toDateString(),
            'left_bv_paise' => $leftToday,
            'right_bv_paise' => $rightToday,
            'weaker_bv_paise' => $weakerTotal,
            'slab' => $matchedSlab['index'],
            'score' => $matchedSlab['score'],
            'gross_gsb_paise' => $gross,
            'admin_charge_paise' => $adminCharge,
            'tds_paise' => $tds,
            'net_gsb_paise' => $gross,
            'power_cf_before_paise' => $cfBeforePower,
            'power_side_before' => $powerSideBefore,
            'power_cf_after_paise' => $newPowerCf,
            'power_side_after' => $strongerSide,
            'slab1_weaker_cf_before_paise' => $cfBeforeSlab1,
            'slab1_weaker_cf_after_paise' => 0,
        ];

        // Frozen distributors: calculate but do not credit wallet.
        // Advance CF identically to the no-match path so stale slab1 BV doesn't
        // phantom-accumulate during the freeze and double-credit on unfreeze.
        // Transactional: if the result row fails to persist (e.g. a schema
        // mismatch), the CF mutation must roll back with it or the matched BV
        // is silently lost.
        if ($isFrozen) {
            return DB::transaction(function () use ($cf, $newPowerCf, $strongerSide, $existing, $baseData): GsbCutoffResult {
                $cf->update([
                    'power_side_bv_paise' => $newPowerCf,
                    'power_side' => $strongerSide,
                    'slab1_weaker_bv_paise' => 0,
                ]);

                return $this->saveResult($existing, [
                    ...$baseData,
                    'status' => GsbCutoffResult::STATUS_FROZEN,
                ]);
            });
        }

        // Repurchase engine (flag-gated): if the distributor missed their
        // repurchase, calculate but do not credit — held during grace,
        // suspended after. CF is advanced identically to the frozen path so the
        // weaker side doesn't phantom-accumulate while income is withheld.
        // (Mentorship is unaffected: a held/suspended sponsee simply generates
        // no credited GSB, while the distributor's own MB comes from their
        // sponsees and is never gated here.)
        $eligibility = $this->eligibility->statusFor($distributorId, BonusType::Gsb);
        if ($eligibility !== IncomeEligibilityService::ELIGIBLE) {
            return DB::transaction(function () use ($cf, $newPowerCf, $strongerSide, $existing, $baseData, $eligibility): GsbCutoffResult {
                $cf->update([
                    'power_side_bv_paise' => $newPowerCf,
                    'power_side' => $strongerSide,
                    'slab1_weaker_bv_paise' => 0,
                ]);

                return $this->saveResult($existing, [
                    ...$baseData,
                    'status' => $eligibility === IncomeEligibilityService::HOLD
                        ? GsbCutoffResult::STATUS_REPURCHASE_HELD
                        : GsbCutoffResult::STATUS_REPURCHASE_SUSPENDED,
                ]);
            });
        }

        // Credit wallet inside a transaction — CF update is atomic with the wallet credit.
        try {
            $savedResult = null;

            DB::transaction(function () use ($distributorId, $gross, $baseData, $existing, $cf, $strongerSide, $newPowerCf, &$savedResult): void {
                // Move carry-forward update inside the transaction so it rolls back if credit fails.
                $cf->update([
                    'power_side_bv_paise' => $newPowerCf,
                    'power_side' => $strongerSide,
                    'slab1_weaker_bv_paise' => 0,
                ]);

                $savedResult = $this->saveResult($existing, [
                    ...$baseData,
                    'status' => GsbCutoffResult::STATUS_CALCULATED,
                ]);

                $this->wallet->credit(
                    distributorId: $distributorId,
                    amountPaise: $gross,
                    type: 'gsb_credit',
                    referenceId: $savedResult->id,
                    referenceType: 'gsb_cutoff_result',
                );

                // STATUS_CALCULATED is transient; the daily command should treat past-date CALCULATED rows as failed on restart.
                $savedResult->update(['status' => GsbCutoffResult::STATUS_CREDITED]);
            });
        } catch (Throwable $e) {
            return $this->saveResult($existing, [
                ...$baseData,
                'slab1_weaker_cf_after_paise' => $cfBeforeSlab1,  // CF was NOT zeroed (transaction rolled back)
                'status' => GsbCutoffResult::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
            ]);
        }

        return GsbCutoffResult::where('distributor_id', $distributorId)
            ->whereDate('cutoff_date', $date->toDateString())
            ->firstOrFail();
    }

    /**
     * Fields that must never survive from a previous run of the same date.
     * A re-run that lands on NO_MATCH / BELOW_600BV would otherwise leave a
     * stale slab + gross from an earlier FAILED/CALCULATED attempt, and
     * reports keying off gross_gsb_paise would show phantom income.
     */
    private const VOLATILE_FIELD_RESETS = [
        'slab' => null,
        'score' => null,
        'gross_gsb_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_gsb_paise' => 0,
        'failure_reason' => null,
    ];

    private function saveResult(?GsbCutoffResult $existing, array $data): GsbCutoffResult
    {
        $data = [...self::VOLATILE_FIELD_RESETS, ...$data];

        if ($existing !== null) {
            $existing->fill($data)->save();

            return $existing->fresh();
        }

        return GsbCutoffResult::create($data);
    }
}
