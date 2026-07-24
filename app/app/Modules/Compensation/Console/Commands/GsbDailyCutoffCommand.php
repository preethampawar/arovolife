<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\GsbCutoffService;
use App\Modules\Compensation\Services\MentorshipBonusService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
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

        foreach ($distributors as $distributor) {
            $distributorId = (int) $distributor->id;

            try {
                // The conditional personal-BV weaker-leg top-up is now applied
                // inside runForDistributor (only when a leg touches a slab).
                $result = $this->cutoff->runForDistributor($distributorId, $date);

                if ($result->status === GsbCutoffResult::STATUS_CREDITED) {
                    $credited++;
                    if ($mentorshipActive) {
                        // MB failures are logged and counted separately — the
                        // distributor's own GSB credit already succeeded, so an
                        // MB error must not be triaged as a GSB cut-off failure.
                        try {
                            $this->mentorship->processForSponsee($distributorId, $result);
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

        $this->info("Done — total: {$total}, credited: {$credited}, failed: {$failed}, mb-failed: {$mbFailed}");

        return ($failed > 0 || $mbFailed > 0) ? self::FAILURE : self::SUCCESS;
    }
}
