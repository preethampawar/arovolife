<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\GsbCutoffService;
use App\Modules\Compensation\Services\MentorshipBonusService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

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
        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::today();

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

        $distributors = $query->pluck('id');
        $total = $distributors->count();
        $credited = 0;
        $failed = 0;

        foreach ($distributors as $distributorId) {
            try {
                $result = $this->cutoff->runForDistributor((int) $distributorId, $date);

                if ($result->status === GsbCutoffResult::STATUS_CREDITED) {
                    $credited++;
                    $this->mentorship->processForSponsee((int) $distributorId, $result);
                } elseif ($result->status === GsbCutoffResult::STATUS_FAILED) {
                    $failed++;
                    Log::error('gsb.cutoff.failed', ['distributor_id' => $distributorId, 'reason' => $result->failure_reason]);
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error('gsb.cutoff.exception', ['distributor_id' => $distributorId, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Done — total: {$total}, credited: {$credited}, failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
