<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Console\Actions\PurchaseDataResetAction;
use App\Modules\Compensation\Jobs\RecomputeAllJob;
use App\Modules\Compensation\Jobs\RunEngineChainJob;
use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\EngineChainResolver;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Services\Recompute\CompensationStateWiper;
use App\Modules\Compensation\Services\Recompute\RecomputeGuard;
use App\Modules\Compensation\Services\Recompute\RecomputeProgress;
use App\Modules\Compensation\Support\EngineDefinition;
use App\Modules\Compensation\Support\EnginePeriodType;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compliance\Models\AuditLog;
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
    ) {}

    public function index(): View
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

        return view('admin.compensation.engine-runs.index', [
            'engines' => $engines,
            // TESTING-ONLY recompute. The view asks the guard rather than
            // re-deciding; when it refuses, the card is not rendered at all.
            'recomputeAllowed' => $this->recomputeGuard->isPermitted(),
            'recomputeTargetDatabase' => $this->recomputeGuard->targetDatabase(),
            'recomputeRowCounts' => $this->recomputeGuard->isPermitted() ? $this->wiper->preview() : [],
            // Engine checkboxes for a partial replay, and the purchase-reset
            // card's own preview — both only when the guard permits.
            'recomputeEngines' => $this->recomputeGuard->isPermitted() ? EngineRegistry::all() : [],
            'purchaseResetRowCounts' => $this->recomputeGuard->isPermitted()
                ? app(PurchaseDataResetAction::class)->preview()
                : [],
            'recomputePresets' => [
                'today' => Carbon::today()->toDateString(),
                'month_start' => Carbon::today()->startOfMonth()->toDateString(),
            ],
        ]);
    }

    /**
     * TESTING ONLY — queue a full wipe-and-replay of every BV-derived row.
     *
     * Dispatches rather than running inline: the replay takes minutes, and a
     * request timeout halfway through would leave the database wiped and only
     * partly rebuilt. Removed with the recompute scaffold at client sign-off.
     */
    public function recomputeAll(Request $request): RedirectResponse
    {
        abort_unless($this->recomputeGuard->isPermitted(), 404);

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'windowed' => ['nullable', 'boolean'],
            'engines' => ['nullable', 'array'],
            'engines.*' => ['string', Rule::in(array_keys(EngineRegistry::all()))],

            // A partial selection wipes every derived table for the window but
            // rebuilds only the ticked engines, so the rest are left MISSING
            // rather than stale. The run summary names them, but that arrives
            // after the delete — this is the acknowledgement that arrives before.
            'accept_missing_engines' => ['exclude_without:engines', 'accepted'],
        ], [
            'accept_missing_engines.accepted' => 'Replaying only some engines deletes the other engines\' '
                .'results for this window without rebuilding them. Tick the acknowledgement to proceed.',
        ]);

        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;
        /** @var list<string> $engines */
        $engines = array_values($validated['engines'] ?? []);

        // Keeping the earlier history is only meaningful with a start date;
        // without one there is nothing to keep.
        $windowed = (bool) ($validated['windowed'] ?? false) && $from !== null;

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
            from: $from,
            to: $to,
            onlyEngineKeys: $engines === [] ? null : $engines,
            windowed: $windowed,
        );

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'compensation.recompute_all.queued',
            'subject_type' => 'platform',
            'subject_id' => 0,
            'details' => [
                'note' => 'Testing-only compensation recompute queued from the admin console.',
                'from' => $from,
                'to' => $to,
                'mode' => $windowed ? 'windowed' : 'full',
                'engines' => $engines === [] ? 'all' : $engines,
            ],
        ]);

        return redirect()->route('admin.compensation.engine-runs.index')
            ->with('status', $windowed
                ? 'Windowed recompute queued from '.$from.'. Only the derived rows from that date '
                    .'onwards are being rebuilt — earlier history is left as it is.'
                : 'Full recompute queued. Every BV-derived row is being wiped and rebuilt — '
                    .'the runs below will repopulate as the replay progresses.');
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
        abort_unless($this->recomputeGuard->isPermitted(), 404);

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
        $actorId = auth()->id();
        $actorId = is_numeric($actorId) ? (int) $actorId : null;

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
    public function recomputeProgress(): JsonResponse
    {
        abort_unless($this->recomputeGuard->isPermitted(), 404);

        return response()->json($this->recomputeProgress->read() ?? ['state' => 'idle']);
    }

    public function events(Request $request): View
    {
        $validated = $request->validate([
            'engine' => ['nullable', 'string', Rule::in(EngineRegistry::keys())],
        ]);

        $engineKey = $validated['engine'] ?? null;

        $runs = EngineRun::query()
            ->with('actor:id,full_name,email')
            ->when($engineKey, fn ($query) => $query->where('engine_key', $engineKey))
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
            'engineKey' => $engineKey,
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

    public function trigger(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'engine' => ['required', 'string', Rule::in(EngineRegistry::keys())],
            'period' => ['required', 'string', 'max:10'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $engine = EngineRegistry::get($validated['engine']);

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

    /** The <input max> attribute — the browser-side twin of {@see parsePeriodOrFail()}'s limit. */
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
