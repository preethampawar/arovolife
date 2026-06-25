<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Services\PayoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

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
        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::today();

        $this->info("GSB weekly payout — {$date->toDateString()}");
        $batch = $this->payoutService->runBatch($date);
        $this->info("Batch #{$batch->id} {$batch->status} — {$batch->distributor_count} distributors, net ₹".number_format($batch->total_net_paise / 100, 2));

        return $batch->status === PayoutBatch::STATUS_COMPLETED ? self::SUCCESS : self::FAILURE;
    }
}
