<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Services\EngineStatusService;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

/**
 * Which Tuesdays owe a weekly payout batch tonight — the ONE answer, given to
 * the scheduler predicate, to the weekly run itself and to the prerequisite
 * check the monthly close makes.
 *
 * Three callers, one question, and they must never differ: a scheduler that
 * starts the run on a night the command then finds nothing owed writes a
 * pointless `skipped` row, and a monthly close that waits for a weekly run the
 * scheduler never started waits for ever.
 *
 * The body came from the old single nightly chain, moved rather than rewritten
 * — same frontier search, same flag-off rule, same null-frontier branch — with
 * the console output left behind, because a planner that prints cannot be asked
 * a question from the scheduler process.
 */
final class WeeklyRunPlanner
{
    /**
     * How far back to look for a Tuesday whose weekly batch was never built.
     *
     * Earnings are never lost to a missed Tuesday — the weekly sweep takes
     * every unpaid entry earned on or before its window end, so the next batch
     * collects the previous week's too. What is lost is the WEEK: distributors
     * wait until the following Tuesday. Four weeks is far enough to cover the
     * outage the cut-off backfill can heal and no further.
     */
    public const MAX_BACKFILL_WEEKS = 4;

    public function __construct(private readonly EngineStatusService $status) {}

    /** Is the weekly run owed anything at all tonight? */
    public function isDue(Carbon $night): bool
    {
        return $this->owedTuesdays($night) !== [];
    }

    /**
     * The Tuesdays this night owes a weekly batch, oldest first.
     *
     * NOT only on a Tuesday. A batch missed on its own night — the run was
     * skipped, the night aborted, the container was down — is retried the very
     * next night, still DATED that Tuesday, so the earning week it pays is
     * unchanged: Wednesday through that Tuesday, exactly as
     * {@see PayoutBatch::weeklyEarningWindow()} defines it. Waiting for the
     * following Tuesday would cost distributors a week for an outage that had
     * nothing to do with them. (Their earnings are never lost either way — the
     * weekly sweep takes every unpaid entry earned on or before its window end,
     * so a later batch would collect the missed week too. What is at stake is
     * when they are paid, not whether.)
     *
     * That includes the very first payout Tuesday, which has no earlier batch
     * to be backfilled from. It is built on the next night like any other missed
     * Tuesday — one batch, never a month, because nothing before `$latest` is
     * reachable without a frontier.
     *
     * Proven from the batch itself, not from the run log: a batch is the thing
     * that exists. It also keeps the run from re-invoking a batch finance has
     * already APPROVED — the runner returns such a batch untouched and the
     * command then reports FAILURE, which would abort the whole run over a
     * batch that is not merely fine but signed off.
     *
     * @return list<Carbon>
     */
    public function owedTuesdays(Carbon $night): array
    {
        $latest = $night->dayOfWeekIso === Carbon::TUESDAY
            ? $night->copy()
            : $night->copy()->previous(Carbon::TUESDAY);

        // WITH THE ENGINE'S FLAG OFF, NOTHING IS OWED — and saying otherwise
        // every night is how a real warning stops being read. The sweep records
        // `skipped` and builds no batch, so no batch ever exists, so the
        // frontier search below can never find one and the null-frontier branch
        // would name `$latest` on every non-Tuesday night for as long as the
        // flag stayed off. Tonight's own Tuesday is still owed, because that
        // `skipped` row in `engine_runs` is the only record that the engine was
        // off on a night it was due — and it is also what keeps a flag-off
        // Tuesday from blocking the monthly close for ever, since the weekly
        // run still exits 0 with a succeeded row of its own. What is dropped is
        // the backfill, which describes a debt that does not exist.
        if ($this->flagIsOff()) {
            return $night->dayOfWeekIso === Carbon::TUESDAY ? [$night->copy()] : [];
        }

        $frontier = null;

        // Newest first: the first Tuesday that HAS a batch is the frontier.
        // Before it is history the run does not reopen — a fresh install must
        // not invent a month of batches.
        for ($weeksBack = 0; $weeksBack <= self::MAX_BACKFILL_WEEKS; $weeksBack++) {
            $tuesday = $latest->copy()->subWeeks($weeksBack);

            if ($this->status->payoutBatchExists(PayoutBatch::TYPE_WEEKLY, $tuesday)) {
                $frontier = $tuesday;

                break;
            }
        }

        if ($frontier === null) {
            // No batch anywhere in the window, so there is no frontier to
            // backfill FROM — but `$latest` is still owed, and it is ONE
            // Tuesday at most six days old, not an invented month.
            //
            // Returning [] here on a non-Tuesday is what used to cost the FIRST
            // payout Tuesday a full week: its own night failed before the sweep,
            // and no later night could name that Tuesday again, because the
            // backfill loop below needs an earlier batch to start from and there
            // is none. Every LATER Tuesday was always safe — it has the week
            // before it as its frontier. Only the first one was unreachable, on
            // the one night it mattered most.
            return [$latest->copy()];
        }

        $days = [];

        for ($tuesday = $frontier->copy()->addWeek(); $tuesday->lessThanOrEqualTo($latest); $tuesday->addWeek()) {
            if (! $this->status->payoutBatchExists(PayoutBatch::TYPE_WEEKLY, $tuesday)) {
                $days[] = $tuesday->copy();
            }
        }

        return $days;
    }

    /**
     * Is the weekly sweep's own feature flag off tonight?
     *
     * Read from the registry rather than named here, so the flag this asks
     * about and the flag the engine is actually gated on cannot drift apart.
     */
    public function flagIsOff(): bool
    {
        $flag = EngineRegistry::get('gsb.weekly-payout')->featureFlagClass;

        return $flag !== null && ! Feature::for(null)->active($flag);
    }
}
