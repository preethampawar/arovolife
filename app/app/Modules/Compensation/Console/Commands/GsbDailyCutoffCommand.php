<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Exceptions\CutoffReplayedOutOfOrder;
use App\Modules\Compensation\Models\GsbCutoffDeferral;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\DTOs\GsbCarryforwardSnapshot;
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
use App\Modules\Compensation\Support\RunPrerequisites;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GsbDailyPoolPricingFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        // One cut-off at a time (E3). A manual trigger from the Engine Runs
        // page runs this same command on the queue worker while the scheduled
        // night may be running it here; the unique index stops a double credit,
        // not a double advance of the carry-forward store. `--force` does not
        // lift this: a concurrent run is never right. A `--distributor` retry
        // has no row of its own and still refuses while a full run is in flight.
        $concurrent = app(RunPrerequisites::class)->inFlightRefusal(
            ['gsb.daily-cutoff'],
            app(EngineRunContext::class)->activeRunId(),
            'gsb.daily-cutoff',
        );

        if ($concurrent !== null) {
            $this->error($concurrent);
            app(EngineRunContext::class)->noteSkipped($concurrent);

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
            // and carries on. Up to the skip cap the run succeeds and this gate
            // opens — the distributors it could not judge are deferred below
            // (client decision 2026-09-27). Above the cap it exits non-zero with
            // a `failed_partial` summary and the run is `failed`, so this gate
            // stays shut platform-wide: that many throwing is a fault in the
            // run, not in the data, and the cut-off prices a day permanently.
            // The operator is told which ADNs to fix instead of being left to
            // read the log.
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

        // A by-name run must not overtake a deferred day. An open deferral on
        // an EARLIER date is refused outright: settling this date first would
        // advance the carry-forward store past the owed day and lose it (H1).
        // One on THIS date is the next full run's to backfill; `--force` after
        // a by-name re-evaluation settles it now and resolves it as manual.
        if ($singleId !== null) {
            $oldestOpen = GsbCutoffDeferral::open()
                ->where('distributor_id', $singleId)
                ->whereDate('cutoff_date', '<=', $date->toDateString())
                ->orderBy('cutoff_date')
                ->first();

            if ($oldestOpen !== null) {
                $adn = (string) Distributor::whereKey($singleId)->value('adn');
                $owedDate = $oldestOpen->cutoff_date->toDateString();

                if ($owedDate !== $date->toDateString()) {
                    // --force lifts the evaluate gate, so the owed day is only
                    // safe to settle by name once it is re-evaluated (M1).
                    $refusal = sprintf(
                        'ADN %s has a deferred cut-off for %s that is still open. Settling %s first would advance their '
                        .'carry-forward past it and lose the owed day. The next full run backfills it automatically; '
                        .'to settle it now, re-evaluate them by name first (php artisan repurchase:evaluate --date=%s '
                        .'--distributor=%d), then run that day (php artisan gsb:daily-cutoff --date=%s --distributor=%d --force).',
                        $adn,
                        $owedDate,
                        $date->toDateString(),
                        $owedDate,
                        $singleId,
                        $owedDate,
                        $singleId,
                    );

                    $this->error($refusal);
                    $this->recordByNameRefusal($singleId, $adn, $owedDate, $date, $refusal);

                    return self::FAILURE;
                }

                if (! $this->option('force')) {
                    $refusal = sprintf(
                        "ADN %s has a deferred cut-off for %s that tonight's full run will backfill automatically. "
                        .'To settle it now, re-evaluate them by name first (php artisan repurchase:evaluate --date=%s '
                        .'--distributor=%d) and pass --force.',
                        $adn,
                        $owedDate,
                        Carbon::today()->toDateString(),
                        $singleId,
                    );

                    $this->error($refusal);
                    $this->recordByNameRefusal($singleId, $adn, $owedDate, $date, $refusal);

                    return self::FAILURE;
                }
            }
        }

        $this->info("GSB daily cut-off — {$date->toDateString()}");

        $poolPricingActive = Feature::for(null)->active(GsbDailyPoolPricingFeature::class);

        // The Mentorship Bonus is computed alongside each GSB credit, so gate it
        // on its own flag — GSB can run without MB, but not the reverse.
        $mentorshipActive = Feature::for(null)->active(MentorshipBonusFeature::class);

        $query = Distributor::query()
            ->whereNotNull('adn')
            ->where('status', 'active');

        if ($singleId !== null) {
            $query->where('id', $singleId);
        }

        // A full run DEFERS the distributors the covering evaluation could not
        // judge (client decision 2026-09-27; E5 redesign). They stay in pass 1
        // — a pure computation on the stale verdict — so their matched share is
        // reserved in the day's frozen GSB pool and MSB denominator; only the
        // settle is skipped, and a GsbCutoffDeferral row records the owed day
        // until the next full run backfills it in date order. A --distributor
        // run never defers.
        $deferredByEvaluation = ['ids' => [], 'adns' => [], 'run_id' => null];

        /** @var array<int, string> $deferredIds distributor id => deferral cause */
        $deferredIds = [];

        /** @var array<int, list<Carbon>> $owedDays distributor id => earlier days still owed, oldest first */
        $owedDays = [];

        if ($singleId === null && $this->eligibility->engineActive()) {
            $deferredByEvaluation = $this->deferredByEvaluation($date);

            foreach ($deferredByEvaluation['ids'] as $id) {
                $deferredIds[$id] = GsbCutoffDeferral::CAUSE_EVALUATION_FAILED;
            }

            if ($deferredIds !== []) {
                $this->warn(sprintf(
                    '%d distributor(s) deferred: their repurchase evaluation failed and they keep the previous '
                    .'verdict%s. Their %s share is reserved in the day\'s pools and their cut-off is backfilled '
                    .'by the first full run after they evaluate cleanly.',
                    count($deferredIds),
                    $deferredByEvaluation['adns'] === [] ? '' : ' — ADN '.implode(', ', $deferredByEvaluation['adns']),
                    $date->toDateString(),
                ));
            }
        }

        // Owed days first, in date order, so each deferred distributor's
        // carry-forward store is advanced in sequence before tonight's day is
        // computed on top of it (H1). A distributor still owed an earlier day
        // after this — their backfill threw — is deferred again tonight rather
        // than settled past it.
        $backfillMbFailed = 0;

        if ($singleId === null) {
            $backfill = $this->backfillDeferrals($date, array_keys($deferredIds), $poolPricingActive, $mentorshipActive);
            $backfillMbFailed = $backfill['mb_failed'];

            if ($backfill['settled'] + $backfill['failed'] + $backfill['left_open'] > 0) {
                $this->line(sprintf(
                    '  Backfilled %d deferred cut-off(s) (%d failed, %d left open).',
                    $backfill['settled'],
                    $backfill['failed'],
                    $backfill['left_open'],
                ));
            }

            $stillOwed = GsbCutoffDeferral::open()
                ->whereDate('cutoff_date', '<', $date->toDateString())
                ->orderBy('cutoff_date')
                ->get(['distributor_id', 'cutoff_date']);

            foreach ($stillOwed as $owed) {
                $deferredIds[$owed->distributor_id] ??= GsbCutoffDeferral::CAUSE_EARLIER_DAY_OPEN;
                $owedDays[$owed->distributor_id][] = $owed->cutoff_date->copy()->startOfDay();
            }
        }

        $total = (clone $query)->count();
        $credited = 0;
        $failed = 0;
        // A backfilled day whose MB failed fails the run exactly as a nightly
        // MB failure does (M4) — the owed day itself is still resolved.
        $mbFailed = $backfillMbFailed;
        $skipped = 0;
        $outOfOrder = 0;

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
        /** @var array<int, true> $deferredSeen deferred ids the roster reached, minus out-of-order ones */
        $deferredSeen = [];

        $query->chunkById(self::ROSTER_CHUNK, function (Collection $chunk) use (
            $date,
            $singleId,
            $deferredIds,
            $owedDays,
            &$deferredSeen,
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
            //
            // A deferred distributor never takes it either: the batch WRITES
            // their row, and a deferred day must have none until it is
            // backfilled.
            if ($singleId === null) {
                $deferredInChunk = $chunk->filter(fn (Distributor $d): bool => isset($deferredIds[(int) $d->id]));
                $partition = $this->idleBatch->partition(
                    $chunk->reject(fn (Distributor $d): bool => isset($deferredIds[(int) $d->id])),
                    $date,
                );
                $skipped += $this->idleBatch->write($partition['below_min'], $partition['idle'], $date);
                $chunk = $partition['engine']->concat($deferredInChunk);
            }

            foreach ($chunk as $distributor) {
                $distributorId = (int) $distributor->id;

                if (isset($deferredIds[$distributorId])) {
                    $deferredSeen[$distributorId] = true;
                }

                try {
                    $computations[$distributorId] = isset($owedDays[$distributorId])
                        ? $this->computeOnOwedDays($distributorId, $date, $owedDays[$distributorId])
                        : $this->cutoff->computeForDistributor($distributorId, $date);
                } catch (CutoffReplayedOutOfOrder) {
                    // A later row already exists for a deferred distributor:
                    // there is no day left to owe them.
                    unset($deferredSeen[$distributorId]);

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

                if ($deferredSeen !== []) {
                    $this->line('  Reserved in the frozen pools for '.count($deferredSeen).' deferred distributor(s).');
                }
            } else {
                $pool = $this->poolService->poolForDate($date);
            }
        }

        // Every owed day is on record before anything settles (M5). Deferred
        // distributors are priced so the reservation records the gross the
        // day's pool set aside for them, never settled — a stale verdict pays
        // nothing — and their sponsor's points join the MSB denominator before
        // it freezes. One whose computation threw has nothing to reserve but
        // the day is still owed: a zero reservation. All in one transaction:
        // if any row cannot be written the night stops here, because the next
        // night would advance that distributor's store past a day nobody
        // recorded, and the day would be lost.
        $msbTotalPoints = 0;

        if ($deferredSeen !== []) {
            try {
                $msbTotalPoints = DB::transaction(function () use ($deferredSeen, $computations, $pool, $poolPricingActive, $mentorshipActive, $date, $deferredIds, $deferredByEvaluation): int {
                    $reservedTotal = 0;

                    foreach (array_keys($deferredSeen) as $distributorId) {
                        $computation = $computations[$distributorId] ?? null;
                        $reservedPoints = 0;

                        if ($computation !== null) {
                            $this->cutoff->price($computation, $pool, $poolPricingActive);
                            $reservedPoints = $mentorshipActive && $computation->isMatched() && $computation->slabIndex !== null
                                ? $this->mentorship->reservedPointsFor($distributorId, $computation->slabIndex)
                                : 0;
                        }

                        $this->writeDeferral($distributorId, $date, $deferredIds[$distributorId], $deferredByEvaluation['run_id'], $computation, $reservedPoints);
                        $reservedTotal += $reservedPoints;
                    }

                    return $reservedTotal;
                });
            } catch (\Throwable $e) {
                $adns = Distributor::whereIn('id', array_keys($deferredSeen))->orderBy('adn')->pluck('adn')->map(strval(...))->all();

                Log::error('gsb.cutoff.deferral_write_failed', [
                    'date' => $date->toDateString(),
                    'adns' => $adns,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);

                $message = sprintf(
                    'gsb.cutoff.deferral_write_failed: the %s GSB cut-off could not record the owed day for ADN %s (%s). '
                    .'Nothing was settled tonight. Re-run the night (php artisan gsb:daily-cutoff --date=%s) once the '
                    .'cause is fixed — before the next nightly run, or the next night advances these distributors past '
                    .'a day nobody recorded.',
                    $date->toDateString(),
                    implode(', ', $adns),
                    $e->getMessage(),
                    $date->toDateString(),
                );

                $this->error($message);

                // Resolved per call: EngineRunContext is container-scoped while a
                // console command is a process-lifetime singleton.
                app(EngineRunContext::class)->noteFailed($message);

                return self::FAILURE;
            }
        }

        // Pass 2 — price against the frozen pool, then settle (all writes).
        // MSB accruals are collected here and credited in pass 3 below.
        /** @var list<MsbAccrual> $accruals */
        $accruals = [];
        foreach ($computations as $distributorId => $computation) {
            // Deferred: recorded above, never settled.
            if (isset($deferredSeen[$distributorId])) {
                continue;
            }

            try {
                $this->cutoff->price($computation, $pool, $poolPricingActive);
                $result = $this->cutoff->settle($computation);

                // A --force by-name run past the refusal above settled a
                // deferred day: the row that now exists is its resolution.
                if ($singleId !== null && $result->status !== GsbCutoffResult::STATUS_FAILED) {
                    $this->resolveDeferral($distributorId, $date, $result, GsbCutoffDeferral::RESOLUTION_MANUAL);
                }

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

        $deferredCount = GsbCutoffDeferral::open()
            ->whereDate('cutoff_date', $date->toDateString())
            ->count();

        if ($singleId === null && $deferredCount > 0) {
            Log::warning('gsb.cutoff.deferred', [
                'date' => $date->toDateString(),
                'count' => $deferredCount,
                'adns' => $deferredByEvaluation['adns'],
            ]);
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
        $this->info("Done — total: {$total}, engine: ".count($computations).", bulk: {$skipped}, credited: {$credited}, failed: {$failed}, mb-failed: {$mbFailed}, msb-points: {$msbTotalPoints}, msb-point-value: ₹{$msbValue}, deferred: ".($singleId === null ? $deferredCount : 0));

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
     * A by-name run refused over an open deferral (M2): a log line and an
     * audit row in the shape of the admin Retry refusal, with no actor — the
     * CLI has none. Application logs rotate; the audit log is retained.
     */
    private function recordByNameRefusal(int $distributorId, string $adn, string $owedDate, Carbon $date, string $refusal): void
    {
        Log::warning('gsb.cutoff.refused_open_deferral', [
            'distributor_id' => $distributorId,
            'adn' => $adn,
            'owed_date' => $owedDate,
            'date' => $date->toDateString(),
            'forced' => (bool) $this->option('force'),
        ]);

        AuditLog::create([
            'actor_id' => null,
            'action' => 'compensation.cutoff.by_name_refused',
            'subject_type' => 'distributor',
            'subject_id' => $distributorId,
            'before_hash' => null,
            'after_hash' => null,
            'details' => [
                'adn' => $adn,
                'owed_date' => $owedDate,
                'date' => $date->toDateString(),
                'forced' => (bool) $this->option('force'),
                'refusal' => $refusal,
            ],
        ]);
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
        $cap = (int) ($summary['skip_cap'] ?? config('arovolife.compensation.evaluate_skip_cap', 500));

        $why = match (true) {
            $cap === 0 => 'Skip-and-continue is off (the skip cap is 0)',
            $failed > $cap => sprintf('That is more than the %d the run may skip', $cap),
            default => 'Every failure is the same exception class — a fault in the run, not in the data',
        };

        return [
            'failed' => $failed,
            'adns' => $adns,
            'message' => sprintf(
                "That run did complete, but %d distributor(s) threw and still carry the previous run's "
                    ."verdict%s%s.\n%s, so it was recorded as a failure: "
                    ."fix the cause, re-run the evaluation, then this cut-off.\n",
                $failed,
                $adns === [] ? '' : ' — ADN '.implode(', ', $adns),
                $classes === [] ? '' : ' ('.implode(', ', array_map(strval(...), $classes)).')',
                $why,
            ),
        ];
    }

    /**
     * The distributors the evaluation covering $date could not judge — deferred
     * by a full run (client decision 2026-09-27). Read from the latest evaluate
     * run after the day, whatever its status: over-deferring is conservative
     * (the day is reserved and backfilled), under-deferring prices a day on a
     * verdict nobody refreshed.
     *
     * @return array{ids: list<int>, adns: list<string>, run_id: int|null}
     */
    private function deferredByEvaluation(Carbon $date): array
    {
        $run = $this->engineStatus->latestRunAfterDay('repurchase.evaluate', $date);
        $summary = is_array($run?->summary) ? $run->summary : [];

        return [
            'ids' => array_values(array_map(intval(...), is_array($summary['failed_distributor_ids'] ?? null) ? $summary['failed_distributor_ids'] : [])),
            'adns' => array_values(array_map(strval(...), is_array($summary['failed_adns'] ?? null) ? $summary['failed_adns'] : [])),
            'run_id' => $run?->id === null ? null : (int) $run->id,
        ];
    }

    /**
     * Tonight's computation for a distributor still owed earlier days, on the
     * store those days WOULD leave (E5 review N1). Each owed day is computed
     * purely, oldest first, and folded into an in-memory snapshot; tonight is
     * then computed on it. Nothing is written — the reservation just prices the
     * same day the backfill will later settle, instead of one on a store that
     * never absorbed the owed days.
     *
     * @param  list<Carbon>  $owedDays
     */
    private function computeOnOwedDays(int $distributorId, Carbon $date, array $owedDays): GsbCutoffComputation
    {
        $snapshot = null;

        foreach ($owedDays as $day) {
            $snapshot = GsbCarryforwardSnapshot::after(
                $this->cutoff->computeForDistributor($distributorId, $day, $snapshot),
                $snapshot,
            );
        }

        Log::info('gsb.cutoff.reservation_chained', [
            'distributor_id' => $distributorId,
            'owed_days' => count($owedDays),
            'date' => $date->toDateString(),
        ]);

        return $this->cutoff->computeForDistributor($distributorId, $date, $snapshot);
    }

    /**
     * Settle every open deferral dated before $tonightDate, oldest first, one
     * distributor at a time through the single-distributor sequence: compute,
     * price against the day's FROZEN pool (never re-frozen), settle, then
     * accrue and credit the sponsor's MB at the day's frozen MSB point value —
     * the money the deferring night reserved.
     *
     * Skipped: distributors tonight's evaluation still could not judge, those
     * no longer active (never backfilled; the digest asks for a decision), and
     * any later day of a distributor whose earlier backfill failed tonight — it
     * would advance the store past the day still owed.
     *
     * @param  list<int>  $stillDeferredIds
     * @return array{settled: int, failed: int, left_open: int, mb_failed: int}
     */
    private function backfillDeferrals(Carbon $tonightDate, array $stillDeferredIds, bool $poolPricingActive, bool $mentorshipActive): array
    {
        $open = GsbCutoffDeferral::open()
            ->whereDate('cutoff_date', '<', $tonightDate->toDateString())
            ->orderBy('cutoff_date')
            ->orderBy('distributor_id')
            ->get();

        $settled = 0;
        $failed = 0;
        $leftOpen = 0;
        $mbFailed = 0;
        $still = array_fill_keys($stillDeferredIds, true);

        /** @var array<int, true> $blocked distributors whose earlier owed day failed tonight */
        $blocked = [];

        $active = Distributor::query()
            ->whereIn('id', $open->pluck('distributor_id')->unique()->all())
            ->whereNotNull('adn')
            ->where('status', 'active')
            ->pluck('id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();

        foreach ($open as $deferral) {
            $distributorId = $deferral->distributor_id;
            $day = $deferral->cutoff_date->copy()->startOfDay();

            if (isset($still[$distributorId]) || isset($blocked[$distributorId]) || ! isset($active[$distributorId])) {
                $leftOpen++;

                continue;
            }

            try {
                $computation = $this->cutoff->computeForDistributor($distributorId, $day);
                $this->cutoff->price(
                    $computation,
                    $poolPricingActive ? $this->poolService->poolForDate($day) : null,
                    $poolPricingActive,
                );
                $result = $this->cutoff->settle($computation);
            } catch (CutoffReplayedOutOfOrder) {
                // A later row already advanced the store — an operator ran them
                // by name, or an older fault. The row that exists is the truth;
                // looping on it every night would help nobody. Superseded, not
                // quietly manual (M3): the day closes with no result row of its
                // own, so it is audited and the digest shows it for a week.
                Log::warning('gsb.cutoff.deferral_already_passed', [
                    'distributor_id' => $distributorId,
                    'cutoff_date' => $day->toDateString(),
                ]);
                $deferral->update([
                    'resolved_at' => Carbon::now(),
                    'resolution' => GsbCutoffDeferral::RESOLUTION_SUPERSEDED,
                ]);
                $this->recordSuperseded($deferral);

                continue;
            } catch (\Throwable $e) {
                $failed++;
                $blocked[$distributorId] = true;
                Log::error('gsb.cutoff.deferral_failed', [
                    'distributor_id' => $distributorId,
                    'cutoff_date' => $day->toDateString(),
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);

                continue;
            }

            if ($result->status === GsbCutoffResult::STATUS_FAILED) {
                $failed++;
                $blocked[$distributorId] = true;
                Log::error('gsb.cutoff.deferral_failed', [
                    'distributor_id' => $distributorId,
                    'cutoff_date' => $day->toDateString(),
                    'reason' => $result->failure_reason,
                ]);

                continue;
            }

            $paidGross = $result->status === GsbCutoffResult::STATUS_CREDITED ? (int) $result->gross_gsb_paise : 0;
            $paidPoints = 0;

            // The GSB day is settled whatever MB does next; an MB failure is
            // logged as one, exactly as on the nightly path.
            if ($result->status === GsbCutoffResult::STATUS_CREDITED && $mentorshipActive) {
                try {
                    $accrual = $this->mentorship->accrueForSponsee($distributorId, $result);

                    if ($accrual !== null) {
                        $paidPoints = $accrual->points;
                        $this->mentorship->creditAccrual($accrual, $this->msbPoolService->poolForDate($day));
                    }
                } catch (\Throwable $e) {
                    $mbFailed++;
                    Log::error('mb.credit.exception', [
                        'sponsee_id' => $distributorId,
                        'cutoff_date' => $day->toDateString(),
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                    ]);
                }
            }

            $exceeded = $paidGross > $deferral->reserved_gsb_paise || $paidPoints > $deferral->reserved_msb_points;

            $deferral->update([
                'resolved_at' => Carbon::now(),
                'resolution' => GsbCutoffDeferral::RESOLUTION_BACKFILLED,
                'gsb_cutoff_result_id' => $result->id,
                'paid_gsb_paise' => $paidGross,
                'paid_msb_points' => $paidPoints,
                'exceeded_reservation_at' => $exceeded ? Carbon::now() : null,
            ]);

            if ($exceeded) {
                $this->recordBackfillExcess($deferral, $paidGross, $paidPoints);
            }

            $settled++;
        }

        return ['settled' => $settled, 'failed' => $failed, 'left_open' => $leftOpen, 'mb_failed' => $mbFailed];
    }

    /**
     * An owed day a later cut-off already passed, resolved as superseded: the
     * audit row names the later row the store now stands on, so someone can
     * check it paid what the owed day should have folded into it.
     */
    private function recordSuperseded(GsbCutoffDeferral $deferral): void
    {
        $laterRowDate = GsbCutoffResult::where('distributor_id', $deferral->distributor_id)
            ->whereDate('cutoff_date', '>', $deferral->cutoff_date->toDateString())
            ->orderBy('cutoff_date')
            ->first()
            ?->cutoff_date
            ->toDateString();

        AuditLog::create([
            'actor_id' => null,
            'action' => 'gsb.cutoff.deferral_superseded',
            'subject_type' => 'gsb_cutoff_deferral',
            'subject_id' => $deferral->id,
            'before_hash' => null,
            'after_hash' => null,
            'details' => [
                'distributor_id' => $deferral->distributor_id,
                'adn' => (string) Distributor::whereKey($deferral->distributor_id)->value('adn'),
                'cutoff_date' => $deferral->cutoff_date->toDateString(),
                'later_row_date' => $laterRowDate,
            ],
        ]);
    }

    /**
     * The backfill paid more than the deferring night reserved — a title
     * crossed, a sponsor crossing the MB minimum, a reversal, or a computation
     * that threw and reserved nothing (R-113). The excess is real money out of
     * the day's leftover, or on top of the priced pool: an audit row and a
     * warning, and the next digest lists it.
     */
    private function recordBackfillExcess(GsbCutoffDeferral $deferral, int $paidGross, int $paidPoints): void
    {
        $details = [
            'distributor_id' => $deferral->distributor_id,
            'adn' => (string) Distributor::whereKey($deferral->distributor_id)->value('adn'),
            'cutoff_date' => $deferral->cutoff_date->toDateString(),
            'reserved_gsb_paise' => $deferral->reserved_gsb_paise,
            'paid_gsb_paise' => $paidGross,
            'reserved_msb_points' => $deferral->reserved_msb_points,
            'paid_msb_points' => $paidPoints,
            'delta_gsb_paise' => $paidGross - $deferral->reserved_gsb_paise,
            'delta_msb_points' => $paidPoints - $deferral->reserved_msb_points,
        ];

        Log::warning('gsb.cutoff.backfill_exceeds_reservation', $details);

        AuditLog::create([
            'actor_id' => null,
            'action' => 'gsb.cutoff.backfill_exceeds_reservation',
            'subject_type' => 'gsb_cutoff_deferral',
            'subject_id' => $deferral->id,
            'before_hash' => null,
            'after_hash' => null,
            'details' => $details,
        ]);
    }

    /**
     * Close an open deferral for ($distributorId, $date) against the result
     * row a settle produced. A no-op when there is none.
     */
    private function resolveDeferral(int $distributorId, Carbon $date, GsbCutoffResult $result, string $resolution): void
    {
        GsbCutoffDeferral::open()
            ->where('distributor_id', $distributorId)
            ->whereDate('cutoff_date', $date->toDateString())
            ->update([
                'resolved_at' => Carbon::now(),
                'resolution' => $resolution,
                'gsb_cutoff_result_id' => $result->id,
            ]);
    }

    /**
     * Record (or refresh, on a re-run of the same night) the owed day and what
     * the full run reserved for it. Never touches a resolved row: a re-run
     * after the day was backfilled must not reopen it.
     */
    private function writeDeferral(
        int $distributorId,
        Carbon $date,
        string $cause,
        ?int $evaluateRunId,
        ?GsbCutoffComputation $computation,
        int $reservedMsbPoints,
    ): void {
        $existing = GsbCutoffDeferral::where('distributor_id', $distributorId)
            ->whereDate('cutoff_date', $date->toDateString())
            ->first();

        if ($existing?->resolved_at !== null) {
            return;
        }

        $matched = $computation !== null && $computation->isMatched();

        $attributes = [
            'cause' => $cause,
            'evaluate_run_id' => $evaluateRunId,
            'reserved_slab' => $matched ? $computation->slabIndex : null,
            'reserved_gsb_paise' => $matched ? $computation->grossPaise : 0,
            'reserved_msb_points' => $reservedMsbPoints,
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return;
        }

        GsbCutoffDeferral::create($attributes + [
            'distributor_id' => $distributorId,
            'cutoff_date' => $date->toDateString(),
        ]);
    }
}
