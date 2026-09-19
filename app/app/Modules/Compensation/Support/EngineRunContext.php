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

    /**
     * The rebuild run whose re-run this process is currently executing.
     *
     * A rebuild invokes the ordinary run for its period through
     * `Artisan::call`, in its own process, while its OWN `engine_runs` row is
     * still `running`. Without this the orchestrator's rebuild-in-flight guard
     * ({@see RunPrerequisites::rebuildInFlightRefusal()}) would refuse on the
     * sight of the very rebuild that asked for it — the race it exists to
     * prevent is two processes, never a command and the command it called.
     */
    private ?int $rebuildRunId = null;

    /**
     * Run id => the outcome the engine itself declared before exiting.
     *
     * CommandFinished carries an exit code and nothing else, so a command that
     * refuses (a payout batch dated a non-Tuesday) and a command that crashed
     * both close their row as `failed` with no reason — the shape QA found on
     * all 49 staging rows. An engine that knows why it is stopping says so
     * here, and RecordEngineRun writes it onto the row it is about to close.
     *
     * @var array<int, array{status: string, reason: string}>
     */
    private array $outcomes = [];

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
        $this->rebuildRunId = null;
        $this->outcomes = [];
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

    /**
     * Declare (or clear) the rebuild this process is running a period again
     * for. Set immediately before the nested `Artisan::call` and restored
     * afterwards, so it never outlives the call it describes.
     */
    public function enterRebuild(?int $rebuildRunId): void
    {
        $this->rebuildRunId = $rebuildRunId;
    }

    /** {@see $rebuildRunId} — the run row a rebuild-in-flight guard must ignore. */
    public function rebuildRunId(): ?int
    {
        return $this->rebuildRunId;
    }

    public function runId(): ?int
    {
        return $this->runId;
    }

    /**
     * The engine decided not to run and said why — a deliberate refusal, not a
     * failure. Recorded as `skipped`, the same status a flag-off no-op gets, so
     * the health digest stops reporting a refusal the engine can never satisfy
     * on that period (a weekly payout dated a Wednesday will never be a
     * Tuesday) as an unresolved failure for thirty days.
     */
    public function noteSkipped(string $reason): void
    {
        $this->note(EngineRun::STATUS_SKIPPED, $reason);
    }

    /** The engine failed and knows the message — an exception, or a broken step. */
    public function noteFailed(string $reason): void
    {
        $this->note(EngineRun::STATUS_FAILED, $reason);
    }

    /**
     * Read and clear the outcome the engine declared for this run, if any.
     *
     * @return array{status: string, reason: string}|null
     */
    public function takeOutcome(int $runId): ?array
    {
        $outcome = $this->outcomes[$runId] ?? null;
        unset($this->outcomes[$runId]);

        return $outcome;
    }

    private function note(string $status, string $reason): void
    {
        // No active run means nothing recorded this invocation (a partial
        // `--distributor` run, or a command outside the registry). The note has
        // no row to land on and is dropped rather than leaking to the next one.
        if ($this->activeRunId === null) {
            return;
        }

        $this->outcomes[$this->activeRunId] = ['status' => $status, 'reason' => $reason];
    }
}
