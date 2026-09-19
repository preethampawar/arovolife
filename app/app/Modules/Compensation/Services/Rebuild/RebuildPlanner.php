<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Rebuild;

use App\Modules\Compensation\Exceptions\RebuildStateChanged;
use Closure;
use Illuminate\Support\Carbon;

/**
 * The one door every rebuild goes through: the CLI commands, the queued job and
 * (from S4) the admin preview all ask this for the plan and this for the wipe.
 *
 * Two methods and no third: `plan()` says what would be removed and why it might
 * be refused, `execute()` removes exactly that. The re-run is deliberately NOT
 * here — the command does it, so the nested engines' console output is captured
 * against their own run rows rather than swallowed inside a service.
 */
final class RebuildPlanner
{
    public function __construct(
        private readonly RebuildPreflight $preflight,
        private readonly NightRebuilder $night,
        private readonly MonthRebuilder $month,
        private readonly BatchRebuilder $batch,
    ) {}

    public function plan(RebuildKind $kind, Carbon $period): RebuildPlan
    {
        $plan = match ($kind) {
            RebuildKind::Night => $this->night->plan($period),
            RebuildKind::Month => $this->month->plan($period),
            RebuildKind::Week, RebuildKind::Payout => $this->batch->plan($kind, $period),
        };

        // The shared refusals go FIRST: "a run is in flight" is the reason to
        // stop reading, whatever the period-specific list says underneath it.
        $shared = $this->preflight->refusals();

        return $shared === [] ? $plan : $plan->withRefusals([...$shared, ...$plan->refusals]);
    }

    /**
     * Remove the period's rows. One transaction per rebuilder, so a wipe that
     * dies half way leaves the period exactly as it was.
     *
     * Takes the CONFIRMED plan rather than a kind and a date, because the wipe
     * re-plans and compares against it before its first delete: a preview and a
     * confirm are two moments, and at 00:05 the scheduler is a third. Anything
     * that moved in between — a new refusal, or merely a different row count —
     * throws {@see RebuildStateChanged} inside the rebuilder's transaction and
     * nothing is written.
     *
     * @param  Closure(string): void  $log
     */
    public function execute(RebuildPlan $confirmed, int $actorId, Closure $log): RebuildResult
    {
        $verify = function () use ($confirmed): void {
            $fresh = $this->plan($confirmed->kind, $confirmed->period);

            if ($fresh->isRefused()) {
                throw RebuildStateChanged::refused($fresh->refusals);
            }

            if ($fresh->fingerprint() !== $confirmed->fingerprint()) {
                throw RebuildStateChanged::changed();
            }
        };

        return match ($confirmed->kind) {
            RebuildKind::Night => $this->night->wipe($confirmed->period, $log, $verify),
            RebuildKind::Month => $this->month->wipe($confirmed->period, $actorId, $log, $verify),
            RebuildKind::Week, RebuildKind::Payout => $this->batch->wipe(
                $confirmed->kind,
                $confirmed->period,
                $actorId,
                $log,
                $verify,
            ),
        };
    }
}
