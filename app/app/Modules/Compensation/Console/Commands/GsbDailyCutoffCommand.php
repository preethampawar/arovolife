<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Exceptions\CutoffReplayedOutOfOrder;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\DTOs\GsbCutoffComputation;
use App\Modules\Compensation\Services\DTOs\MsbAccrual;
use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Services\GsbCutoffService;
use App\Modules\Compensation\Services\GsbDailyPoolService;
use App\Modules\Compensation\Services\GsbIdleCutoffBatch;
use App\Modules\Compensation\Services\IncomeEligibilityService;
use App\Modules\Compensation\Services\MentorshipBonusService;
use App\Modules\Compensation\Services\MsbDailyPoolService;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compensation\Support\OpenMonthGuard;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GsbDailyPoolPricingFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

final class GsbDailyCutoffCommand extends Command
{
    /**
     * Distributors loaded, warmed and computed at a time.
     *
     * Small enough that the chunk's warmed caches are a few megabytes, large
     * enough that the bulk warm queries still amortise across it. 2,000 keeps
     * both true at ten lakh.
     */
    private const ROSTER_CHUNK = 2000;

    protected $signature = 'gsb:daily-cutoff
                            {--date= : Override the cut-off date (YYYY-MM-DD, default: today)}
                            {--distributor= : Run for a single distributor ID only (admin retry)}
                            {--force : Run even though repurchase:evaluate has not run for the date}
                            {--in-flight : Testing only — cut off a day that has not ended; the day\'s pool is provisional}';

    protected $description = 'Run the 23:59 GSB cut-off for all active distributors';

    public function __construct(
        private readonly GsbCutoffService $cutoff,
        private readonly MentorshipBonusService $mentorship,
        private readonly GsbDailyPoolService $poolService,
        private readonly MsbDailyPoolService $msbPoolService,
        private readonly GsbIdleCutoffBatch $idleBatch,
        private readonly IncomeEligibilityService $eligibility,
        private readonly EngineStatusService $engineStatus,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Feature::for(null)->active(GenosSalesBonusFeature::class)) {
            $this->info('Genos Sales Bonus is disabled (feature flag off) — nothing to run.');

            return self::SUCCESS;
        }

        if ($this->option('date') !== null) {
            $rawDate = (string) $this->option('date');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
                $this->error("--date must be in YYYY-MM-DD format, got: {$rawDate}");

                return self::FAILURE;
            }
            $date = Carbon::createFromFormat('Y-m-d', $rawDate)->startOfDay();
        } else {
            $date = Carbon::today();
        }

        // A day still in flight has no final BV. The cut-off freezes the day's
        // GSB and MSB pools the first time it runs and prices every match
        // against that snapshot; run at noon it prices the day on half its BV,
        // and the scheduled run after midnight then KEEPS that pricing, because
        // freezePoolForDate() returns the existing row unchanged (the 24 Aug
        // 2026 staging incident). Until now the only thing standing in the way
        // was the repurchase guard below — which `--force` lifts, and which is
        // not there at all while the repurchase engine is off.
        //
        // `--force` deliberately does NOT lift this one: it answers a different
        // question (has the day been judged) and an operator forcing past a
        // missing evaluation is not thereby asking to freeze a partial day.
        if (! $this->option(OpenMonthGuard::OPTION) && $date->gte(Carbon::today())) {
            $this->error(sprintf(
                'Refusing to run the %s GSB cut-off: that day has not ended (today is %s). The cut-off '
                ."freezes the day's GSB and MSB pools on the BV that exists at this instant, and the "
                .'scheduled run after midnight keeps that pricing rather than repricing the full day, so '
                .'every distributor who earns later today is priced out of it permanently.
'
                .'Run it for a day that has closed — php artisan gsb:daily-cutoff --date=%s — or pass '
                .'--in-flight for a deliberate, provisional test cut-off.',
                $date->toDateString(),
                Carbon::today()->toDateString(),
                Carbon::yesterday()->toDateString(),
            ));

            Log::warning('gsb.cutoff.refused_open_day', [
                'date' => $date->toDateString(),
                'today' => Carbon::today()->toDateString(),
            ]);

            // Resolved per call: EngineRunContext is container-scoped while a
            // console command is a process-lifetime singleton.
            app(EngineRunContext::class)->noteSkipped(sprintf(
                'The %s GSB cut-off was refused: the day has not ended.',
                $date->toDateString(),
            ));

            return self::FAILURE;
        }

        $singleId = $this->option('distributor')
            ? (int) $this->option('distributor')
            : null;

