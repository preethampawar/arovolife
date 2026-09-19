<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands\Concerns;

use App\Modules\Compensation\Exceptions\BatchIsFrozen;
use App\Modules\Compensation\Exceptions\RebuildStateChanged;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Services\Rebuild\RebuildKind;
use App\Modules\Compensation\Services\Rebuild\RebuildPlan;
use App\Modules\Compensation\Services\Rebuild\RebuildPlanner;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compensation\Support\RepurchaseShortfallGuard;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The body every rebuild command shares: preview → refusals → confirm → wipe →
 * re-run → warnings, with an audit row at each step that changed something.
 *
 * Idempotent by construction. Every attempt wipes the previous attempt's rows
 * before re-running, so "run it again and again" lands on the same figures
 * rather than accumulating them — which is why the confirm is the only gate and
 * there is no attempt counter anywhere.
 *
 * The run row this writes (the rebuild is a registry entry, so RecordEngineRun
 * records it like any engine) is `skipped` when the rebuild refused before
 * writing anything, `failed` when the wipe landed and the re-run did not, and
 * `succeeded` when both did. The middle case is the one that matters: the period
 * then reads "not computed" (D13) and the ordinary scheduled run re-attempts it
 * on its own.
 */
trait RebuildsPeriod
{
    abstract protected function kind(): RebuildKind;

    public function handle(RebuildPlanner $planner, EngineRunContext $context): int
    {
        $kind = $this->kind();
        $definition = EngineRegistry::get($kind->registryKey());
        $period = $this->resolvePeriod();

        if ($period === null) {
            return self::FAILURE;
        }

        $actorId = $this->resolveActor($context);

        if ($actorId === null) {
            return $this->refuse(
                $kind,
                $period,
                null,
                'A rebuild records who decided it; pass --actor=<developer user id>.',
                $context,
            );
        }

        $this->attributeRunToActor($context, $actorId);

        $plan = $planner->plan($kind, $period);

        $this->info(sprintf('%s — %s', $definition->label, $definition->displayPeriod($period)));
        $this->printPlan($plan);

        if ($plan->isRefused()) {
            return $this->refuse($kind, $period, $actorId, implode("\n", $plan->refusals), $context, $plan);
        }

        if (! $this->option('yes') && ! $this->confirm('Wipe these rows and run the period again?', false)) {
            // A decision, and recorded as one. Left as `succeeded` it read as a
            // rebuild that ran, which is the opposite of what happened, and the
            // audit trail lost the fact that somebody looked at this period and
            // chose to leave it alone.
            $declined = 'Declined at the confirm; nothing was written.';

            $this->info('Left as it is.');
            $context->noteSkipped($declined);
            $this->audit('compensation.rebuild.declined', $actorId, $plan, null, ['reason' => $declined]);

            return self::SUCCESS;
        }

        $this->audit('compensation.rebuild.started', $actorId, $plan, AuditDigests::of($plan->rowsToRemove));

        try {
            $result = $planner->execute($plan, $actorId, function (string $message): void {
                $this->line($message);
            });
        } catch (BatchIsFrozen|RebuildStateChanged $e) {
            // Both are decisions the wipe took before writing anything — a batch
            // finance has signed off, or a period that moved under the confirm.
            // An operator reads a refusal; a stack trace would leave the run row
            // `failed` with no terminal record of why.
            return $this->refuse($kind, $period, $actorId, $e->getMessage(), $context);
        }

        $removed = $result->removed;

        $this->audit('compensation.rebuild.wiped', $actorId, $plan, null, [
            'removed' => $removed,
            'adjusted' => $result->adjusted,
            'wallet_totals' => $result->walletTotals,
        ]);

        $exit = $this->rerun($plan, $actorId, $context);

        foreach ($plan->warnings as $warning) {
            $this->warn('After this: '.$warning);
        }

        if ($exit !== 0) {
            $reason = sprintf(
                '%s was wiped (%d rows) but the re-run exited %d. Run this rebuild again once the cause is fixed; '
                .'the next scheduled run also re-attempts the period.',
                $plan->kind->label(),
                array_sum($removed),
                $exit,
            );

            $this->error($reason);
            $context->noteFailed($reason);

            // The wipe landed and the replay did not, so every credit this
            // period funded is now uncovered. This is the branch where a
            // shortfall is MOST likely and it must not be the one that never
            // looks: whoever decides the correction needs the list.
            $shortfalls = $this->reportRepurchaseShortfall($plan, $actorId);

            $this->audit('compensation.rebuild.rerun_failed', $actorId, $plan, null, [
                'removed' => $removed,
                'exit_code' => $exit,
                'reason' => $reason,
                'repurchase_shortfalls' => count($shortfalls),
            ]);

            return self::FAILURE;
        }

        $shortfalls = $this->reportRepurchaseShortfall($plan, $actorId);

        $this->audit('compensation.rebuild.completed', $actorId, $plan, null, [
            'removed' => $removed,
            'warnings' => $plan->warnings,
            'repurchase_shortfalls' => count($shortfalls),
        ]);

        $this->info(sprintf('Rebuilt %s — %d row(s) removed and re-derived.', $plan->periodValue(), array_sum($removed)));

        return self::SUCCESS;
    }

