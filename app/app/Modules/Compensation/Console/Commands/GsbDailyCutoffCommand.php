<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\DTOs\GsbCutoffComputation;
use App\Modules\Compensation\Services\DTOs\MsbAccrual;
use App\Modules\Compensation\Services\GsbCutoffService;
use App\Modules\Compensation\Services\GsbDailyPoolService;
use App\Modules\Compensation\Services\MentorshipBonusService;
use App\Modules\Compensation\Services\MsbDailyPoolService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GsbDailyPoolPricingFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

final class GsbDailyCutoffCommand extends Command
{
    protected $signature = 'gsb:daily-cutoff
                            {--date= : Override the cut-off date (YYYY-MM-DD, default: today)}
                            {--distributor= : Run for a single distributor ID only (admin retry)}';

    protected $description = 'Run the 23:59 GSB cut-off for all active distributors';

    public function __construct(
        private readonly GsbCutoffService $cutoff,
        private readonly MentorshipBonusService $mentorship,
        private readonly GsbDailyPoolService $poolService,
        private readonly MsbDailyPoolService $msbPoolService,
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

        $singleId = $this->option('distributor')
            ? (int) $this->option('distributor')
            : null;

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

        $distributors = $query->get(['id', 'gsb_frozen_at']);
        $total = $distributors->count();
        $credited = 0;
        $failed = 0;
        $mbFailed = 0;

        // Batch-load per-distributor data before the loop: personal BV totals,
        // repurchase cycles, and frozen status — replaces ~3 N+1 queries with
        // 2–3 bulk queries regardless of distributor count.
        $this->cutoff->warmBatch($distributors);

        $poolPricingActive = Feature::for(null)->active(GsbDailyPoolPricingFeature::class);

        // Pass 1 — pure computation for every distributor (no writes). A
        // compute failure excludes that distributor from the day's pool
        // aggregates; their later retry prices against the frozen pool value
        // (same snapshot-not-recompute tolerance as the rest of the engine).
        /** @var array<int, GsbCutoffComputation> $computations */
        $computations = [];
        foreach ($distributors as $distributor) {
            $distributorId = (int) $distributor->id;

            try {
                $computations[$distributorId] = $this->cutoff->computeForDistributor($distributorId, $date);
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
        $this->info("Done — total: {$total}, credited: {$credited}, failed: {$failed}, mb-failed: {$mbFailed}, msb-points: {$msbTotalPoints}, msb-point-value: ₹{$msbValue}");

        return ($failed > 0 || $mbFailed > 0) ? self::FAILURE : self::SUCCESS;
    }
}
