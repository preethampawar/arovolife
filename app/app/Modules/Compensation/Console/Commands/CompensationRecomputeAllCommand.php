<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Jobs\RecomputeAllJob;
use App\Modules\Compensation\Services\IncomeEligibilityService;
use App\Modules\Compensation\Services\Recompute\CompensationRecomputeRunner;
use App\Modules\Compensation\Services\Recompute\EngineReplayService;
use App\Modules\Compensation\Services\Recompute\RecomputeGuard;
use App\Modules\Compensation\Services\Recompute\RecomputeHorizon;
use App\Modules\Compensation\Services\Recompute\RecomputeNotPermitted;
use App\Modules\Compensation\Services\Recompute\RecomputeProgress;
use App\Modules\Compensation\Services\Recompute\RecomputeState;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The command line half of the compensation recompute — and the whole of the
 * nightly reset.
 *
 * On a test environment this is how compensation is computed: one command that
 * wipes the derived rows and replays every engine at the instant the scheduler
 * would have fired it, up to a {@see RecomputeHorizon}. The admin button runs
 * the same {@see CompensationRecomputeRunner} on the queue; this runs it inline
 * so a terminal (and cron) can wait for it.
 *
 * `--if-projected` is what makes the nightly reset safe to schedule: it does
 * nothing at all unless the environment is standing on simulated figures, so a
 * database that is already production-faithful is never wiped and rebuilt for
 * no reason.
 *
 * Refuses in production, unconditionally — {@see RecomputeGuard}.
 */
final class CompensationRecomputeAllCommand extends Command
{
    protected $signature = 'compensation:recompute-all
                            {--horizon=now : How far to replay the scheduler — now | today | projection}
                            {--from= : First day to replay (YYYY-MM-DD, default: the first BV date)}
                            {--windowed : Keep the history before --from instead of wiping it}
                            {--only=* : Replay only these engine keys (repeatable)}
                            {--if-projected : Do nothing unless this environment holds simulated figures}
                            {--force : Skip the typed database confirmation}';

    protected $description = 'TEST ENVIRONMENTS ONLY — wipe every BV-derived row and replay the engines at the scheduler\'s own clock';