    /** The period this rebuild is for, or null when the option cannot be read. */
    private function resolvePeriod(): ?Carbon
    {
        $definition = EngineRegistry::get($this->kind()->registryKey());
        $raw = $this->option(ltrim($definition->periodOption, '-'));

        if (! is_string($raw) || trim($raw) === '') {
            return $definition->defaultPeriodDate();
        }

        try {
            return $definition->parsePeriod($raw);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return null;
        }
    }

    /**
     * Who decided this rebuild — a user holding the `developer` role, and
     * nobody else.
     *
     * `--actor` is how a shell names them, because a command typed at a prompt
     * carries no session; the queued job binds the same id on the run context
     * instead. A rebuild that cannot name a developer does not run: it deletes
     * derived money rows in production, and a deletion nobody is recorded as
     * having decided is exactly what the audit trail exists to prevent.
     */
    private function resolveActor(EngineRunContext $context): ?int
    {
        // `is_numeric`, not `is_string`: an id arrives as a string from a shell
        // and as an int from `Artisan::call`, and a rebuild that silently lost
        // its actor because of the difference would refuse with a message about
        // an option the caller did pass.
        $option = $this->option('actor');
        $actorId = is_numeric($option) ? (int) $option : $context->actorId();

        if ($actorId === null || $actorId <= 0) {
            return null;
        }

        return User::find($actorId)?->hasRole('developer') === true ? $actorId : null;
    }

    private function printPlan(RebuildPlan $plan): void
    {
        if ($plan->rowsToRemove === []) {
            $this->line('Nothing to remove for this period.');
        } else {
            $this->table(
                ['Table', 'Rows removed'],
                array_map(
                    static fn (string $table, int $count): array => [$table, (string) $count],
                    array_keys($plan->rowsToRemove),
                    array_values($plan->rowsToRemove),
                ),
            );
        }

        if ($plan->unsweeps > 0) {
            $this->line(sprintf('Wallet credits un-swept (never deleted): %d', $plan->unsweeps));
        }

        foreach ($plan->adjustments as $table => $count) {
            $this->line(sprintf('Corrected in place, not deleted: %s — %d row(s)', $table, $count));
        }

        $this->line('Then: php artisan '.$plan->rerunCommand());

        foreach ($plan->warnings as $warning) {
            $this->warn('After this: '.$warning);
        }

        foreach ($plan->refusals as $refusal) {
            $this->error($refusal);
        }
    }

