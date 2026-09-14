<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Console\Actions\PurchaseDataResetAction;
use App\Console\Actions\PurchaseResetBlocked;
use App\Modules\Compensation\Jobs\RecomputeAllJob;
use App\Modules\Compensation\Jobs\RunEngineChainJob;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\EngineChainResolver;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Services\Recompute\CompensationStateWiper;
use App\Modules\Compensation\Services\Recompute\RecomputeGuard;
use App\Modules\Compensation\Services\Recompute\RecomputeHorizon;
use App\Modules\Compensation\Services\Recompute\RecomputeProgress;
use App\Modules\Compensation\Services\Recompute\RecomputeState;
use App\Modules\Compensation\Support\EngineDefinition;
use App\Modules\Compensation\Support\EnginePeriodType;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Pennant\Feature;

/**
 * The Engine Runs page: every compensation engine, its last run, and a manual
 * trigger that queues the engine together with the prerequisites it needs.
 *
 * Complements Manual Controls: Manual Controls fixes one distributor, Engine
 * Runs executes a whole engine for a whole period. The POST is finance-gated
 * because engine runs create wallet credits.
 */
final class AdminEngineRunsController extends Controller
{
    public function __construct(
        private readonly EngineStatusService $status,
        private readonly EngineChainResolver $resolver,
        private readonly RecomputeGuard $recomputeGuard,
        private readonly CompensationStateWiper $wiper,
        private readonly RecomputeProgress $recomputeProgress,
        private readonly RecomputeState $recomputeState,
    ) {}

    public function index(Request $request): View
    {
        $definitions = EngineRegistry::all();
        $lastRuns = $this->status->lastRunPerEngine(EngineRegistry::keys());

        $engines = [];
        foreach ($definitions as $key => $definition) {
            $flagOn = $definition->featureFlagClass === null
                ? null
                : Feature::for(null)->active($definition->featureFlagClass);

            // A disabled feature must leave no trace on any admin surface, so
            // a flag-off engine disappears from the page until re-enabled.
            if ($flagOn === false) {
                continue;
            }

            $lastRun = $lastRuns[$key] ?? null;

            $engines[] = [
                'definition' => $definition,
                'flagOn' => $flagOn,
                'lastRun' => $lastRun,
                // Bootstrap fallback: before the run log has history, the
                // engine's own result tables are the only proof it ever ran.
                'derivedPeriod' => $lastRun === null ? $this->status->lastComputedPeriod($key) : null,
                // Flag-off prerequisites are skipped at runtime by the chain
                // resolver, so their chips are hidden here too.
                'dependencyLabels' => array_values(array_map(
                    static fn (array $dependency): string => EngineRegistry::get($dependency['key'])->label,
                    array_filter(
                        $definition->dependencies,
                        static function (array $dependency): bool {
                            $flagClass = EngineRegistry::get($dependency['key'])->featureFlagClass;

                            return $flagClass === null || Feature::for(null)->active($flagClass);
                        },
                    ),
                )),
                'defaultPeriodValue' => $this->periodInputValue($definition),
                'maxPeriodValue' => $this->periodInputMax($definition),
            ];
        }

        // The destructive testing cards are super-staff-only (`developer` or
        // `admin`) ON TOP of the guard. The guard answers for the environment;
        // it cannot answer for the reader, and the scoped roles —
        // `admin-finance`, `admin-compliance`, `admin-operations` — all reach
        // this page too. A route-level `role:developer|admin` gate covers the
        // POSTs; this decides whether the cards — and the
        // database name and row counts printed on them — exist at all.
        $destructiveToolsVisible = $this->destructiveToolsAllowed($request);

        // On a test environment the recompute IS the runner: it replays every
        // engine at the instant the scheduler would have fired it, which is what
        // makes the figures match production. A manual trigger fires one engine
        // at the wrong instant — the 24 Aug premature freeze, and the 14 Sep
        // month-end-wallet bug, were both that — so the buttons are not rendered
        // there at all. Production is untouched: the guard is always shut.
        $manualTriggersDisabled = $this->recomputeGuard->isPermitted();

        return view('admin.compensation.engine-runs.index', [
            'engines' => $engines,
            'manualTriggersDisabled' => $manualTriggersDisabled,
            'destructiveToolsVisible' => $destructiveToolsVisible,
            'recomputeTargetDatabase' => $this->recomputeGuard->targetDatabase(),
            'recomputeRowCounts' => $destructiveToolsVisible ? $this->wiper->preview() : [],
            'purchaseResetRowCounts' => $destructiveToolsVisible
                ? app(PurchaseDataResetAction::class)->preview()
                : [],
            // The one choice a recompute offers: how far along the scheduler's
            // calendar to replay. Everything else the run needs it works out
            // for itself.
            'recomputeHorizons' => $destructiveToolsVisible
                ? array_map(
                    static fn (RecomputeHorizon $horizon): array => [
                        'value' => $horizon->value,
                        'label' => $horizon->label(Carbon::now()),
                        'description' => $horizon->describe(Carbon::now()),
                        'projected' => $horizon->isProjected(),
                    ],
                    RecomputeHorizon::cases(),
                )
                : [],
            'projectedThrough' => $this->recomputeState->projectedThrough(),
        ]);
    }