        // The repurchase verdict this cut-off reads is written by exactly one
        // process, `repurchase:evaluate`. Run before it and every day inside a
        // failed cycle still reads as eligible, so the cut-off credits income
        // the client's rules forfeit — and the forfeit is permanent, so there is
        // no later correction. Refuse instead. Proof has to be a run that could
        // SEE the whole day: a run for a later date, or a run dated D that
        // started after D ended. The scheduled 00:05 run ON D is not proof — a
        // purchase made later that day fulfils the cycle it judged as failed.
        if ($this->eligibility->engineActive()
            && ! $this->option('force')
            && ! $this->engineStatus->hasSucceededRunAfterDay('repurchase.evaluate', $date)) {
            // Why the gate is shut, when the evaluation ran and named its
            // casualties. `repurchase:evaluate` isolates a throwing distributor
            // and carries on, then exits non-zero with a `failed_partial`
            // summary; the run is `failed`, so the gate above is unmoved — a
            // non-zero failure count means somebody's verdict is stale, and the
            // cut-off prices a day permanently. Fail-closed platform-wide is
            // deliberate: refusing only the named distributors would still
            // freeze the day's pools without their BV. What changes is that the
            // operator is told which ADNs to fix instead of being left to read
            // the log.
            $partial = $this->repurchaseFailureNote($date);

            Log::critical('gsb.cutoff.refused_missing_evaluate', [
                'date' => $date->toDateString(),
                'distributor_id' => $singleId,
                'evaluate_failures' => $partial['failed'],
                'evaluate_failed_adns' => $partial['adns'],
            ]);

            $nextDay = $date->copy()->addDay()->toDateString();

            $this->error(
                "Refusing to run the {$date->toDateString()} GSB cut-off: the repurchase engine is on but "
                ."`repurchase:evaluate` has no succeeded run that has seen the whole of {$date->toDateString()} "
                .'— it needs a run for a later date, or a run for that date that started after the day ended. '
                ."Otherwise a cycle fulfilled later that day would still read as failed, and be forfeited.\n"
                .$partial['message']
                ."Run `php artisan repurchase:evaluate --date={$nextDay}` first, then re-run this "
                .'command (or pass --force to override).'
            );

            return self::FAILURE;
        }

        $this->info("GSB daily cut-off — {$date->toDateString()}");

        $query = Distributor::query()
            ->whereNotNull('adn')
            ->where('status', 'active');

        if ($singleId !== null) {
            $query->where('id', $singleId);
        }

        // The Mentorship Bonus is computed alongside each GSB credit, so gate it
        // on its own flag — GSB can run without MB, but not the reverse.
        $mentorshipActive = Feature::for(null)->active(MentorshipBonusFeature::class);

        $total = (clone $query)->count();
        $credited = 0;
        $failed = 0;
        $mbFailed = 0;
        $skipped = 0;
        $outOfOrder = 0;

        $poolPricingActive = Feature::for(null)->active(GsbDailyPoolPricingFeature::class);

        // Pass 1 — pure computation for every distributor (no writes). A
        // compute failure excludes that distributor from the day's pool
        // aggregates; their later retry prices against the frozen pool value
        // (same snapshot-not-recompute tolerance as the rest of the engine).
        /** @var array<int, GsbCutoffComputation> $computations */
        $computations = [];

