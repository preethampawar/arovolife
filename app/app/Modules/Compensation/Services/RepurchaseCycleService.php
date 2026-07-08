<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Events\IncomeReactivated;
use App\Modules\Compensation\Events\IncomeSuspended;
use App\Modules\Compensation\Events\RepurchaseCompleted;
use App\Modules\Compensation\Events\RepurchaseCycleOpened;
use App\Modules\Compensation\Events\RepurchaseGraceStarted;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\RepurchaseCycle;
use Illuminate\Support\Carbon;

/**
 * Manages each distributor's monthly repurchase obligation (KP 2026-06-27/28).
 *
 * Cycles are anchored to the day-of-month the distributor first reached 600 BV
 * personal purchase (5 Jul 2026 rule; e.g. the 5th → cycle 5th..4th). A
 * distributor must complete their rank's
 * `repurchase_bv_paise` of self-purchase BV before the cycle's due date; missing
 * it opens a `grace_days` window (income calculated-but-held), after which their
 * GSB/Fortune/GBB are suspended — Mentorship and Rank BV are never suspended.
 *
 * MVP modelling note: one obligation cycle is open at a time (non-overlapping),
 * so a single cycle row's status IS the distributor's effective eligibility. The
 * next cycle opens only once the current one is completed AND its window has
 * elapsed; a suspended distributor stays on the same cycle until they complete
 * it, then resumes forward. (Strict back-to-back overlapping cycles with grace
 * spilling into the next cycle is a refinement to confirm with KP.)
 *
 * Every value is read through {@see CompensationPlanSettingsService} (SSOT) and
 * every state transition emits a fire-and-forget domain event.
 */
