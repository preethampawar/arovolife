<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Jobs\RecomputeAllJob;
use App\Modules\Compensation\Jobs\RunEngineChainJob;
use App\Modules\Compensation\Models\EngineRun;
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
            ];
        }

        return view('admin.compensation.engine-runs.index', [
            'engines' => $engines,
            // TESTING-ONLY recompute. The view asks the guard rather than
            // re-deciding; when it refuses, the card is not rendered at all.
            'recomputeAllowed' => $this->recomputeGuard->isPermitted(),
            'recomputeTargetDatabase' => $this->recomputeGuard->targetDatabase(),
            'recomputeRowCounts' => $this->recomputeGuard->isPermitted() ? $this->wiper->preview() : [],
        ]);
    }

    /**
     * TESTING ONLY — queue a full wipe-and-replay of every BV-derived row.
     *
     * Dispatches rather than running inline: the replay takes minutes, and a
     * request timeout halfway through would leave the database wiped and only
     * partly rebuilt. Removed with the recompute scaffold at client sign-off.
     */
    public function recomputeAll(): RedirectResponse
    {
        abort_unless($this->recomputeGuard->isPermitted(), 404);

        if (Cache::lock(RecomputeAllJob::LOCK_KEY)->get() === false) {
            return redirect()->route('admin.compensation.engine-runs.index')
                ->with('error', 'A compensation recompute is already running. Wait for it to finish.');
        }

        // The probe above only tested availability; the job takes the real lock
        // for its own lifetime, so release this one immediately.
        Cache::lock(RecomputeAllJob::LOCK_KEY)->forceRelease();

        $actorId = auth()->id();
        RecomputeAllJob::dispatch(is_numeric($actorId) ? (int) $actorId : null);

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'compensation.recompute_all.queued',
            'subject_type' => 'platform',
            'subject_id' => 0,
            'details' => ['note' => 'Testing-only full compensation recompute queued from the admin console.'],
        ]);

        return redirect()->route('admin.compensation.engine-runs.index')
            ->with('status', 'Full recompute queued. Every BV-derived row is being wiped and rebuilt — '
                .'the runs below will repopulate as the replay progresses.');
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

        return view('admin.compensation.engine-runs.events', [
            'runs' => $runs,
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

    /** The value pre-filled into the <input type=date|month>, matching the command's own default period. */
    private function periodInputValue(EngineDefinition $definition): string
    {
        $default = $definition->defaultPeriodDate();

        return $definition->periodType === EnginePeriodType::Month
            ? $default->format('Y-m')
            : $default->format('Y-m-d');
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

        // A future period has no sales data; a cut-off for today would freeze a
        // partial day. Months are accepted for the current month because the
        // monthly engines legitimately run mid-month for the month so far.
        $limit = $engine->periodType === EnginePeriodType::Month
            ? Carbon::today()->startOfMonth()
            : Carbon::today();

        if ($engine->periodStart($period)->gt($limit)) {
            throw ValidationException::withMessages([
                'period' => 'The period cannot be in the future.',
            ]);
        }

        return $period;
    }
}
