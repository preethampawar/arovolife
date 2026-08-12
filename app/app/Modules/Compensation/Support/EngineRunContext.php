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
 */
final class EngineRunContext
{
    private string $trigger = EngineRun::TRIGGER_CONSOLE;

    private ?int $actorId = null;

    private ?string $chainId = null;

    private ?int $runId = null;

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
    public function recordRunId(int $runId): void
    {
        $this->runId = $runId;
    }

    public function runId(): ?int
    {
        return $this->runId;
    }
}
