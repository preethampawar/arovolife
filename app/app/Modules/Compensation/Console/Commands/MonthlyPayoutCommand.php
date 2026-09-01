<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Support\IndianNumber as Number;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;
use Throwable;

final class MonthlyPayoutCommand extends Command
{
    protected $signature = 'payout:monthly-run
                            {--month= : Month to pay (YYYY-MM, defaults to current month)}';

    protected $description = 'Run the monthly payout batch (GBB, Rank, Fortune, Awards, ADC — Groups B/C/D)';

    public function __construct(private readonly PayoutService $payoutService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Feature::for(null)->active(GenosSalesBonusFeature::class)) {
            $this->info('Compensation feature flag is OFF — no monthly payout batch to run.');

            return self::SUCCESS;
        }

        $month = $this->option('month')
            ? Carbon::parse((string) $this->option('month').'-01')
            : Carbon::today()->startOfMonth();

        $this->info("Monthly payout (Groups B/C/D) — {$month->format('F Y')}");

        try {
            $batch = $this->payoutService->runMonthlyBatch($month);
        } catch (Throwable $e) {
            // See GsbWeeklyPayoutCommand: un-stick the batch so the next
            // scheduled run is not silently skipped by the processing guard.
            Log::critical('Monthly payout batch aborted by an unhandled exception', [
                'batch_type' => PayoutBatch::TYPE_MONTHLY,
                'batch_date' => $month->copy()->startOfMonth()->toDateString(),
                'exception' => $e,
            ]);

            PayoutBatch::whereDate('batch_date', $month->copy()->startOfMonth()->toDateString())
                ->where('batch_type', PayoutBatch::TYPE_MONTHLY)
                ->where('status', PayoutBatch::STATUS_PROCESSING)
                ->update(['status' => PayoutBatch::STATUS_FAILED]);

            $this->error("Monthly payout aborted: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Batch #{$batch->id} {$batch->status} — {$batch->distributor_count} distributors, net ₹".Number::format($batch->total_net_paise / 100, 2));

        return $batch->status === PayoutBatch::STATUS_PENDING && $batch->processed_at !== null
            ? self::SUCCESS
            : self::FAILURE;
    }
}
