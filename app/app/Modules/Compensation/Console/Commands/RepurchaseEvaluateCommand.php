<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\RepurchaseCycleService;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compensation\Support\RunPrerequisites;
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
 *
 * A distributor whose evaluation throws is skipped, not fatal (client decision
 * 2026-09-27): they keep the previous run's verdict, the run carries on and
 * succeeds, and tonight's GSB cut-off reserves their share and defers their
 * day (GsbCutoffDeferral). Above the cap ({@see effectiveSkipCap()}), or when
 * ten or more failures share one exception class, the run is a fault in
 * itself — it then fails closed, as every partial run did before.
 */
final class RepurchaseEvaluateCommand extends Command
{
    /** Every distributor the run could evaluate. */
    public const OUTCOME_COMPLETED = 'completed';

    /**
     * The run finished; up to the cap of distributors threw and keep the
     * previous run's verdict. Exited 0 — the cut-off runs for everyone else and
     * defers their day (client decision 2026-09-27).
     */
    public const OUTCOME_COMPLETED_WITH_SKIPS = 'completed_with_skips';

    /**
     * More than the cap threw: a fault in the run, not in the data. Recorded —
     * and exited — as a failure, as before.
     */
    public const OUTCOME_FAILED_PARTIAL = 'failed_partial';

    /**
     * Failures sharing one exception class at or above this count fail the run
     * closed whatever the cap: that shape is a fault in the run.
     */
    private const SINGLE_CLASS_FLOOR = 10;

    /** The skip cap never drops below this, however small the roster. */
    private const SKIP_CAP_FLOOR = 10;

    /** Cap on the ADNs named in the summary; the log has them all. */
    private const MAX_REPORTED_FAILURES = 50;

    /**
     * Ids per `whereIn`. A placeholder each, and MySQL refuses a prepared
     * statement past 65,535 of them; 500 is what GsbIdleCutoffBatch has used
     * for the same shape since it was written.
     */
    private const ID_CHUNK = 500;

    /**
     * Distributors loaded at a time. Larger than {@see ID_CHUNK} because this
     * one bounds memory rather than SQL placeholders: the chunk is narrowed to
     * the distributors who could have a cycle before any of them is evaluated.
     */
    private const ROSTER_CHUNK = 2000;

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

        // One evaluation at a time (E3). A manual trigger from the Engine Runs
        // page runs this same command on the queue worker while the scheduled
        // night may be running it here, and both would roll the same cycles.
        // A `--distributor` run has no row of its own and still refuses while a
        // full run is in flight.
        $concurrent = app(RunPrerequisites::class)->inFlightRefusal(
            ['repurchase.evaluate'],
            app(EngineRunContext::class)->activeRunId(),
            'repurchase.evaluate',
        );

        if ($concurrent !== null) {
            $this->error($concurrent);
            app(EngineRunContext::class)->noteSkipped($concurrent);

            return self::FAILURE;
        }

        $query = Distributor::query()
            ->whereNotNull('adn')
            ->where('status', 'active');

        if ($this->option('distributor')) {
            $query->where('id', (int) $this->option('distributor'));
        }

        $this->info("Repurchase evaluation — as of {$asOf->toDateString()}");

        $startedAt = Carbon::now();
        $evaluated = 0;
        $withheld = 0;
        $noCycle = 0;

        /** @var list<int> $failedIds */
        $failedIds = [];
        /** @var array<string, true> $failureClasses */
        $failureClasses = [];