final class RepurchaseCycleService
{
    /** Safety bound on the catch-up roll loop (months). */
    private const MAX_ROLL = 120;

    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
        private readonly BvLedgerService $bvLedger,
    ) {}

    /** Highest rank the distributor has qualified for (sticky); 0 = non-ranked. */
    public function currentRank(int $distributorId): int
    {
        return (int) RankQualification::query()
            ->where('distributor_id', $distributorId)
            ->where('status', RankQualification::STATUS_QUALIFIED)
            ->max('rank_number');
    }

    /** Monthly repurchase BV obligation (paise) for this distributor's rank. */
    public function requiredBvPaise(int $distributorId): int
    {
        $rank = $this->currentRank($distributorId);

        return $rank > 0
            ? $this->plan->rankRepurchaseBvPaise($rank)
            : $this->plan->nonRankedRepurchaseBvPaise();
    }

    /**
     * The distributor's repurchase anchor = the date they first reached the
     * 600-BV personal-purchase minimum (5 Jul 2026 rule; previously the 3,000-BV
     * Retailer title). Null until they reach 600 BV (no obligation before that).
     */
    public function repurchaseAnchor(int $distributorId): ?Carbon
    {
        return $this->bvLedger->firstReachedBvPaiseAt($distributorId, $this->plan->gsbMinBvPaise());
    }

    /**
     * Open/advance the distributor's repurchase cycle as of $asOf, refreshing
     * completion + status and emitting transition events. Returns the open cycle,
     * or null if the distributor is not yet a Retailer (no obligation).
     */
    public function evaluate(int $distributorId, Carbon $asOf): ?RepurchaseCycle
    {
        $anchor = $this->repurchaseAnchor($distributorId);
        if ($anchor === null || $anchor->greaterThan($asOf)) {
            return null;
        }

        $cycle = RepurchaseCycle::query()
            ->where('distributor_id', $distributorId)
            ->orderByDesc('cycle_start_date')
            ->first()
            ?? $this->openCycle($distributorId, $anchor->copy()->startOfDay());

        // Advance through elapsed-and-completed cycles until the open cycle
        // covers $asOf or is held/suspended. The guard caps catch-up and, by
        // failing the roll condition rather than opening a new row, guarantees
        // the returned cycle is always one that was just refreshed.
        $guard = 0;
        while (true) {
            $this->refresh($cycle, $asOf);

            if ($cycle->status === RepurchaseCycle::STATUS_COMPLETED
                && $asOf->greaterThan($cycle->due_date)
                && ++$guard <= self::MAX_ROLL) {
                $cycle = $this->openCycle($distributorId, $cycle->due_date->copy()->addDay());

                continue;
            }
            break;
        }

        return $cycle;
    }

    /**
     * Create + persist a fresh cycle starting on $start; emits the opened event.
     * The required BV is snapshotted from the distributor's rank at cycle-open;
     * a rank change mid-cycle takes effect from the next cycle.
     */
    private function openCycle(int $distributorId, Carbon $start): RepurchaseCycle
    {
        $start = $start->copy()->startOfDay();
        $due = $start->copy()->addMonthNoOverflow()->subDay();
        $graceEnd = $due->copy()->addDays($this->plan->repurchaseGraceDays());

        $cycle = RepurchaseCycle::create([
            'distributor_id' => $distributorId,
            'cycle_start_date' => $start->toDateString(),
            'due_date' => $due->toDateString(),
            'grace_end_date' => $graceEnd->toDateString(),
            'required_bv_paise' => $this->requiredBvPaise($distributorId),
            'completed_bv_paise' => 0,
            'status' => RepurchaseCycle::STATUS_ACTIVE,
        ]);

        event(new RepurchaseCycleOpened($distributorId, $cycle->id, $start->toDateString()));

        return $cycle;
    }

    /** Recompute one cycle's completion + status as of $asOf and persist it. */
    private function refresh(RepurchaseCycle $cycle, Carbon $asOf): void
    {
        $start = $cycle->cycle_start_date->copy()->startOfDay();
        $asOfEod = $asOf->copy()->endOfDay();

        // On-time / grace completion counts self-purchase BV only up to the
        // grace end (so a later cycle's BV can never complete an earlier rolled
        // cycle). A suspended cycle can still be completed late — that counts
        // BV right up to $asOf.
        $byGraceEnd = $this->bvLedger->selfPurchaseBvPaise(
            $cycle->distributor_id,
            $start,
            $asOfEod->copy()->min($cycle->grace_end_date->copy()->endOfDay()),
        );
        $now = $this->bvLedger->selfPurchaseBvPaise($cycle->distributor_id, $start, $asOfEod);

        $previous = $cycle->status;
        $next = $this->resolveStatus($cycle, $asOf, $byGraceEnd, $now);

        $cycle->completed_bv_paise = $next === RepurchaseCycle::STATUS_COMPLETED
            ? max($byGraceEnd, $now)
            : $now;

        if ($next !== $previous) {
            if ($next === RepurchaseCycle::STATUS_COMPLETED && $cycle->completed_at === null) {
                $cycle->completed_at = Carbon::now();
            }
            $this->onTransition($cycle, $previous, $next);
        }

        $cycle->status = $next;
        $cycle->save();
    }

    private function resolveStatus(RepurchaseCycle $cycle, Carbon $asOf, int $byGraceEnd, int $now): string
    {
        $required = $cycle->required_bv_paise;

        if ($byGraceEnd >= $required) {
            return RepurchaseCycle::STATUS_COMPLETED;
        }
        if ($asOf->lessThanOrEqualTo($cycle->due_date->copy()->endOfDay())) {
            return RepurchaseCycle::STATUS_ACTIVE;
        }
        if ($asOf->lessThanOrEqualTo($cycle->grace_end_date->copy()->endOfDay())) {
            return RepurchaseCycle::STATUS_GRACE;
        }
        if ($now >= $required) {
            return RepurchaseCycle::STATUS_COMPLETED; // late completion → reactivation
        }

        return RepurchaseCycle::STATUS_SUSPENDED;
    }

    private function onTransition(RepurchaseCycle $cycle, string $from, string $to): void
    {
        $distributorId = $cycle->distributor_id;

        match ($to) {
            RepurchaseCycle::STATUS_GRACE => event(new RepurchaseGraceStarted(
                $distributorId,
                $cycle->id,
                $cycle->grace_end_date->toDateString(),
            )),
            RepurchaseCycle::STATUS_SUSPENDED => event(new IncomeSuspended($distributorId, $cycle->id)),
            RepurchaseCycle::STATUS_COMPLETED => $this->onCompleted($cycle, $from),
            default => null,
        };
    }

    private function onCompleted(RepurchaseCycle $cycle, string $from): void
    {
        // Pass the prior status so listeners can distinguish on-time (from
        // active) vs within-grace vs forfeited-then-completed (from suspended).
        event(new RepurchaseCompleted($cycle->distributor_id, $cycle->id, $from));

        if (in_array($from, [RepurchaseCycle::STATUS_GRACE, RepurchaseCycle::STATUS_SUSPENDED], true)) {
            event(new IncomeReactivated($cycle->distributor_id, $cycle->id));
        }
    }
}
