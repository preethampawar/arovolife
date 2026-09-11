<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\RepurchaseCycleService;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

/**
 * Daily evaluation of every active distributor's repurchase cycle: opens/rolls
 * cycles, refreshes completion from self-purchase BV, and moves each cycle
 * between active, suspended and completed (emitting the domain events). The GSB
 * cut-off reads the resulting status; this command keeps it current.
 *
 * Every run writes its counts to `engine_runs.summary`. `fulfilled` and
 * `forfeited` are the verdicts this run took — windows that closed while it
 * ran; `withheld` is the standing count of distributors whose current cycle is
 * suspended, which is the number that answers "who is not earning tonight".
 */
final class RepurchaseEvaluateCommand extends Command
{
    /** Every distributor the run could evaluate. */
    public const OUTCOME_COMPLETED = 'completed';

    /**
     * The run finished, but at least one distributor threw and still carries
     * the previous run's verdict. Recorded — and exited — as a failure: the
     * cut-off must not price a day against a verdict nobody refreshed.
     */
    public const OUTCOME_FAILED_PARTIAL = 'failed_partial';

    /** Cap on the ADNs named in the summary; the log has them all. */
    private const MAX_REPORTED_FAILURES = 50;

    protected $signature = 'repurchase:evaluate
                            {--date= : Override the as-of date (YYYY-MM-DD, default: today)}
                            {--distributor= : Evaluate a single distributor ID only}';

    protected $description = 'Evaluate each distributor\'s 30-day repurchase cycle and update income eligibility';

    public function __construct(
        private readonly RepurchaseCycleService $cycles,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Feature::for(null)->active(RepurchaseEngineFeature::class)) {
            $this->info('Repurchase engine is disabled (feature flag off) — nothing to run.');

            return self::SUCCESS;
        }

