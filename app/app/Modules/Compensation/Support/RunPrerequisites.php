<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Compensation\Services\EngineStatusService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * "Did tonight's earlier runs succeed?" — the ordering guard between the three
 * cadences, answered from the run log rather than from a clock or a lock.
 *
 * The client's rule (2026-09-18): the daily closing goes first, the Tuesday
 * payout after it, and the monthly close after both. A clock offset cannot hold
 * that order — `withoutOverlapping()` is per command and does not serialise
 * across commands — and a shared cache lock is not an option either: the
 * default cache store is a Redis shared with eight other apps under
 * `allkeys-lfu`, which may evict a lock key silently (ADR-0011). The run log is
 * the only durable record that a run actually finished, so it is what the guard
 * reads.
 *
 * Each refusal is a SENTENCE FRAGMENT, not a finished message: callers embed it
 * ("September 2026 was not closed tonight: <fragment>"), so the phrasing stays
 * with the run that made the decision.
 *
 * Refusing costs a day and never a figure. The weekly batch dated Tuesday T
 * sweeps only entries earned on or before T−7, so it never needed tonight's
 * cut-off; the wait is the client's ordering rule, not a data dependency, and a
 * deferred batch is still dated that Tuesday when it is built.
 */
final class RunPrerequisites
{
    public function __construct(
        private readonly EngineStatusService $status,
        private readonly WeeklyRunPlanner $weekly,
    ) {}

    /**
     * Null when tonight's nightly run has a succeeded row dated $night that
     * started after the night began.
     *
     * "Dated tonight AND started tonight" rather than "has ever succeeded":
     * a succeeded row dated tonight but started yesterday is a replay of a past
     * night, and the ordering rule is about the process that has just finished
     * writing tonight's cut-off.
     *
     * The diagnosis is read from TONIGHT's own attempt, not from the last run
     * of the engine at any period: on a night whose run has not started yet
     * the latter names yesterday's row, and an operator told "its last attempt
     * is succeeded" goes looking for a failure that is not there. No attempt
     * dated tonight is itself the diagnosis.
     */
    public function nightlyRunRefusal(Carbon $night): ?string
    {
        if ($this->status->hasSucceededRunTonight('compensation.nightly-run', $night)) {
            return null;
        }

        $latest = $this->status->latestFinishedRun('compensation.nightly-run', $night);

        return sprintf(
            "tonight's nightly run (%s) has not succeeded%s",
            $night->format('d M Y'),
            $latest === null
                ? ' — it has not run at all tonight'
                : sprintf(
                    ' — its last attempt is %s%s',
                    $latest->status,
                    is_string($latest->error) && $latest->error !== ''
                        ? ': '.Str::limit((string) strtok($latest->error, "\n"), 200)
                        : '',
                ),
        );
    }

    /**
     * Null when no Tuesday batch is owed tonight, or tonight's weekly run has
     * succeeded.
     *
     * The "not due" branch is what keeps this from blocking the close on every
     * ordinary night — and, with the GSB flag off, what keeps a flag-off
     * Tuesday from blocking it for ever: the weekly run still exits 0 with a
     * succeeded row of its own, having recorded the leaf as skipped.
     */
    public function weeklyRunRefusal(Carbon $night): ?string
    {
        if (! $this->weekly->isDue($night)
            || $this->status->hasSucceededRunTonight('compensation.weekly-run', $night)) {
            return null;
        }

        return sprintf(
            "tonight's weekly run has not succeeded and a Tuesday batch (%s) is owed",
            implode(', ', array_map(
                static fn (Carbon $tuesday): string => $tuesday->toDateString(),
                $this->weekly->owedTuesdays($night),
            )),
        );
    }
}
