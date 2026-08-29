<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Compensation\Models\EngineRun;

/**
 * Ambient attribution for the engine run that is about to happen.
 *
 * RecordEngineRun writes the engine_runs row from a console event, where it has
 * no idea *who* asked for the run. EngineRunService binds this container-scoped
 * holder immediately before calling artisan so the listener can stamp the row
 * with the admin, the chain and the trigger; the listener writes the row id back
 * so the service can attach captured console output afterwards.
 *
 * Unbound (the cron / developer-CLI case) it reports a plain console run.
 *
 * Two run ids, deliberately: `activeRunId` is the run currently executing —
 * cleared at CommandFinished so that nothing written *between* runs in the same
 * process (e.g. the recompute replay re-placing orders) is misattributed to the
 * run that just ended. `runId` is the last run started and is kept until
 * `reset()`, because EngineRunService::finalise() reads it after Artisan::call
 * has already returned, to attach the captured console output.
 */
final class EngineRunContext
{
    private string $trigger = EngineRun::TRIGGER_CONSOLE;

    private ?int $actorId = null;

    private ?string $chainId = null;

    private ?int $runId = null;

    private ?int $activeRunId = null;

    public function attribute(string $trigger, ?int $actorId, ?string $chainId): void
    {
        $this->trigger = $trigger;
        $this->actorId = $actorId;
        $this->chainId = $chainId;
        $this->runId = null;
    }

    public function reset(): void
    {
        $this->trigger = EngineRun::TRIGGER_CONSOLE;
        $this->actorId = null;
        $this->chainId = null;
        $this->runId = null;
        $this->activeRunId = null;
    }

    public function trigger(): string
    {
        return $this->trigger;
    }

    public function actorId(): ?int
    {
        return $this->actorId;
    }

    public function chainId(): ?string
    {
        return $this->chainId;
    }

    /** Called by the listener once the engine_runs row exists. */
    public function beginRun(int $runId): void
    {
        $this->runId = $runId;
        $this->activeRunId = $runId;
    }

    /**
     * Called by the listener at CommandFinished. Restores the enclosing run (or
     * null) as the active one; `runId` is deliberately left alone so finalise()
     * can still find the row it has to attach output to.
     */
    public function endRun(?int $outerRunId): void
    {
        $this->activeRunId = $outerRunId;
    }

    /** The run currently executing, if any — what WalletService stamps. */
    public function activeRunId(): ?int
    {
        return $this->activeRunId;
    }

    public function runId(): ?int
    {
        return $this->runId;
    }
}
