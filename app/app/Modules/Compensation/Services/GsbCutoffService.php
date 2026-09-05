<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\GsbDailyPool;
use App\Modules\Compensation\Services\DTOs\GsbCutoffComputation;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\GsbDailyPoolPricingFeature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * The nightly GSB cut-off, split into three seams (KP 2026-07-29 pool pricing):
 *
 *  1. computeForDistributor() — pure, zero writes: legs, carry-forward rewind,
 *     top-up simulation, slab match.
 *  2. price() — assigns the gross once the day's pool economics are known
 *     (slabs 1–2 fixed, slabs 3–7 at the frozen daily pool value).
 *  3. settle() — every write: top-up application, carry-forward advance,
 *     result row, wallet credit.
 *
 * GsbDailyCutoffCommand runs pass 1 over all distributors, freezes the day's
 * pool, then passes 2+3. runForDistributor() composes all three for
 * single-distributor paths (admin retry) — it never creates or recomputes a
 * pool row, so retries always price against the frozen day value.
 */
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
        private readonly GsbDailyPoolService $pool,
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
        $computation = $this->computeForDistributor($distributorId, $date);

        $this->price(
            $computation,
            $this->pool->poolForDate($date),
            Feature::for(null)->active(GsbDailyPoolPricingFeature::class),
        );

        return $this->settle($computation);
    }

    /**
     * Pass 1 — pure computation, zero writes. Determines the distributor's
     * legs (CF-aware, rewound on re-run), simulates the conditional personal-BV
     * top-up, and matches a slab. All mutations are deferred to settle().
     */
    public function computeForDistributor(int $distributorId, Carbon $date): GsbCutoffComputation
    {
        // Idempotency: never double-credit.
        //
        // An admin-reversed row is equally terminal: the wallet was debited
        // back but the carry-forward store deliberately kept this date's
        // advance (no clawback). Re-running would skip the CF rewind (the row
        // still IS the advance) and, via the retry-deletes-failed-rows path,
        // could credit the date a second time against an inflated CF baseline.
        $existing = GsbCutoffResult::where('distributor_id', $distributorId)
            ->whereDate('cutoff_date', $date->toDateString())
            ->first();

        if ($existing !== null && in_array($existing->status, [
            GsbCutoffResult::STATUS_CREDITED,
            GsbCutoffResult::STATUS_REVERSED,
        ], true)) {
            return new GsbCutoffComputation(
                distributorId: $distributorId,
                date: $date,
                existing: $existing,
                outcome: GsbCutoffComputation::OUTCOME_ALREADY_SETTLED,
            );
        }

        // Resolve frozen status from batch cache; fall back to a live query for
        // single-distributor admin retry runs that skip warmBatch().
        $isFrozen = array_key_exists($distributorId, $this->frozenCache)
            ? $this->frozenCache[$distributorId]
            : Distributor::where('id', $distributorId)->value('gsb_frozen_at') !== null;

        // Eligibility gate: configurable minimum personal BV (default 600 BV).
        $personalBvPaise = $this->bvLedger->totalPersonalBvPaise($distributorId);

        if ($personalBvPaise < $this->plan->gsbMinBvPaise()) {
            return new GsbCutoffComputation(
                distributorId: $distributorId,
                date: $date,
                existing: $existing,
                outcome: GsbCutoffComputation::OUTCOME_BELOW_MIN_BV,
                isFrozen: $isFrozen,
                personalBvPaise: $personalBvPaise,
            );
        }

        $title = $this->titleService->forBvPaise($personalBvPaise);

        // Today's accumulated group BV (may be 0 if no orders in their group today).
        $dailyBv = GroupBvDaily::where('distributor_id', $distributorId)
            ->whereDate('date', $date->toDateString())
            ->first();

        $leftToday = $dailyBv?->left_bv_paise ?? 0;
        $rightToday = $dailyBv?->right_bv_paise ?? 0;

        // Carry-forward state, read into locals — settle() owns the row.
        $cf = GsbCarryforward::where('distributor_id', $distributorId)->first();
        $cfPower = $cf->power_side_bv_paise ?? 0;
        $cfSlab1 = $cf->slab1_weaker_bv_paise ?? 0;
        $cfSide = $cf->power_side ?? null;

        // Re-run of an already-processed date. The rolling CF store has already
        // absorbed this date's outcome, so recomputing against it would compound
        // today's BV into CF a second time (observed in the wild when a manual
        // run followed the 00:10 scheduled run). Rewind the local view to the
        // before-state recorded on the existing row — the recompute below then
        // lands on identical numbers, keeping "Retry is safe" true.
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

            $cfPower = $existing->power_cf_before_paise;
            $cfSlab1 = $existing->slab1_weaker_cf_before_paise;
            // Legacy rows predate power_side_before; fall back to the store's
            // current side (only wrong if the run flipped the power side).
            $cfSide = $existing->power_side_before ?? $cfSide;
        }

        // Snapshot the pre-run side now that the locals hold the true
        // before-state; saved on the result row so a future re-run can rewind
        // side-accurately.
        $powerSideBefore = $cfSide;

        // Add power CF to the side it belongs to.
        $leftEffective = $leftToday + ($cfSide === 'L' ? $cfPower : 0);
        $rightEffective = $rightToday + ($cfSide === 'R' ? $cfPower : 0);

        // Conditional personal-BV weaker-leg top-up (KP 2026-07-21). Accumulated
        // personal purchase BV is credited to the weaker leg ONLY on a cut-off
        // where a leg's effective BV (incl. CF) has already touched the smallest
        // slab threshold — otherwise it stays pending for a future day. On a tie
        // the Left leg is the stronger/power side, so the weaker (top-up) side is
        // Right. Simulated here; settle() applies exactly the planned orders so
        // an order paid between the two passes stays pending for tomorrow.
        $topupSide = null;
        $topupBvPaise = 0;
        $topupOrderIds = [];
        $minSlabMatched = $this->plan->gsbMinSlabMatchedBvPaise();
        if ($minSlabMatched > 0 && max($leftEffective, $rightEffective) >= $minSlabMatched) {
            $plan = $this->topup->pendingPlanForDistributor($distributorId, $date);

            if ($plan['bv_paise'] > 0) {
                $topupSide = $leftEffective < $rightEffective ? 'L' : 'R';
                $topupBvPaise = $plan['bv_paise'];
                $topupOrderIds = $plan['order_ids'];

                // Mirror the top-up locally so matching sees the new BV.
                if ($topupSide === 'L') {
                    $leftToday += $topupBvPaise;
                    $leftEffective += $topupBvPaise;
                } else {
                    $rightToday += $topupBvPaise;
                    $rightEffective += $topupBvPaise;
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
        $weakerTotal = $weakerEffective + $cfSlab1;

        $matchedSlab = null;

        // Slabs 7→2: fresh weaker BV only — slab1 CF must NOT apply here.
        // Inactive slabs (e.g. slab 7 while its bonus is TBD) are skipped.
        foreach ([7, 6, 5, 4, 3, 2] as $slabIndex) {
            $slabRow = $this->plan->gsbSlab($slabIndex);
            if ($slabRow === null || ! $slabRow['is_active'] || $slabRow['bonus_paise'] === null) {
                continue;
            }
            if ($slabIndex <= $title->maxGsbSlab && $weakerEffective >= $slabRow['matched_bv_paise']) {
                $matchedSlab = $slabRow;
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
            $matchedSlab = $slab1;
        }

        if ($matchedSlab === null) {
            // No match — carry-forward plan: weaker accumulates for slab 1, power carries forward.
            return new GsbCutoffComputation(
                distributorId: $distributorId,
                date: $date,
                existing: $existing,
                outcome: GsbCutoffComputation::OUTCOME_NO_MATCH,
                isFrozen: $isFrozen,
                personalBvPaise: $personalBvPaise,
                leftToday: $leftToday,
                rightToday: $rightToday,
                weakerTotal: $weakerTotal,
                strongerSide: $strongerSide,
                powerSideBefore: $powerSideBefore,
                cfBeforePower: $cfPower,
                cfBeforeSlab1: $cfSlab1,
                newPowerCf: min($strongerEffective, $this->plan->gsbPowerCfCapPaise()),
                newSlab1Cf: $weakerTotal,  // accumulates until 15K matched
                topupSide: $topupSide,
                topupBvPaise: $topupBvPaise,
                topupOrderIds: $topupOrderIds,
            );
        }

        // Slab matched. Repurchase eligibility is a read (warmed cache) — safe
        // to resolve here so settle() stays branch-for-branch mechanical.
        $eligibility = $isFrozen
            ? IncomeEligibilityService::ELIGIBLE
            : $this->eligibility->statusFor($distributorId, BonusType::Gsb);

        // Repurchase wallet gate: the wallet must have been spent down to ₹0 by
        // the end of the PREVIOUS calendar month. Read off the frozen month-end
        // snapshot, and fail-open while no snapshot exists. Frozen distributors
        // are exempt for the same reason they skip the eligibility read — their
        // branch never credits anyway.
        $walletBlocked = ! $isFrozen && ! $this->eligibility->repurchaseWalletZeroedForMonth(
            $distributorId,
            $date->copy()->timezone('Asia/Kolkata')->startOfMonth()->subMonthNoOverflow()->toDateString(),
        );

        return new GsbCutoffComputation(
            distributorId: $distributorId,
            date: $date,
            existing: $existing,
            outcome: $walletBlocked
                ? GsbCutoffComputation::OUTCOME_REPURCHASE_WALLET_BLOCKED
                : GsbCutoffComputation::OUTCOME_MATCHED,
            isFrozen: $isFrozen,
            eligibility: $eligibility,
            personalBvPaise: $personalBvPaise,
            leftToday: $leftToday,
            rightToday: $rightToday,
            weakerTotal: $weakerTotal,
            strongerSide: $strongerSide,
            powerSideBefore: $powerSideBefore,
            cfBeforePower: $cfPower,
            cfBeforeSlab1: $cfSlab1,
            newPowerCf: min(
                max(0, $strongerEffective - $weakerTotal),
                $this->plan->gsbPowerCfCapPaise(),
            ),
            newSlab1Cf: 0,
            slabIndex: $matchedSlab['slab'],
            slabThreshold: $matchedSlab['matched_bv_paise'],
            slabScore: $matchedSlab['score'],
            slabScoreValuePaise: $matchedSlab['score_value_paise'],
            nominalBonusPaise: $matchedSlab['bonus_paise'],
            topupSide: $topupSide,
            topupBvPaise: $topupBvPaise,
            topupOrderIds: $topupOrderIds,
        );
    }

    /**
     * Pass 2 — assign the gross. Fixed slabs (1–2) always pay score × their
     * fixed score value. Variable slabs (3–7) pay score × the day's frozen
     * pool value when pool pricing is active and a pool row exists; historical
     * dates without a pool row (and the flag-off path) fall back to the
     * materialized gsb_slabs.bonus_paise — byte-identical legacy behavior.
     */
    public function price(GsbCutoffComputation $computation, ?GsbDailyPool $dailyPool, bool $variablePricingActive): void
    {
        if (! $computation->isMatched()) {
            return;
        }

        if (! $variablePricingActive || $computation->slabIndex === null) {
            $computation->grossPaise = $computation->nominalBonusPaise;
            $computation->pricedScoreValuePaise = $computation->slabScoreValuePaise;

            return;
        }

        if (! GsbDailyPoolService::isVariableSlab($computation->slabIndex)) {
            $computation->grossPaise = $computation->fixedSlabGrossPaise();
            $computation->pricedScoreValuePaise = $computation->slabScoreValuePaise;

            return;
        }

        if ($dailyPool === null || $computation->slabScore === null) {
            $computation->grossPaise = $computation->nominalBonusPaise;
            $computation->pricedScoreValuePaise = $computation->slabScoreValuePaise;

            return;
        }

        $computation->pricedScoreValuePaise = $dailyPool->variable_score_value_paise;
        $computation->grossPaise = $computation->slabScore * $dailyPool->variable_score_value_paise;
    }

    /**
     * Pass 3 — all writes: apply the planned top-up, advance the carry-forward
     * store, persist the result row and credit the wallet. Branches mirror the
     * pre-refactor engine exactly.
     */
    public function settle(GsbCutoffComputation $computation): GsbCutoffResult
    {
        $existing = $computation->existing;

        if ($computation->outcome === GsbCutoffComputation::OUTCOME_ALREADY_SETTLED) {
            /** @var GsbCutoffResult $existing */
            return $existing;
        }

        $distributorId = $computation->distributorId;
        $date = $computation->date;

        if ($computation->outcome === GsbCutoffComputation::OUTCOME_BELOW_MIN_BV) {
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

        // Apply the simulated personal-BV top-up — exactly the orders the
        // computation counted, so the settled accumulator matches the match.
        if ($computation->topupBvPaise > 0 && $computation->topupSide !== null) {
            $this->topup->applyPendingForDistributor(
                $distributorId,
                $date,
                $computation->topupSide,
                $computation->topupOrderIds,
            );
        }

        // Carry-forward state (create row if this distributor's first cut-off).
        $cf = GsbCarryforward::firstOrCreate(
            ['distributor_id' => $distributorId],
            ['power_side_bv_paise' => 0, 'power_side' => null, 'slab1_weaker_bv_paise' => 0],
        );

        // PARITY PARTNER: GsbIdleCutoffBatch::row() bulk-writes this exact branch
        // for distributors it has proven inert, skipping the compute entirely.
        // Any change to the columns, the defaults or the 'L' tie-break below
        // must be mirrored there, or a replay and a live run stop agreeing.
        if ($computation->outcome === GsbCutoffComputation::OUTCOME_NO_MATCH) {
            $cf->update([
                'power_side_bv_paise' => $computation->newPowerCf,
                'power_side' => $computation->strongerSide,
                'slab1_weaker_bv_paise' => $computation->newSlab1Cf,
            ]);

            return $this->saveResult($existing, [
                'distributor_id' => $distributorId,
                'cutoff_date' => $date->toDateString(),
                'left_bv_paise' => $computation->leftToday,
                'right_bv_paise' => $computation->rightToday,
                'weaker_bv_paise' => $computation->weakerTotal,
                'power_cf_before_paise' => $computation->cfBeforePower,
                'power_side_before' => $computation->powerSideBefore,
                'power_cf_after_paise' => $computation->newPowerCf,
                'power_side_after' => $computation->strongerSide,
                'slab1_weaker_cf_before_paise' => $computation->cfBeforeSlab1,
                'slab1_weaker_cf_after_paise' => $computation->newSlab1Cf,
                'status' => GsbCutoffResult::STATUS_NO_MATCH,
            ]);
        }

        // Slab matched. Deductions (admin charge, TDS) are applied at payout time.
        $gross = $computation->grossPaise;

        $baseData = [
            'distributor_id' => $distributorId,
            'cutoff_date' => $date->toDateString(),
            'left_bv_paise' => $computation->leftToday,
            'right_bv_paise' => $computation->rightToday,
            'weaker_bv_paise' => $computation->weakerTotal,
            'slab' => $computation->slabIndex,
            'score' => $computation->slabScore,
            'score_value_paise' => $computation->pricedScoreValuePaise,
            'gross_gsb_paise' => $gross,
            'admin_charge_paise' => 0,
            'tds_paise' => 0,
            'net_gsb_paise' => $gross,
            'power_cf_before_paise' => $computation->cfBeforePower,
            'power_side_before' => $computation->powerSideBefore,
            'power_cf_after_paise' => $computation->newPowerCf,
            'power_side_after' => $computation->strongerSide,
            'slab1_weaker_cf_before_paise' => $computation->cfBeforeSlab1,
            'slab1_weaker_cf_after_paise' => 0,
        ];

        // Frozen distributors: calculate but do not credit wallet.
        // Advance CF identically to the no-match path so stale slab1 BV doesn't
        // phantom-accumulate during the freeze and double-credit on unfreeze.
        // Transactional: if the result row fails to persist (e.g. a schema
        // mismatch), the CF mutation must roll back with it or the matched BV
        // is silently lost.
        if ($computation->isFrozen) {
            return DB::transaction(function () use ($cf, $computation, $existing, $baseData): GsbCutoffResult {
                $cf->update([
                    'power_side_bv_paise' => $computation->newPowerCf,
                    'power_side' => $computation->strongerSide,
                    'slab1_weaker_bv_paise' => 0,
                ]);

                return $this->saveResult($existing, [
                    ...$baseData,
                    'status' => GsbCutoffResult::STATUS_FROZEN,
                ]);
            });
        }

        // Repurchase wallet gate: the previous calendar month closed with an
        // unspent repurchase wallet, so the day's match is recorded but not
        // paid. CF advances exactly as in the frozen path — the weaker side has
        // been consumed by the match either way, and letting it phantom-
        // accumulate would double-credit it the day the gate clears.
        if ($computation->outcome === GsbCutoffComputation::OUTCOME_REPURCHASE_WALLET_BLOCKED) {
            return DB::transaction(function () use ($cf, $computation, $existing, $baseData): GsbCutoffResult {
                $cf->update([
                    'power_side_bv_paise' => $computation->newPowerCf,
                    'power_side' => $computation->strongerSide,
                    'slab1_weaker_bv_paise' => 0,
                ]);

                return $this->saveResult($existing, [
                    ...$baseData,
                    'status' => GsbCutoffResult::STATUS_REPURCHASE_SUSPENDED,
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
        if ($computation->eligibility !== IncomeEligibilityService::ELIGIBLE) {
            return DB::transaction(function () use ($cf, $computation, $existing, $baseData): GsbCutoffResult {
                $cf->update([
                    'power_side_bv_paise' => $computation->newPowerCf,
                    'power_side' => $computation->strongerSide,
                    'slab1_weaker_bv_paise' => 0,
                ]);

                return $this->saveResult($existing, [
                    ...$baseData,
                    'status' => $computation->eligibility === IncomeEligibilityService::HOLD
                        ? GsbCutoffResult::STATUS_REPURCHASE_HELD
                        : GsbCutoffResult::STATUS_REPURCHASE_SUSPENDED,
                ]);
            });
        }

        // Credit wallet inside a transaction — CF update is atomic with the wallet credit.
        try {
            DB::transaction(function () use ($distributorId, $gross, $baseData, $existing, $cf, $computation): void {
                // Move carry-forward update inside the transaction so it rolls back if credit fails.
                $cf->update([
                    'power_side_bv_paise' => $computation->newPowerCf,
                    'power_side' => $computation->strongerSide,
                    'slab1_weaker_bv_paise' => 0,
                ]);

                $savedResult = $this->saveResult($existing, [
                    ...$baseData,
                    'status' => GsbCutoffResult::STATUS_CALCULATED,
                ]);

                // A starved pool day can price a matched slab 3–7 at ₹0 — the
                // weaker leg is still consumed, but a zero wallet entry is noise.
                if ($gross > 0) {
                    $this->wallet->creditWithRepurchaseDeduction(
                        distributorId: $distributorId,
                        grossPaise: $gross,
                        bonusType: 'gsb_credit',
                        referenceId: $savedResult->id,
                        referenceType: 'gsb_cutoff_result',
                    );
                }

                // STATUS_CALCULATED is transient; the daily command should treat past-date CALCULATED rows as failed on restart.
                $savedResult->update(['status' => GsbCutoffResult::STATUS_CREDITED]);
            });
        } catch (Throwable $e) {
            return $this->saveResult($existing, [
                ...$baseData,
                'slab1_weaker_cf_after_paise' => $computation->cfBeforeSlab1,  // CF was NOT zeroed (transaction rolled back)
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
        'score_value_paise' => null,
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