        // CHUNKED, not `->get()`. The roster used to be materialised whole:
        // every active distributor as a model, with warmBatch() then holding
        // every one of their personal-BV totals and repurchase cycles for the
        // length of the run. The scale harness measured 485 MB at a hundred
        // thousand distributors, growing linearly — roughly 4.8 GB at ten lakh,
        // which is the chain running out of memory rather than out of time
        // (R-90). Warming, computing and forgetting one chunk at a time bounds
        // that by the chunk instead of by the population.
        //
        // What does NOT move into the chunk is the pool: it is still frozen
        // once, below, from the aggregate of EVERY computation, because the
        // day's economics are the day's — a pool frozen per chunk would price
        // each chunk against its own denominator.
        $query->chunkById(self::ROSTER_CHUNK, function (Collection $chunk) use (
            $date,
            $singleId,
            &$computations,
            &$failed,
            &$skipped,
            &$outOfOrder,
        ): void {
            // Batch-load per-distributor data before the loop: personal BV
            // totals, repurchase cycles, and frozen status — replaces ~3 N+1
            // queries with 2–3 bulk queries per chunk.
            $this->cutoff->warmBatch($chunk);

            // Distributors who cannot match today (below the personal-BV
            // minimum, or eligible but with no group BV, no carry-forward and
            // no row for the date) get their rows written in one batched INSERT
            // instead of a compute+settle cycle each. On the reference dataset
            // that is ~98% of the day's rows. Single-distributor retries never
            // take the shortcut.
            if ($singleId === null) {
                $partition = $this->idleBatch->partition($chunk, $date);
                $skipped += $this->idleBatch->write($partition['below_min'], $partition['idle'], $date);
                $chunk = $partition['engine'];
            }

            foreach ($chunk as $distributor) {
                $distributorId = (int) $distributor->id;

                try {
                    $computations[$distributorId] = $this->cutoff->computeForDistributor($distributorId, $date);
                } catch (CutoffReplayedOutOfOrder) {
                    // Not a failure — an ordering decision, caught by its own
                    // type so it cannot be filed as one. The day was cut off
                    // correctly and a later day has since advanced the rolling
                    // carry-forward store, so this date has nowhere correct to
                    // start; nothing is written and the earlier cut-off stands.
                    // Counted apart from $failed because the latest finished
                    // attempt decides whether a period is computed (D13): a
                    // `failed` row here would un-prove a day that IS proven and
                    // leave the month unclosable on a refusal nobody can clear.
                    $outOfOrder++;
                    Log::warning('gsb.cutoff.refused_out_of_order', [
                        'date' => $date->toDateString(),
                        'distributor_id' => $distributorId,
                    ]);
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error('gsb.cutoff.exception', [
                        'distributor_id' => $distributorId,
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                }
            }

            // The chunk is computed; nothing downstream reads these caches.
            $this->cutoff->forgetBatch();
        }, 'id');

        if ($skipped > 0) {
            $this->line("  {$skipped} distributor(s) with no possible match — rows written in bulk.");
        }

        // Freeze the day's pool economics BEFORE any credit, so a crash
        // mid-settle re-runs against identical numbers. Fixed payout counts
        // every matched slab 1–2 computation (frozen/held rows record — and may
        // later release — the same gross, so they must be funded). Only the
        // full nightly run freezes; single-distributor retries reuse the
        // existing snapshot (or fall back to legacy pricing when none exists).
        $pool = null;
        if ($poolPricingActive) {
            if ($singleId === null) {
                $fixedPayoutPaise = 0;
                $variableTotalScore = 0;
                foreach ($computations as $computation) {
                    if (! $computation->isMatched() || $computation->slabIndex === null) {
                        continue;
                    }
                    if (GsbDailyPoolService::isVariableSlab($computation->slabIndex)) {
                        $variableTotalScore += $computation->slabScore ?? 0;
                    } else {
                        $fixedPayoutPaise += $computation->fixedSlabGrossPaise();
                    }
                }

                $pool = $this->poolService->freezePoolForDate($date, $fixedPayoutPaise, $variableTotalScore);
            } else {
                $pool = $this->poolService->poolForDate($date);
            }
        }

        // Pass 2 — price against the frozen pool, then settle (all writes).
        // MSB accruals are collected here and credited in pass 3 below.
        /** @var list<MsbAccrual> $accruals */
        $accruals = [];
        $msbTotalPoints = 0;
        foreach ($computations as $distributorId => $computation) {
            try {
                $this->cutoff->price($computation, $pool, $poolPricingActive);
                $result = $this->cutoff->settle($computation);

                if ($result->status === GsbCutoffResult::STATUS_CREDITED) {
                    $credited++;
                    if ($mentorshipActive) {
                        // MB failures are logged and counted separately — the
                        // distributor's own GSB credit already succeeded, so an
                        // MB error must not be triaged as a GSB cut-off failure.
                        // Only ACCRUE here: the MSB point value is the day's
                        // pool ÷ the day's total points, so nothing can be
                        // priced until every distributor has settled.
                        try {
                            $accrual = $this->mentorship->accrueForSponsee($distributorId, $result);
                            if ($accrual !== null) {
                                $accruals[] = $accrual;
                                $msbTotalPoints += $accrual->points;
                            }
                        } catch (\Throwable $e) {
                            $mbFailed++;
                            Log::error('mb.credit.exception', [
                                'sponsee_id' => $distributorId,
                                'cutoff_date' => $date->toDateString(),
                                'error' => $e->getMessage(),
                                'exception' => get_class($e),
                            ]);
                        }
                    }
                } elseif ($result->status === GsbCutoffResult::STATUS_FAILED) {
                    $failed++;
                    Log::error('gsb.cutoff.failed', ['distributor_id' => $distributorId, 'reason' => $result->failure_reason]);
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error('gsb.cutoff.exception', [
                    'distributor_id' => $distributorId,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        }

        // Pass 3 — the day's MSB denominator is only known now, so freeze the
        // pool and credit every accrual at the one point value it yields.
        // Single-distributor retries never freeze: one sponsor's points are not
        // the day's denominator, so they price against the existing snapshot.
        $msbPointValuePaise = 0;
        if ($mentorshipActive) {
            $msbPool = $singleId === null
                ? $this->msbPoolService->freezePoolForDate($date, $msbTotalPoints)
                : $this->msbPoolService->poolForDate($date);
            $msbPointValuePaise = (int) ($msbPool->point_value_paise ?? 0);

            foreach ($accruals as $accrual) {
                try {
                    $this->mentorship->creditAccrual($accrual, $msbPool);
                } catch (\Throwable $e) {
                    $mbFailed++;
                    Log::error('mb.credit.exception', [
                        'sponsee_id' => $accrual->sponseeId,
                        'cutoff_date' => $date->toDateString(),
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                    ]);
                }
            }
        }

        $msbValue = number_format($msbPointValuePaise / 100, 2);
        $this->info("Done — total: {$total}, engine: ".count($computations).", bulk: {$skipped}, credited: {$credited}, failed: {$failed}, mb-failed: {$mbFailed}, msb-points: {$msbTotalPoints}, msb-point-value: ₹{$msbValue}");

        // Nothing computed, nothing broken: every distributor the engine could
        // not compute was refused for the same reason, and the day's earlier
        // cut-off is still the day's cut-off. Recorded as `skipped` so
        // completedCutoffDatesBetween() keeps listing the day (D13), and still
        // exited non-zero so the caller — an operator, or an orchestrator that
        // asked for this day — sees that the work it asked for did not happen.
        // A real failure alongside it wins: that run IS a failed attempt.
        if ($outOfOrder > 0 && $failed === 0 && $mbFailed === 0) {
            $refusal = sprintf(
                'The %s GSB cut-off was refused for %d distributor(s): the day has already been passed by a '
                .'later cut-off, and the rolling carry-forward store cannot be rewound one day at a time. A day '
                .'can be rebuilt only while it is the newest one, so nothing was written and the cut-off that '
                ."already stands for %s is unchanged.\n"
                .'Replaying history from %s forward is a windowed recompute, which runs on dev and staging only '
                .'(`php artisan compensation:recompute-all --horizon=now`); in production escalate to the '
                .'platform team (R-91).',
                $date->toDateString(),
                $outOfOrder,
                $date->toDateString(),
                $date->toDateString(),
            );

            $this->error($refusal);

            // Resolved per call: EngineRunContext is container-scoped while a
            // console command is a process-lifetime singleton.
            app(EngineRunContext::class)->noteSkipped($refusal);

            return self::FAILURE;
        }

        return ($failed > 0 || $mbFailed > 0) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * What the last `repurchase:evaluate` run that covers $date reported.
     *
     * Returns the failure count, the ADNs it named and a line to append to the
     * refusal. Empty when there is no such run (the evaluation simply never
     * ran) or when it recorded no summary — an older run, or one whose
     * bookkeeping failed.
     *
     * @return array{failed: int, adns: list<string>, message: string}
     */
    private function repurchaseFailureNote(Carbon $date): array
    {
        $run = $this->engineStatus->latestRunAfterDay('repurchase.evaluate', $date);
        $summary = is_array($run?->summary) ? $run->summary : [];

        $failed = (int) ($summary['failed'] ?? 0);

        if ($failed < 1) {
            return ['failed' => 0, 'adns' => [], 'message' => ''];
        }

        $adns = array_values(array_map(
            fn ($adn): string => (string) $adn,
            is_array($summary['failed_adns'] ?? null) ? $summary['failed_adns'] : [],
        ));

        $classes = is_array($summary['failure_classes'] ?? null) ? $summary['failure_classes'] : [];

        return [
            'failed' => $failed,
            'adns' => $adns,
            'message' => sprintf(
                "That run did complete, but %d distributor(s) threw and still carry the previous run's "
                    ."verdict%s%s.\nFix them first — the cut-off stays shut while any failure stands, because "
                    ."the day's pools are frozen once and never repriced.\n",
                $failed,
                $adns === [] ? '' : ' — ADN '.implode(', ', $adns),
                $classes === [] ? '' : ' ('.implode(', ', array_map(strval(...), $classes)).')',
            ),
        ];
    }
}
