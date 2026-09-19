<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Services\Recompute\RecomputeState;
use App\Modules\Compensation\Support\EngineDefinition;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\RunClock;
use Illuminate\Support\Carbon;

/**
 * Builds the {@see RunClock} an admin surface shows: the last fire from the run
 * log, the next from the cadence, and whether the scheduler will honour it.
 *
 * Separate from {@see EngineStatusService} on purpose — that service answers
 * "has this period been computed" for the engines themselves and is
 * constructed in console commands and jobs, where a cache- and audit-log-
 * reading dependency has no business being.
 */
final class RunClockService
{
    /**
     * Memoised: {@see RecomputeState::schedulerEnginesAllowed()} reads the
     * audit log uncached by design, and the Engine Runs page builds a clock for
     * every engine on it.
     */
    private ?bool $schedulerPaused = null;

    public function __construct(
        private readonly EngineStatusService $status,
        private readonly RecomputeState $recompute,
    ) {}

    /** The clock for one engine, reading its last run from the log. */
    public function for(string $key): RunClock
    {
        return $this->from(EngineRegistry::get($key), $this->status->lastRun($key));
    }

    /**
     * The same clock for a caller that already holds the run — the Engine Runs
     * index loads every engine's last run in one query and must not fan back
     * out into one per card.
     */
    public function from(EngineDefinition $definition, ?EngineRun $lastRun): RunClock
    {
        return new RunClock(
            $definition,
            $lastRun,
            $definition->nextRunAfter(Carbon::now('Asia/Kolkata')),
            $this->schedulerPaused(),
        );
    }

    private function schedulerPaused(): bool
    {
        return $this->schedulerPaused ??= ! $this->recompute->schedulerEnginesAllowed();
    }
}