        // CHUNKED, not one `pluck('id')` over the whole roster: at ten lakh that
        // is a million-element collection held for the length of the run before
        // a single cycle is evaluated (R-90). Each chunk narrows to the
        // distributors who could have a cycle and evaluates those.
        $query->chunkById(self::ROSTER_CHUNK, function (Collection $chunk) use (
            $asOf,
            &$evaluated,
            &$withheld,
            &$noCycle,
            &$failedIds,
            &$failureClasses,
        ): void {
            $distributors = $this->withPossibleCycle($chunk->pluck('id'));

            // Warm the chunk, evaluate it, forget it. Without this each
            // distributor costs six small reads of their own — the shape that
            // made this command 90% of the nightly chain and 6,004,508 queries
            // at ten lakh (R-90). The caches are accelerators only: every one
            // falls through to the query it replaced, so a distributor the warm
            // missed is still evaluated, just at the old price.
            try {
                // Inside the try, so a throw in the warm itself still reaches
                // the `finally` and cannot hand the next chunk this one's rows.
                $this->cycles->warmBatch(
                    array_values(array_map(intval(...), $distributors->all())),
                    $asOf,
                );

                foreach ($distributors as $distributorId) {
                    // One distributor's data problem must not cost the other N their
                    // evaluation: the cut-off reads the verdict this writes, and a run
                    // abandoned half way would leave every distributor after the
                    // throwing one judged on yesterday's state. Collect and carry on:
                    // up to the skip cap they are skipped and the run succeeds
                    // (client decision 2026-09-27); above it the run fails closed.
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
            } finally {
                // In a `finally` so a chunk that throws its way out cannot leave
                // the next chunk reading this one's warmed rows.
                $this->cycles->forgetBatch();
            }
        }, 'id');

        $failed = count($failedIds);
        $cap = self::effectiveSkipCap($evaluated + $failed);

        // Ten or more failures that all share one exception class are one
        // fault in the run (a dropped connection, a bad deploy), not ten
        // distributors' bad data — however far under the cap they are.
        $singleClass = $failed >= self::SINGLE_CLASS_FLOOR && count($failureClasses) === 1;
        $withinCap = $failed <= $cap && ! $singleClass;
        $verdicts = $this->verdictsTakenSince($startedAt);
        $fulfilled = $verdicts[RepurchaseCycle::STATUS_COMPLETED];
        $forfeited = $verdicts[RepurchaseCycle::STATUS_SUSPENDED];

        $this->recordRunSummary([
            'outcome' => $failed === 0
                ? self::OUTCOME_COMPLETED
                : ($withinCap ? self::OUTCOME_COMPLETED_WITH_SKIPS : self::OUTCOME_FAILED_PARTIAL),
            'as_of' => $asOf->toDateString(),
            'evaluated' => $evaluated,
            'fulfilled' => $fulfilled,
            'forfeited' => $forfeited,
            'withheld' => $withheld,
            'no_cycle' => $noCycle,
            'failed' => $failed,
            'failed_adns' => $this->adnsFor($failedIds),
            'failed_distributor_ids' => array_slice($failedIds, 0, $cap),
            'skip_cap' => $cap,
            'failure_classes' => array_keys($failureClasses),
            'reason' => match (true) {
                $failed === 0 => sprintf(
                    'Evaluated %d distributor(s) as of %s with no failures.',
                    $evaluated,
                    $asOf->toDateString(),
                ),
                $withinCap => sprintf(
                    'Evaluated %d distributor(s) as of %s; %d could not be evaluated and were skipped — they keep the '
                        ."previous run's verdict, and tonight's GSB cut-off reserves their share and defers their day. "
                        .'The first full cut-off after they evaluate cleanly backfills it.',
                    $evaluated,
                    $asOf->toDateString(),
                    $failed,
                ),
                $cap === 0 => sprintf(
                    'Evaluated %d distributor(s) as of %s; %d could not be evaluated. Skip-and-continue is off (the '
                        .'skip cap is 0), so any failure fails the run. The GSB cut-off refuses until it is fixed and '
                        .'the evaluation re-run.',
                    $evaluated,
                    $asOf->toDateString(),
                    $failed,
                ),
                $singleClass => sprintf(
                    'Evaluated %d distributor(s) as of %s; %d could not be evaluated and every failure is the same class '
                        .'(%s) — a fault in the run, not in the data. The GSB cut-off refuses until it is fixed and the '
                        .'evaluation re-run.',
                    $evaluated,
                    $asOf->toDateString(),
                    $failed,
                    (string) array_key_first($failureClasses),
                ),
                default => sprintf(
                    'Evaluated %d distributor(s) as of %s; %d could not be evaluated — more than the %d the run may skip, '
                        .'so this is a fault in the run, not in the data. The GSB cut-off refuses until it is fixed and the '
                        .'evaluation re-run.',
                    $evaluated,
                    $asOf->toDateString(),
                    $failed,
                    $cap,
                ),
            },
        ]);

        $this->info(
            "Done — evaluated: {$evaluated}, fulfilled: {$fulfilled}, forfeited: {$forfeited}, "
            ."withheld: {$withheld}, failed: {$failed}"
            .($failed > 0 && $withinCap ? ", skipped: {$failed}" : '')
        );

        return $withinCap ? self::SUCCESS : self::FAILURE;
    }

    /**
     * How many throwing distributors a run over $lookedAt may skip: the
     * smaller of the configured cap and 1% of the roster it looked at, never
     * below ten. A flat cap larger than the network would let a systemic fault
     * pass as a handful of bad rows.
     *
     * A configured 0 turns skip-and-continue off (L2): the cap is 0, so the
     * first failure fails the run closed, as before E5.
     */
    public static function effectiveSkipCap(int $lookedAt): int
    {
        $configured = max(0, (int) config('arovolife.compensation.evaluate_skip_cap', 500));

        if ($configured === 0) {
            return 0;
        }

        return max(self::SKIP_CAP_FLOOR, min($configured, intdiv(max(0, $lookedAt), 100)));
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

        // Chunked, because `whereIn` becomes a placeholder per id and MySQL
        // refuses a prepared statement past 65,535 of them: at ten lakh
        // distributors this threw "too many placeholders" and the whole nightly
        // evaluation died — measured, not predicted, on the scale harness
        // between 10k and 100k (R-90). The chunk size is GsbIdleCutoffBatch's,
        // which has carried the same shape since it was written.
        $keep = [];

        foreach (array_chunk($distributorIds->all(), self::ID_CHUNK) as $chunk) {
            foreach (['bv_ledger_entries', 'repurchase_cycles'] as $table) {
                foreach (DB::table($table)->whereIn('distributor_id', $chunk)->distinct()->pluck('distributor_id') as $id) {
                    $keep[(int) $id] = true;
                }
            }
        }

        return $distributorIds->filter(fn ($id): bool => isset($keep[(int) $id]))->values();
    }
}
