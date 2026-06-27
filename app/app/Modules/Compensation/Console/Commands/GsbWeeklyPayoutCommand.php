<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class GsbWeeklyPayoutCommand extends Command
{
    protected $signature = 'gsb:weekly-payout
                            {--date= : Batch date override (YYYY-MM-DD, default: today)}';

    protected $description = 'Run the Tuesday weekly payout batch for all eligible wallets';

    public function __construct(private readonly PayoutService $payoutService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Feature::for(null)->active(GenosSalesBonusFeature::class)) {
            $this->info('Genos Sales Bonus is disabled (feature flag off) — no payout batch to run.');

            return self::SUCCESS;
        }

        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::today();

        $this->info("GSB weekly payout — {$date->toDateString()}");
        $batch = $this->payoutService->runBatch($date);
        $this->info("Batch #{$batch->id} {$batch->status} — {$batch->distributor_count} distributors, net ₹".number_format($batch->total_net_paise / 100, 2));

        // Batch moves to PENDING (awaiting admin approval) after a successful run —
        // it only reaches COMPLETED after the admin calls approve(). Treat PENDING
        // with a processed_at timestamp as a successful run to avoid false-positive
        // cron alerts.
        return $batch->status === PayoutBatch::STATUS_PENDING && $batch->processed_at !== null
            ? self::SUCCESS
            : self::FAILURE;
    }
}
