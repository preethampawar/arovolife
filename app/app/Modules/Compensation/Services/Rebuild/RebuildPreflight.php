<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Rebuild;

use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Services\Recompute\RecomputeState;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compensation\Support\WorkerFreshness;

/**
 * The refusals every rebuild shares, whatever period it is for.
 *
 * NOT an environment gate. `compensation:recompute-all` refuses production by
 * design (ADR-0014) because it replays history wholesale; a rebuild replays one
 * period through the ordinary engines and is the sanctioned production repair
 * for a run that failed part-way (D4). Nothing here reads the environment.
 *
 * What it does refuse is state a rebuild cannot reason about: a standing
 * recompute projection (the derived rows belong to a clock that has not
 * arrived), a worker running pre-deploy code, and a scheduled run or another
 * rebuild in flight — wiping a period out from under the process computing it
 * is the one race the whole design exists to avoid.
 */
final class RebuildPreflight
{
    public function __construct(
        private readonly RecomputeState $state,
        private readonly EngineStatusService $status,
        private readonly EngineRunContext $context,
    ) {}

    /** @return list<string> */
    public function refusals(): array
    {
        $refusals = [];

        if (! $this->state->schedulerEnginesAllowed()) {
            $refusals[] = 'A recompute projection is standing (or a replay is in flight) on this environment, so the '
                .'engines are paused: a rebuild would compute this period on top of rows that belong to a clock that '
                ."has not arrived.\nWait for the 23:30 reset, or run "
                .'php artisan compensation:recompute-all --horizon=now to return this environment to '
                .'production-faithful first.';
        }

        if (($stale = WorkerFreshness::staleReason()) !== null) {
            $refusals[] = $stale;
        }

        foreach ([...EngineRegistry::rootOrchestratorKeys(), ...EngineRegistry::rebuildKeys()] as $key) {
            // Except this rebuild's own row. A rebuild command IS a registry
            // entry, so RecordEngineRun has already opened a `running` row for it
            // before handle() gets here — without the exclusion every rebuild
            // would refuse on the sight of itself.
            if ($this->status->hasRunInFlight($key, $this->context->activeRunId())) {
                $refusals[] = sprintf(
                    '%s is running right now; a rebuild beside it would race the scheduler. Wait for it to finish.',
                    EngineRegistry::get($key)->label,
                );
            }
        }

        return $refusals;
    }
}
