<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Listeners;

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Support\EngineDefinition;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\EngineRunContext;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

/**
 * Records every compensation-engine invocation into `engine_runs`.
 *
 * Wrapping the commands from the outside — rather than routing all ten through
 * a shared runner — is deliberate: GsbDailyCutoffCommand carries a three-pass
 * orchestration in the command body, and refactoring it purely to gain a run log
 * would be a large, risky change to money-moving code. Listening to the console
 * events also means one writer covers cron runs, developer CLI runs and the
 * Artisan::call the admin chain job makes.
 *
 * Bound as a container singleton so the id map survives between the two events.
 */
final class RecordEngineRun
{
    /**
     * Command signature => stack of in-flight engine_runs ids.
     *
     * @var array<string, list<int>>
     */
    private array $inFlight = [];

    /**
     * Run ids whose engine's feature flag was OFF when the command started.
     * Those commands no-op and exit 0, and must never be recorded as
     * `succeeded`: a succeeded row satisfies EngineStatusService's
     * "period computed" check, and the dependency resolver would then skip a
     * prerequisite that never actually ran — freezing immutable pool economics
     * against missing data once the flags go live.
     *
     * @var array<int, true>
     */
    private array $flagOff = [];

    /**
     * Run id => hrtime(true) at CommandStarting.
     *
     * Wall-clock duration cannot be derived from started_at/finished_at: the
     * compensation replay travels the clock (Carbon::setTestNow) so every
     * replayed run stamps the same instant twice and reads as 0 seconds. hrtime
     * is monotonic and unaffected by the travelled clock.
     *
     * @var array<int, float>
     */
    private array $startedHrtime = [];

    public function starting(CommandStarting $event): void
    {
        $definition = $this->definitionFor($event->command);

        if ($definition === null || $this->isPartialRun($event->input)) {
            return;
        }

        $context = $this->context();

        try {
            $run = EngineRun::create([
                'engine_key' => $definition->key,
                'period_start' => $this->resolvePeriod($definition, $event->input),
                'status' => EngineRun::STATUS_RUNNING,
                'trigger' => $context->trigger(),
                'actor_id' => $context->actorId(),
                'chain_id' => $context->chainId(),
                'started_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            // Never let bookkeeping break a money-moving engine: a missing table
            // (mid-migration) or a locked DB must not abort the run itself.
            $this->logFailure('engine_run.record.start_failed', $definition->key, $e);

            return;
        }

        $this->inFlight[$definition->commandSignature][] = $run->id;
        $this->startedHrtime[$run->id] = hrtime(true);
        $context->beginRun($run->id);

        if ($this->featureFlagIsOff($definition)) {
            $this->flagOff[$run->id] = true;
        }
    }

    public function finished(CommandFinished $event): void
    {
        $definition = $this->definitionFor($event->command);

        if ($definition === null) {
            return;
        }

        $signature = $definition->commandSignature;

        // No matching start row: the command ran before this listener could
        // record it (missing table, partial `--distributor` run, or a boot-time
        // failure). Nothing to close out.
        if (($this->inFlight[$signature] ?? []) === []) {
            return;
        }

        $runId = array_pop($this->inFlight[$signature]);

        if ($this->inFlight[$signature] === []) {
            unset($this->inFlight[$signature]);
        }

        // Immediately after the pop, and before anything that can return early:
        // from here on nothing this process writes belongs to $runId. Nesting is
        // possible (a command invoking another), so restore the enclosing run
        // rather than blanking the attribution outright.
        $this->context()->endRun($this->topOfAnyStack());

        $wasFlagOff = isset($this->flagOff[$runId]);
        unset($this->flagOff[$runId]);

        $durationMs = $this->elapsedMs($runId);

        try {
            if ($wasFlagOff && $event->exitCode === 0) {
                // The command no-opped because its feature flag is off. Recorded
                // as skipped — never succeeded — so the run cannot count as
                // "period computed" for the dependency resolver.
                EngineRun::where('id', $runId)->update([
                    'status' => EngineRun::STATUS_SKIPPED,
                    'summary' => json_encode(['reason' => 'feature_flag_off']),
                    'finished_at' => Carbon::now(),
                    'duration_ms' => $durationMs,
                    'updated_at' => Carbon::now(),
                ]);

                return;
            }

            EngineRun::where('id', $runId)->update([
                'status' => $event->exitCode === 0 ? EngineRun::STATUS_SUCCEEDED : EngineRun::STATUS_FAILED,
                'finished_at' => Carbon::now(),
                'duration_ms' => $durationMs,
                'updated_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            $this->logFailure('engine_run.record.finish_failed', $definition->key, $e);
        }
    }

    /**
     * The innermost run still in flight after a pop, or null when none is.
     *
     * Signatures are keyed separately, so "outer" is the last id of the last
     * non-empty stack: PHP preserves insertion order, and a nested command can
     * only have been started after its parent.
     */
    private function topOfAnyStack(): ?int
    {
        foreach (array_reverse($this->inFlight) as $ids) {
            if ($ids !== []) {
                return $ids[count($ids) - 1];
            }
        }

        return null;
    }

    /** Monotonic milliseconds since this run's CommandStarting, if we saw it. */
    private function elapsedMs(int $runId): ?int
    {
        $startedAt = $this->startedHrtime[$runId] ?? null;
        unset($this->startedHrtime[$runId]);

        return $startedAt === null
            ? null
            : (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function featureFlagIsOff(EngineDefinition $definition): bool
    {
        if ($definition->featureFlagClass === null) {
            return false;
        }

        try {
            return ! Feature::for(null)->active($definition->featureFlagClass);
        } catch (Throwable) {
            // Pennant unavailable (mid-migration boot): assume on rather than
            // mislabel a real run as a no-op.
            return false;
        }
    }

    /**
     * Resolved per event, never captured in the constructor: this listener is a
     * process-lifetime singleton, but EngineRunContext is container-scoped and
     * FLUSHED between queue jobs. A constructor-captured instance would go
     * stale in the queue worker — the listener would read default (console /
     * no actor) attribution while EngineRunService binds the real one on the
     * fresh scoped instance, producing a duplicate, unattributed run row.
     */
    private function context(): EngineRunContext
    {
        return app(EngineRunContext::class);
    }

    private function definitionFor(?string $command): ?EngineDefinition
    {
        return $command === null ? null : EngineRegistry::findBySignature($command);
    }

    /**
     * `--distributor` narrows a cut-off or repurchase evaluation to one person
     * (a developer's surgical re-run). Recording it would make the whole day
     * look computed to the dependency resolver, so those runs stay unlogged.
     */
    private function isPartialRun(InputInterface $input): bool
    {
        $distributor = $input->getParameterOption('--distributor', null);

        return is_string($distributor) && $distributor !== '';
    }

    /**
     * The period the command is actually going to act on: the explicit option
     * when present, otherwise the same default the command itself applies.
     */
    private function resolvePeriod(EngineDefinition $definition, InputInterface $input): Carbon
    {
        $raw = $input->getParameterOption($definition->periodOption, null);

        if (is_string($raw) && trim($raw) !== '') {
            try {
                return $definition->periodStart($definition->parsePeriod($raw));
            } catch (Throwable) {
                // Malformed option — the command will fail on it too; record the
                // run against the default period rather than losing the row.
            }
        }

        return $definition->periodStart($definition->defaultPeriodDate());
    }

    private function logFailure(string $message, string $engineKey, Throwable $e): void
    {
        Log::warning($message, [
            'engine_key' => $engineKey,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