    /**
     * TEST ENVIRONMENTS ONLY — queue a full wipe-and-replay of every BV-derived
     * row, up to the chosen {@see RecomputeHorizon}.
     *
     * Dispatches rather than running inline: the replay takes minutes, and a
     * request timeout halfway through would leave the database wiped and only
     * partly rebuilt. The command line takes the window options (`--from`,
     * `--windowed`, `--only`); the page deliberately offers only the horizon,
     * because a partial rebuild is a debugging tool rather than a way to run an
     * environment.
     */
    public function recomputeAll(Request $request): RedirectResponse
    {
        abort_unless($this->destructiveToolsAllowed($request), 404);

        $validated = $request->validate([
            'horizon' => ['required', 'string', Rule::in(RecomputeHorizon::values())],
        ]);

        $horizon = RecomputeHorizon::fromValue($validated['horizon']);

        // The lock alone cannot tell a live run from one that died holding it: a
        // worker killed mid-replay never reaches its finally, so the lock sits
        // there for its full two-hour TTL and refuses every retry — exactly when
        // a retry is the only way to finish rebuilding the wiped database. The
        // heartbeat is the tiebreaker; a run that has stopped reporting is dead,
        // and its lock is debris to clear rather than an owner to wait for.
        if (Cache::lock(RecomputeAllJob::LOCK_KEY)->get() === false && $this->recomputeProgress->isRunning()) {
            return redirect()->route('admin.compensation.engine-runs.index')
                ->with('error', 'A compensation recompute is already running. Wait for it to finish.');
        }

        // Either the probe above acquired the lock or it found abandoned debris.
        // The job takes the real lock for its own lifetime, so clear this one.
        Cache::lock(RecomputeAllJob::LOCK_KEY)->forceRelease();

        // Publish the queued state before dispatching, so the redirected page
        // shows this run instead of the previous run's finished summary.
        $this->recomputeProgress->queued();

        $actorId = auth()->id();
        RecomputeAllJob::dispatch(
            actorUserId: is_numeric($actorId) ? (int) $actorId : null,
            horizon: $horizon->value,
        );

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'compensation.recompute_all.queued',
            'subject_type' => 'platform',
            'subject_id' => 0,
            // Queueing changes nothing yet; the after digest pins exactly what
            // was asked for, which is what a later replay is judged against.
            'before_hash' => null,
            'after_hash' => AuditDigests::of([
                'horizon' => $horizon->value,
                'mode' => 'full',
            ]),
            'details' => [
                'note' => 'Test-environment compensation recompute queued from the admin console.',
                'horizon' => $horizon->value,
                'simulated_through' => $horizon->isProjected()
                    ? $horizon->instantFrom(Carbon::now())->toDateTimeString()
                    : null,
                'mode' => 'full',
            ],
        ]);

        // The banner, and the scheduler pause behind it, read the audit row this
        // request just wrote — so the redirected page must not answer from a
        // cache taken before it.
        $this->recomputeState->forget();

        return redirect()->route('admin.compensation.engine-runs.index')
            ->with('status', $horizon->isProjected()
                ? sprintf(
                    'Recompute queued — projecting through %s. Every BV-derived row is being wiped and rebuilt, and '
                        .'the figures after now will be simulated until the nightly reset.',
                    $horizon->instantFrom(Carbon::now())->format('d M Y'),
                )
                : 'Recompute queued, up to now. Every BV-derived row is being wiped and rebuilt exactly as the '
                    .'scheduler would have produced it — the runs below will repopulate as the replay progresses.');
    }

    /**
     * TESTING ONLY — wipe the purchases themselves along with everything
     * derived from them, so a plan can be tested from an empty slate.
     *
     * Distinct from a recompute in exactly one way that matters: this removes
     * the orders, so there is nothing left to recompute FROM. Users,
     * distributors, the Genos tree, KYC, consents, the catalog and every plan
     * setting survive — see PurchaseDataResetAction for the full list.
     *
     * Runs inline: it is a series of truncates, not a replay, and finishes well
     * inside a request. Removed with the recompute scaffold at sign-off.
     */
    public function resetPurchaseData(Request $request, PurchaseDataResetAction $reset): RedirectResponse
    {
        abort_unless($this->destructiveToolsAllowed($request), 404);

        $actorId = auth()->id();
        $actorId = is_numeric($actorId) ? (int) $actorId : null;

        // Checked before validation and the audit-log "requested" entry below:
        // refuse before anything else is written, not after.
        try {
            $reset->ensureOutsideEngineWindow($actorId, 'the admin Engine Runs console');
        } catch (PurchaseResetBlocked $e) {
            return redirect()->route('admin.compensation.engine-runs.index')
                ->with('error', $e->getMessage());
        }

        $validated = $request->validate([
            'confirm_database' => ['required', 'string', Rule::in([$this->recomputeGuard->targetDatabase()])],
        ], [
            'confirm_database.in' => 'Type the database name exactly to confirm.',
        ]);

        // Unlike a recompute, this one must NOT steal a lock it cannot get. A
        // recompute that trips over a stale lock is recoverable by re-running;
        // truncating the orders out from under a live replay is not, because
        // the source rows it was rebuilding from are gone for good. So take the
        // lock properly and refuse if it is held — a genuinely abandoned lock is
        // cleared by the recompute button, which is safe to force.
        $lock = Cache::lock(RecomputeAllJob::LOCK_KEY, 900);

        if (! $lock->get()) {
            return redirect()->route('admin.compensation.engine-runs.index')
                ->with('error', 'A compensation recompute holds the replay lock. Wait for it to finish '
                    .'(or clear a dead run by starting a recompute) before resetting purchase data.');
        }

        $removed = $reset->preview();

        // Written BEFORE the truncation, and deliberately not inside the action:
        // this destroys cooling-off windows, invoices, buyback evidence and the
        // TDS trail, so the record of WHO ordered it and WHAT was standing at the
        // time has to survive even if the truncation dies half-way through.
        // `audit_log` is not in the wipe list, so this entry outlives the data.
        AuditLog::create([
            'actor_id' => $actorId,
            'action' => 'platform.purchase_reset.requested',
            'subject_type' => 'platform',
            'subject_id' => 0,
            // The rows still standing when the reset was asked for — the
            // before-state the wipe is measured against.
            'before_hash' => AuditDigests::of($removed),
            'after_hash' => null,
            'details' => [
                'note' => 'Testing-only purchase-data reset requested from the admin console.',
                'database' => $this->recomputeGuard->targetDatabase(),
                'connection' => $this->recomputeGuard->targetConnection(),
                'confirmed_database' => $validated['confirm_database'],
                'rows_standing' => $removed,
                'rows_standing_total' => array_sum($removed),
            ],
        ]);

        try {
            $reset->execute(
                progress: static function (string $message): void {
                    Log::info('platform.purchase_reset', ['message' => $message]);
                },
                actorId: $actorId,
                provenance: 'the admin Engine Runs console',
            );
        } finally {
            $lock->release();
        }

        // The old run's summary describes rows that no longer exist.
        $this->recomputeProgress->clear();

        return redirect()->route('admin.compensation.engine-runs.index')
            ->with('status', sprintf(
                'Purchase data reset — %s row(s) removed across %d table(s). Distributors, the Genos '
                .'tree and every plan setting are untouched; place new orders to start a fresh test.',
                number_format(array_sum($removed)),
                count($removed),
            ));
    }

    /**
     * Live progress for a running recompute, polled by the Engine Runs page.
     *
     * Read-only and cheap — it reads one cache key, never the database, which
     * matters because the replay is mid-truncation for part of its life.
     */
    public function recomputeProgress(Request $request): JsonResponse
    {
        abort_unless($this->destructiveToolsAllowed($request), 404);

        return response()->json($this->recomputeProgress->read() ?? ['state' => 'idle']);
    }

    /**
     * Both testing tools destroy data, and both print the target database name
     * and its row counts before they do. The environment guard is necessary and
     * not sufficient: it says the DATA may be destroyed here, never that THIS
     * reader may destroy it. Enforced in the controller as well as on the route
     * so either gate alone is enough.
     */
    private function destructiveToolsAllowed(Request $request): bool
    {
        return $this->recomputeGuard->isPermitted()
            && $request->user()?->hasAnyRole(['developer', 'admin']) === true;
    }

    public function events(Request $request): View
    {
        $validated = $request->validate([
            'engine' => ['nullable', 'string', Rule::in(EngineRegistry::keys())],
            // The sidebar failure badge links straight here with status=failed.
            'status' => ['nullable', 'string', Rule::in([
                EngineRun::STATUS_RUNNING,
                EngineRun::STATUS_SUCCEEDED,
                EngineRun::STATUS_FAILED,
                EngineRun::STATUS_SKIPPED,
            ])],
        ]);

        $engineKey = $validated['engine'] ?? null;
        $status = $validated['status'] ?? null;

        $runs = EngineRun::query()
            ->with('actor:id,full_name,email')
            ->when($engineKey, fn ($query) => $query->where('engine_key', $engineKey))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        // One aggregate query for the whole page: how many wallet entries each
        // run wrote and their net amount. A failed run's committed rows are then
        // a listed set rather than something to reconstruct by hand.
        $ledgerByRun = $runs->isEmpty()
            ? collect()
            : WalletLedgerEntry::query()
                ->whereIn('engine_run_id', $runs->pluck('id'))
                ->groupBy('engine_run_id')
                ->selectRaw('engine_run_id, COUNT(*) AS entries, SUM(amount_paise) AS net_paise')
                ->get()
                ->keyBy('engine_run_id');

        return view('admin.compensation.engine-runs.events', [
            'runs' => $runs,
            'ledgerByRun' => $ledgerByRun,
            // engine_key@period => the first succeeded run's start, so a row can
            // say "already processed" on the strength of an earlier run rather
            // than on an empty console capture. An idempotent re-run writes
            // nothing and prints nothing, which is indistinguishable from a run
            // that did not work — QA's F83.
            'firstSucceededAt' => $this->firstSucceededRunPerPeriod($runs->items()),
            'engineKey' => $engineKey,
            'statusFilter' => $status,
            // Full map so historical rows of a now-disabled engine keep their
            // label; the filter dropdown only offers currently visible engines.
            'definitions' => EngineRegistry::all(),
            'filterOptions' => array_filter(
                EngineRegistry::all(),
                static fn (EngineDefinition $definition): bool => $definition->featureFlagClass === null
                    || Feature::for(null)->active($definition->featureFlagClass),
            ),
        ]);
    }

    /**
     * The earliest succeeded run for each engine+period on the page.
     *
     * @param  array<int, EngineRun>  $runs
     * @return array<string, Carbon>
     */
    private function firstSucceededRunPerPeriod(array $runs): array
    {
        if ($runs === []) {
            return [];
        }

        $earliest = [];
        $periods = array_map(
            static fn (EngineRun $run): string => $run->period_start->toDateString(),
            $runs,
        );

        EngineRun::query()
            ->select('engine_key', 'period_start')
            ->selectRaw('MIN(started_at) AS first_started_at')
            ->where('status', EngineRun::STATUS_SUCCEEDED)
            ->whereIn('engine_key', array_unique(array_map(
                static fn (EngineRun $run): string => $run->engine_key,
                $runs,
            )))
            // whereDate over a range, not an IN list of dates: the column holds
            // a midnight timestamp, so a plain equality against 'YYYY-MM-DD'
            // matches nothing at all.
            ->whereDate('period_start', '>=', min($periods))
            ->whereDate('period_start', '<=', max($periods))
            ->groupBy('engine_key', 'period_start')
            ->get()
            ->each(function (EngineRun $row) use (&$earliest): void {
                $key = $row->engine_key.'@'.$row->period_start->toDateString();
                $earliest[$key] = Carbon::parse((string) $row->getAttribute('first_started_at'));
            });

        return $earliest;
    }

    public function trigger(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'engine' => ['required', 'string', Rule::in(EngineRegistry::keys())],
            'period' => ['required', 'string', 'max:10'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $engine = EngineRegistry::get($validated['engine']);

        // On a test environment the recompute owns the calendar. Firing one
        // engine by hand here runs it at the wrong instant — the period it
        // judges is still open, and the rows it writes are dated inside that
        // period — which is how a manual cut-off froze a day's pool at zero
        // (24 Aug 2026) and how Rank Bonus's repurchase deductions blocked
        // Growth Booster and Fortune for the month they belonged to
        // (14 Sep 2026). Run a recompute instead; it fires every engine at the
        // instant the scheduler would have.
        if ($this->recomputeGuard->isPermitted()) {
            throw ValidationException::withMessages([
                'engine' => 'Engines are not triggered individually on this environment. Use the recompute at the '
                    .'top of this page — it replays every engine at the instant the scheduler would have run it, '
                    .'which is what makes the figures match production.',
            ]);
        }

        // Maker-checker: the payout-batch engines are created by the scheduler
        // and approved by finance — the same permission this route carries, so
        // a manual trigger would let one admin both create and approve a batch.
        if (! $engine->manuallyTriggerable) {
            throw ValidationException::withMessages([
                'engine' => "{$engine->label} is scheduler-only and cannot be triggered manually.",
            ]);
        }

        // A flag-off engine no-ops; queueing it would only record a skipped run.
        if ($engine->featureFlagClass !== null && ! Feature::for(null)->active($engine->featureFlagClass)) {
            throw ValidationException::withMessages([
                'engine' => "{$engine->label} cannot run while its feature flag is off.",
            ]);
        }

        $period = $this->parsePeriodOrFail($engine, $validated['period']);

        // Resolved once here so the audit row records what the admin was told
        // would run; the job resolves again at execution time.
        $plan = $this->resolver->resolve($engine->key, $period);
        $chainId = (string) Str::uuid();

        $actorId = $request->user()?->id;

        AuditLog::create([
            'actor_id' => $actorId,
            'action' => 'compensation.engine.manual_run',
            'subject_type' => 'engine',
            'subject_id' => null,
            // A trigger changes nothing itself; the after digest pins the
            // chain that was authorised, warnings included.
            'before_hash' => null,
            'after_hash' => AuditDigests::of([
                'engine' => $engine->key,
                'period' => $engine->formatPeriod($period),
                'chain_id' => $chainId,
                'planned_chain' => $plan->toAuditPreview(),
                'warnings' => $plan->warnings,
            ]),
            'details' => [
                'engine' => $engine->key,
                'period' => $engine->formatPeriod($period),
                'reason' => $validated['reason'],
                'chain_id' => $chainId,
                'planned_chain' => $plan->toAuditPreview(),
                'warnings' => $plan->warnings,
            ],
            'ip' => $request->ip(),
        ]);

        RunEngineChainJob::dispatch($engine->key, $engine->formatPeriod($period), $actorId, $chainId);

        Log::info('engine.chain.queued', [
            'engine_key' => $engine->key,
            'period' => $engine->formatPeriod($period),
            'chain_id' => $chainId,
            'planned_steps' => count($plan->steps),
        ]);

        $message = sprintf(
            'Queued %s for %s%s. Refresh this page to follow progress.',
            $engine->label,
            $engine->displayPeriod($period),
            $plan->dependencyCount() > 0
                ? sprintf(' — %d prerequisite step(s) will run first', $plan->dependencyCount())
                : '',
        );

        foreach ($plan->warnings as $warning) {
            $message .= ' Note: '.$warning;
        }

        return redirect()->route('admin.compensation.engine-runs.index')->with('status', $message);
    }

    /**
     * The value pre-filled into the <input type=date|month>: the command's own
     * default period, clamped to the latest period a manual run may target —
     * the cut-off's CLI default is "today", which a manual run must never use
     * (it would freeze a day still in flight), so its form pre-fills yesterday.
     */
    private function periodInputValue(EngineDefinition $definition): string
    {
        $default = $definition->defaultPeriodDate()->min($definition->latestManualPeriod());

        return $definition->periodType === EnginePeriodType::Month
            ? $default->format('Y-m')
            : $default->format('Y-m-d');
    }

    /** The `<input max>` attribute — the browser-side twin of parsePeriodOrFail()'s limit. */
    private function periodInputMax(EngineDefinition $definition): string
    {
        $limit = $definition->latestManualPeriod();

        return $definition->periodType === EnginePeriodType::Month
            ? $limit->format('Y-m')
            : $limit->format('Y-m-d');
    }

    private function parsePeriodOrFail(EngineDefinition $engine, string $input): Carbon
    {
        try {
            $period = $engine->parsePeriod($input);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'period' => $engine->periodType === EnginePeriodType::Month
                    ? 'Enter the period as YYYY-MM.'
                    : 'Enter the period as YYYY-MM-DD.',
            ]);
        }

        // A future period has no sales data — and an economics-freezing engine
        // may not run for a period still in flight at all: on 24 Aug 2026 a
        // manual cut-off at 23:27 froze that day's pool at ₹0 before the
        // evening's BV had landed, and the scheduled 00:10 run then priced the
        // day's real achievers against the empty snapshot. The latest allowed
        // period comes from the definition, so the rule lives in one place.
        $limit = $engine->latestManualPeriod();

        if ($engine->periodStart($period)->gt($limit)) {
            if (! $engine->requiresClosedPeriod) {
                throw ValidationException::withMessages([
                    'period' => 'The period cannot be in the future.',
                ]);
            }

            throw ValidationException::withMessages([
                'period' => sprintf(
                    '%s freezes the %s\'s pool economics permanently, so it can only run once the %s has ended — the scheduled run will process it. The latest period you can run it for is %s.',
                    $engine->label,
                    $engine->periodType === EnginePeriodType::Month ? 'month' : 'day',
                    $engine->periodType === EnginePeriodType::Month ? 'month' : 'day',
                    $engine->displayPeriod($limit),
                ),
            ]);
        }

        return $period;
    }
}