    /**
     * Run the period's ordinary command with the rebuild's actor bound, so every
     * nested engine row names the developer who decided it rather than reading
     * as a cron job.
     *
     * The previous attribution is RESTORED rather than reset: a reset would also
     * blank the active run id, and this command's own `noteFailed()` a few lines
     * later would then have no row to land on.
     */
    private function rerun(RebuildPlan $plan, int $actorId, EngineRunContext $context): int
    {
        $previous = [$context->trigger(), $context->actorId(), $context->chainId()];
        $context->attribute(EngineRun::TRIGGER_MANUAL, $actorId, $previous[2] ?? (string) Str::uuid());

        // This process IS the rebuild, so the orchestrator's rebuild-in-flight
        // guard must not refuse on this rebuild's own still-`running` row. The
        // guard it protects is against a SECOND process; restored in `finally`
        // so it never outlives the call.
        $context->enterRebuild($context->activeRunId());

        [$signature, $options] = $plan->kind->rerunCall($plan->period);

        try {
            $exitCode = Artisan::call($signature, $options);
        } catch (Throwable $e) {
            Log::error('compensation.rebuild.rerun_crashed', [
                'kind' => $plan->kind->value,
                'period' => $plan->periodValue(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            $this->error(sprintf('%s threw %s: %s', $signature, $e::class, $e->getMessage()));

            return self::FAILURE;
        } finally {
            $context->enterRebuild(null);
            $context->attribute($previous[0], $previous[1], $previous[2]);
        }

        $output = trim(Artisan::output());

        if ($output !== '') {
            $this->line($output);
        }

        return $exitCode;
    }

    /** A refusal writes nothing, records the run as `skipped` and says why. */
    private function refuse(
        RebuildKind $kind,
        Carbon $period,
        ?int $actorId,
        string $reason,
        EngineRunContext $context,
        ?RebuildPlan $plan = null,
    ): int {
        $this->error($reason);
        $context->noteSkipped($reason);

        $this->writeAudit('compensation.rebuild.refused', $actorId, null, [
            'kind' => $kind->value,
            'period' => EngineRegistry::get($kind->registryKey())->formatPeriod($period),
            'refusals' => $plan === null ? [$reason] : $plan->refusals,
        ]);

        return self::FAILURE;
    }

    /**
     * Compare the repurchase positions this rebuild touched against where they
     * stood before it, and record anyone left below zero.
     *
     * A shortfall is not a refusal: the discount left on a real order long ago,
     * and refusing would only block the correction of everything else. But
     * {@see WalletService::repurchaseWalletBalancePaise()}
     * floors the balance at zero, so unreported it would never surface at all.
     * The row detects; a human still has to post the correction.
     *
     * @return array<int, array{before: int, after: int}>
     */
    private function reportRepurchaseShortfall(RebuildPlan $plan, int $actorId): array
    {
        $after = RepurchaseShortfallGuard::positions(array_keys($plan->repurchasePositions));
        $shortfalls = RepurchaseShortfallGuard::shortfalls($plan->repurchasePositions, $after);

        if (($warning = RepurchaseShortfallGuard::warning($shortfalls)) === null) {
            return [];
        }

        $total = array_sum(array_map(static fn (array $p): int => -$p['after'], $shortfalls));

        // Not noteSkipped/noteFailed on the success path: the rebuild worked.
        // Logged as well as audited because this row is now the only durable
        // record of a money-affecting condition, and writeAudit() swallows a
        // failed insert.
        $this->warn($warning);
        Log::warning('compensation.rebuild.repurchase_shortfall', [
            'period' => $plan->periodValue(),
            'shortfalls_paise' => $shortfalls,
            'total_paise' => $total,
        ]);
        $this->audit('compensation.rebuild.repurchase_shortfall', $actorId, $plan, null, [
            'shortfalls_paise' => $shortfalls,
            'total_paise' => $total,
        ]);

        return $shortfalls;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function audit(string $action, int $actorId, RebuildPlan $plan, ?string $before = null, array $details = []): void
    {
        $this->writeAudit($action, $actorId, $before, [
            'kind' => $plan->kind->value,
            'period' => $plan->periodValue(),
            'rows_to_remove' => $plan->rowsToRemove,
            'unsweeps' => $plan->unsweeps,
            'adjustments' => $plan->adjustments,
            'warnings' => $plan->warnings,
            'rerun_command' => $plan->rerunCommand(),
            ...$details,
        ]);
    }

    /**
     * Stamp the rebuild's OWN run row with the developer who asked for it.
     *
     * {@see RecordEngineRun} opens that row at `CommandStarting`, before
     * `--actor` has been parsed, so from a shell it reads `console` with no
     * actor — the one row on the Engine Runs page that must name a person. The
     * nested engine rows the re-run writes already do, because
     * {@see self::rerun()} binds the context before calling them.
     *
     * Bookkeeping, so a failure here is logged and swallowed rather than
     * aborting a rebuild that is otherwise fine.
     */
    private function attributeRunToActor(EngineRunContext $context, int $actorId): void
    {
        $runId = $context->activeRunId();

        if ($runId === null) {
            return;
        }

        try {
            EngineRun::query()->whereKey($runId)->update([
                'trigger' => EngineRun::TRIGGER_MANUAL,
                'actor_id' => $actorId,
            ]);
        } catch (Throwable $e) {
            Log::error('compensation.rebuild.run_attribution_failed', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The audit row is the durable record of a deletion, but losing it must not
     * turn a rebuild that ran into a rebuild that crashed — the same rule
     * {@see OrchestratesEngineSteps::abortRun()} applies.
     *
     * @param  array<string, mixed>  $details
     */
    private function writeAudit(string $action, ?int $actorId, ?string $before, array $details): void
    {
        try {
            AuditLog::create([
                'actor_id' => $actorId,
                'action' => $action,
                'subject_type' => 'engine',
                'subject_id' => 0,
                'before_hash' => $before,
                'after_hash' => null,
                'details' => $details,
                'ip' => null,
            ]);
        } catch (Throwable $e) {
            Log::error('compensation.rebuild.audit_failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