    public function __construct(
        private readonly RecomputeGuard $guard,
        private readonly RecomputeState $state,
        private readonly RecomputeProgress $progress,
        private readonly CompensationRecomputeRunner $runner,
        private readonly IncomeEligibilityService $eligibility,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $this->guard->ensurePermitted();
        } catch (RecomputeNotPermitted $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $horizon = RecomputeHorizon::tryFrom((string) $this->option('horizon'));

        if ($horizon === null) {
            $this->error(sprintf(
                '--horizon must be one of: %s.',
                implode(', ', RecomputeHorizon::values()),
            ));

            return self::FAILURE;
        }

        if ($this->option('if-projected') && ! $this->state->isProjected()) {
            $this->line('Nothing to reset: this environment is not holding simulated compensation figures.');

            return self::SUCCESS;
        }

        $from = $this->resolveFrom();

        if ($from === false) {
            return self::FAILURE;
        }

        $only = $this->resolveOnly();

        if ($only === false) {
            return self::FAILURE;
        }

        if (! $this->confirmTarget($horizon)) {
            $this->line('Aborted.');

            return self::FAILURE;
        }

        // The same lock the queued job takes: two concurrent replays would
        // interleave their day loops and destroy the carry-forward chain.
        $lock = Cache::lock(RecomputeAllJob::LOCK_KEY, 7200);

        if (! $lock->get()) {
            $this->error(RecomputeNotPermitted::alreadyRunning()->getMessage());

            return self::FAILURE;
        }

        $this->progress->queued();
        $this->auditQueued($horizon);

        try {
            $report = $this->runner->run(
                from: $from,
                horizon: $horizon,
                actorUserId: null,
                progress: function (string $message): void {
                    $this->line($message);
                },
                onlyEngineKeys: $only,
                windowed: (bool) $this->option('windowed'),
            );
        } catch (Throwable $e) {
            Log::error('compensation.recompute.failed', ['error' => $e->getMessage()]);
            $this->progress->fail($e->getMessage());
            $this->error('Recompute failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        $this->newLine();
        $this->table(['', ''], [
            ['Window', $report->windowLabel()],
            ['Horizon', $report->horizon->value],
            ['Simulated through', $report->simulatedThrough?->format('d M Y H:i') ?? '— nothing simulated'],
            ['Rows replaced', number_format($report->totalRowsRemoved())],
            ['Orders re-propagated', number_format($report->ordersPropagated)],
            ['Days replayed', number_format($report->daysReplayed)],
            ['Engine runs', number_format($report->totalEngineRuns())],
            ['Duration', $report->durationSeconds.'s'],
        ]);

        foreach ($report->warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }

    /**
     * Record what was asked for BEFORE the wipe starts.
     *
     * Two reasons, both learned the hard way. The banner and the scheduler pause
     * read this row, so a CLI projection that only audited on completion left
     * the environment rendering half-rebuilt figures with no disclosure for the
     * minutes the replay ran. And the row has to name a human: the admin path
     * records `auth()->id()`, but a command line has no session, so it records
     * the shell account and host instead — which is a true answer, and for the
     * nightly cron it is the deploy user.
     */
    private function auditQueued(RecomputeHorizon $horizon): void
    {
        AuditLog::create([
            'actor_id' => null,
            'action' => 'compensation.recompute_all.queued',
            'subject_type' => 'platform',
            'subject_id' => 0,
            'details' => [
                'note' => 'Compensation recompute started from the command line.',
                'horizon' => $horizon->value,
                'simulated_through' => $horizon->isProjected()
                    ? $horizon->instantFrom(Carbon::now())->toDateTimeString()
                    : null,
                'invoked_via' => 'cli',
                'os_user' => get_current_user(),
                'host' => gethostname(),
                'scheduled' => (bool) $this->option('if-projected'),
            ],
        ]);

        $this->state->forget();
    }

    /** The parsed `--from`, null for "everything", or false when it is malformed. */
    private function resolveFrom(): Carbon|null|false
    {
        $raw = trim((string) ($this->option('from') ?? ''));

        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            $this->error("--from must be in YYYY-MM-DD format, got: {$raw}");

            return false;
        }

        return Carbon::createFromFormat('Y-m-d', $raw)->startOfDay();
    }

    /**
     * The validated `--only` engine keys, null for "every engine", or false when
     * one of them is not an engine.
     *
     * @return list<string>|null|false
     */
    private function resolveOnly(): array|null|false
    {
        /** @var list<string> $only */
        $only = array_values(array_filter((array) $this->option('only')));

        if ($only === []) {
            return null;
        }

        $unknown = array_values(array_diff($only, EngineRegistry::keys()));

        if ($unknown !== []) {
            $this->error(sprintf(
                'Unknown engine key(s): %s. Known keys: %s',
                implode(', ', $unknown),
                implode(', ', EngineRegistry::keys()),
            ));

            return false;
        }

        // The wipe deletes this window's repurchase cycles whatever is selected,
        // and the guarded engines then refuse to run without them — aborting the
        // replay partway and leaving the database half-rebuilt. Refuse the
        // selection here instead, before a single row is deleted.
        $guarded = EngineReplayService::guardedEnginesMissingEvaluate($only);

        if ($guarded !== [] && $this->eligibility->engineActive()) {
            $this->error(sprintf(
                '%s cannot be replayed without repurchase.evaluate while the repurchase engine is on: each reads '
                    .'the repurchase verdict this replay is about to delete, and refuses to run until it has been '
                    ."rebuilt.\nAdd --only=repurchase.evaluate as well, or drop --only to replay every engine.",
                implode(' and ', $guarded),
            ));

            return false;
        }

        return $only;
    }

    /**
     * Make the operator name the database before it is destroyed.
     *
     * The fourth lock of the four in the runbook, and the only one a machine
     * cannot open for itself: `--force` (and `--no-interaction`, which cron
     * uses) skips it, which is why the other three are answerable from the
     * environment and the connected database rather than from intent.
     */
    private function confirmTarget(RecomputeHorizon $horizon): bool
    {
        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        $database = $this->guard->targetDatabase();

        $this->warn(sprintf(
            'This deletes every BV-derived row on `%s` (connection `%s`) and rebuilds it: %s',
            $database,
            $this->guard->targetConnection(),
            $horizon->describe(Carbon::now()),
        ));

        return $this->ask(sprintf('Type the database name (%s) to continue', $database)) === $database;
    }
}