        if ($this->option('date') !== null) {
            $rawDate = (string) $this->option('date');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
                $this->error("--date must be in YYYY-MM-DD format, got: {$rawDate}");

                return self::FAILURE;
            }
            $asOf = Carbon::createFromFormat('Y-m-d', $rawDate)->startOfDay();
        } else {
            $asOf = Carbon::today();
        }

        $query = Distributor::query()
            ->whereNotNull('adn')
            ->where('status', 'active');

        if ($this->option('distributor')) {
            $query->where('id', (int) $this->option('distributor'));
        }

        $this->info("Repurchase evaluation — as of {$asOf->toDateString()}");

        $distributors = $this->withPossibleCycle($query->pluck('id'));
        $startedAt = Carbon::now();
        $evaluated = 0;
        $withheld = 0;
        $noCycle = 0;

        /** @var list<int> $failedIds */
        $failedIds = [];
        /** @var array<string, true> $failureClasses */
        $failureClasses = [];

        foreach ($distributors as $distributorId) {
            // One distributor's data problem must not cost the other N their
            // evaluation: the cut-off reads the verdict this writes, and a run
            // abandoned half way would leave every distributor after the
            // throwing one judged on yesterday's state. Collect and carry on;
            // the failure count is what the run's verdict rests on.
            try {
                $cycle = $this->cycles->evaluate((int) $distributorId, $asOf);
                $evaluated++;

                if ($cycle === null) {
                    $noCycle++;
                } elseif ($cycle->status === RepurchaseCycle::STATUS_SUSPENDED) {
                    $withheld++;
                }
            } catch (\Throwable $e) {
                $failedIds[] = (int) $distributorId;
                $failureClasses[$e::class] = true;

                Log::error('repurchase.evaluate.exception', [
                    'distributor_id' => $distributorId,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        }

        $failed = count($failedIds);
        $verdicts = $this->verdictsTakenSince($startedAt);
        $fulfilled = $verdicts[RepurchaseCycle::STATUS_COMPLETED];
        $forfeited = $verdicts[RepurchaseCycle::STATUS_SUSPENDED];

        $this->recordRunSummary([
            'outcome' => $failed > 0 ? self::OUTCOME_FAILED_PARTIAL : self::OUTCOME_COMPLETED,
            'as_of' => $asOf->toDateString(),
            'evaluated' => $evaluated,
            'fulfilled' => $fulfilled,
            'forfeited' => $forfeited,
            'withheld' => $withheld,
            'no_cycle' => $noCycle,
            'failed' => $failed,
            'failed_adns' => $this->adnsFor($failedIds),
            'failure_classes' => array_keys($failureClasses),
            'reason' => $failed > 0
                ? sprintf(
                    'Evaluated %d distributor(s) as of %s; %d could not be evaluated and still carry the '
                        .'previous run\'s verdict. The GSB cut-off refuses until every one of them is fixed '
                        .'and the evaluation re-run.',
                    $evaluated,
                    $asOf->toDateString(),
                    $failed,
                )
                : sprintf(
                    'Evaluated %d distributor(s) as of %s with no failures.',
                    $evaluated,
                    $asOf->toDateString(),
                ),
        ]);

        $this->info(
            "Done — evaluated: {$evaluated}, fulfilled: {$fulfilled}, forfeited: {$forfeited}, "
            ."withheld: {$withheld}, failed: {$failed}"
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The verdicts this run actually took, by outcome.
     *
     * A verdict is taken exactly once per window, the moment
     * `RepurchaseCycleService::resolveAtWindowEnd()` stamps `resolved_at` — so
     * "resolved since this run started" is the run's own work and nobody
     * else's. The returned cycle cannot answer this: a fulfilled window rolls
     * into the next one within the same call, so the cycle handed back is
     * almost always the fresh, active one.
     *
     * @return array{completed: int, suspended: int}
     */
    private function verdictsTakenSince(Carbon $startedAt): array
    {
        $query = DB::table('repurchase_cycles')
            ->where('resolved_at', '>=', $startedAt->toDateTimeString());

        if ($this->option('distributor')) {
            $query->where('distributor_id', (int) $this->option('distributor'));
        }

        $byStatus = $query->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            RepurchaseCycle::STATUS_COMPLETED => (int) ($byStatus[RepurchaseCycle::STATUS_COMPLETED] ?? 0),
            RepurchaseCycle::STATUS_SUSPENDED => (int) ($byStatus[RepurchaseCycle::STATUS_SUSPENDED] ?? 0),
        ];
    }

    /**
     * The ADNs behind the failed distributor ids, capped so one systemic
     * failure cannot write an unbounded blob into `engine_runs.summary`.
     *
     * ADNs, never names or contact details: the summary is read by the Engine
     * Runs page and the health digest, and an ADN is the identifier every
     * admin surface already uses to look a distributor up.
     *
     * @param  list<int>  $failedIds
     * @return list<string>
     */
    private function adnsFor(array $failedIds): array
    {
        if ($failedIds === []) {
            return [];
        }

        return array_values(
            Distributor::query()
                ->whereIn('id', array_slice($failedIds, 0, self::MAX_REPORTED_FAILURES))
                ->orderBy('id')
                ->pluck('adn')
                ->map(fn ($adn): string => (string) $adn)
                ->all()
        );
    }

    /**
     * Attach this run's counts to its own `engine_runs` row.
     *
     * RecordEngineRun opens and closes the row from the console events but has
     * nothing to say about what the engine actually did; on the plain
     * success/failure path it leaves `summary` alone, so the counts written
     * here survive it. Bookkeeping never breaks the engine: a missing row or a
     * locked table is logged and swallowed.
     *
     * @param  array<string, mixed>  $summary
     */
    private function recordRunSummary(array $summary): void
    {
        $runId = app(EngineRunContext::class)->activeRunId();

        // No row: a `--distributor` run (deliberately unlogged) or a run that
        // started before the listener could record it.
        if ($runId === null) {
            return;
        }

        try {
            EngineRun::where('id', $runId)->update([
                'summary' => json_encode($summary),
                'updated_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('repurchase.evaluate.summary_write_failed', [
                'engine_run_id' => $runId,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Narrow the candidates to distributors an evaluation can change.
     *
     * evaluate() opens a cycle only once the distributor has crossed the
     * repurchase BV anchor, which it establishes by scanning
     * bv_ledger_entries — an expensive per-distributor scan that can only ever
     * return null for someone with no BV rows at all. Everyone who already has
     * a cycle is kept regardless (their cycle still has to roll and expire).
     *
     * On the reference dataset this drops 124 of 288 candidates from every
     * daily run, which a full replay makes 46 times over.
     *
     * @param  Collection<int, int>  $distributorIds
     * @return Collection<int, int>
     */
    private function withPossibleCycle(Collection $distributorIds): Collection
    {
        if ($distributorIds->isEmpty()) {
            return $distributorIds;
        }

        $ids = $distributorIds->all();

        $withBv = DB::table('bv_ledger_entries')
            ->whereIn('distributor_id', $ids)
            ->distinct()
            ->pluck('distributor_id');

        $withCycle = DB::table('repurchase_cycles')
            ->whereIn('distributor_id', $ids)
            ->distinct()
            ->pluck('distributor_id');

        $keep = $withBv->merge($withCycle)->map(fn ($id): int => (int) $id)->flip();

        return $distributorIds->filter(fn ($id): bool => $keep->has((int) $id))->values();
    }
}
