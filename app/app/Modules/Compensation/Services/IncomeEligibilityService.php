<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\DTOs\RepurchaseVerdict;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * Answers one question, in the two shapes its callers need it: was this
 * distributor's repurchase obligation failed on this day?
 *
 * Client spec 2026-09-07 §2 — a failed day is FORFEITED, not held. The day's
 * group BV is never added and the income that would have followed from it is
 * lost permanently; nothing is released later. Which days are lost is decided
 * in exactly one place, {@see RepurchaseCycle::forfeitedWindow()}; both methods
 * here read it rather than re-deriving the arithmetic, so a daily engine asking
 * "is 25 Aug forfeited?" and a monthly rank sum asking "which days of August
 * were forfeited?" can never disagree.
 *
 * The verdict is a function of a DATE, not of "the newest cycle row". A monthly
 * engine re-running August must reach the verdict August's run reached, and a
 * distributor who failed on 9 Aug and fulfilled on 19 Aug lost exactly those
 * ten days. `fulfilled_on` on the cycle row is what makes that answerable after
 * the fact.
 *
 * The whole thing is gated by the {@see RepurchaseEngineFeature} flag, so when
 * the engine is off nothing is ever forfeited and existing runs are unchanged.
 */
final class IncomeEligibilityService
{
    /** The day counts normally. */
    public const ELIGIBLE = 'eligible';

    /** The day fell inside a failed cycle's window — its BV and income are lost. */
    public const FORFEITED = 'forfeited';

    /** @var array<int, Collection<int, RepurchaseCycle>> Cycles per distributor, newest first. */
    private array $cycleCache = [];

    /**
     * Batch-load every repurchase cycle for these distributors so subsequent
     * {@see verdictAsOf()} calls skip the per-distributor query. All cycles, not
     * just the latest: the verdict for a past date may sit on an older row.
     *
     * @param  int[]  $distributorIds
     */
    public function warmCycleCache(array $distributorIds): void
    {
        if ($distributorIds === []) {
            return;
        }

        $byDistributor = RepurchaseCycle::query()
            ->whereIn('distributor_id', $distributorIds)
            ->orderByDesc('cycle_start_date')
            ->get()
            ->groupBy('distributor_id');

        foreach ($distributorIds as $id) {
            /** @var Collection<int, RepurchaseCycle> $cycles */
            $cycles = $byDistributor->get($id) ?? collect();
            $this->cycleCache[$id] = $cycles;
        }
    }

    /** Whether the repurchase engine is enabled. */
    public function engineActive(): bool
    {
        return Feature::for(null)->active(RepurchaseEngineFeature::class);
    }

    /**
     * Repurchase standing on $asOf. This is a READ — it reflects the cycle
     * state maintained by the daily `repurchase:evaluate` command (the sole
     * writer), so bonus runs never mutate cycle state or fire events.
     *
     * Eligible when the engine is off, when the distributor has no cycle
     * covering $asOf (pre-600-BV, or not yet evaluated → fail open, so a lagging
     * daily command can never silently forfeit everyone's income), and when the
     * governing cycle forfeited no days or forfeited days other than this one.
     */
    public function verdictAsOf(int $distributorId, Carbon $asOf): RepurchaseVerdict
    {
        if (! $this->engineActive()) {
            return RepurchaseVerdict::eligible();
        }

        $asOf = $asOf->copy()->startOfDay();
        $cycle = $this->cycleCovering($distributorId, $asOf);

        if ($cycle === null) {
            return RepurchaseVerdict::eligible();
        }

        $window = $cycle->forfeitedWindow();

        if ($window === null) {
            return RepurchaseVerdict::eligible($cycle->id);
        }

        [$start, $end] = $window;

        if ($asOf->lessThan($start) || ($end !== null && $asOf->greaterThan($end))) {
            return RepurchaseVerdict::eligible($cycle->id);
        }

        return RepurchaseVerdict::forfeited($cycle->failure_reason, $cycle->id);
    }

    /**
     * Every distributor's forfeited days between $from and $to, clipped to that
     * range — what a monthly engine needs in order to subtract the lost days
     * from a month's group BV in one query instead of 31 per distributor.
     *
     * Keyed by distributor id; distributors who forfeited nothing in the range
     * are simply absent. Ranges are inclusive `[start, end]` date strings and,
     * because cycle windows never overlap, disjoint and in date order.
     *
     * @param  int[]|null  $distributorIds  restrict the scan to these distributors
     *                                      (a one-distributor read, e.g. the rank
     *                                      progress page, must not load every
     *                                      failed cycle on the platform)
     * @return array<int, list<array{0: string, 1: string}>>
     */
    public function forfeitedDayRanges(Carbon $from, Carbon $to, ?array $distributorIds = null): array
    {
        if (! $this->engineActive()) {
            return [];
        }

        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        // Narrow to cycles that could possibly overlap the range before asking
        // forfeitedWindow(): resolved (an unresolved cycle forfeits nothing),
        // due before the range ends (or its window starts past $to), and either
        // still unfulfilled or fulfilled late and after the range starts.
        $cycles = RepurchaseCycle::query()
            ->when($distributorIds !== null, fn ($q) => $q->whereIn('distributor_id', $distributorIds))
            ->whereNotNull('resolved_at')
            ->whereDate('due_date', '<', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('fulfilled_on')->orWhereColumn('fulfilled_on', '>', 'due_date'))
            ->where(fn ($q) => $q->whereNull('fulfilled_on')->orWhereDate('fulfilled_on', '>', $from->toDateString()))
            ->orderBy('due_date')
            ->get(['distributor_id', 'due_date', 'fulfilled_on', 'resolved_at']);

        $ranges = [];

        foreach ($cycles as $cycle) {
            $window = $cycle->forfeitedWindow();

            if ($window === null) {
                continue;
            }

            [$start, $end] = $window;

            $start = $start->greaterThan($from) ? $start : $from->copy();
            $end = ($end === null || $end->greaterThan($to)) ? $to->copy() : $end;

            if ($start->lessThanOrEqualTo($end)) {
                $ranges[(int) $cycle->distributor_id][] = [$start->toDateString(), $end->toDateString()];
            }
        }

        return $ranges;
    }

    /**
     * The cycle whose window started on or before $asOf — the one that governs
     * that date. A later cycle cannot judge an earlier day, and the gap between
     * a failed window and its late fulfilment belongs to the failed cycle.
     */
    private function cycleCovering(int $distributorId, Carbon $asOf): ?RepurchaseCycle
    {
        if (array_key_exists($distributorId, $this->cycleCache)) {
            return $this->cycleCache[$distributorId]
                ->first(fn (RepurchaseCycle $c): bool => $c->cycle_start_date->copy()->startOfDay()->lessThanOrEqualTo($asOf));
        }

        return RepurchaseCycle::query()
            ->where('distributor_id', $distributorId)
            ->whereDate('cycle_start_date', '<=', $asOf->toDateString())
            ->orderByDesc('cycle_start_date')
            ->first();
    }
}
