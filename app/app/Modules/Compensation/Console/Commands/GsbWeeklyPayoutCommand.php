<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Support\IndianNumber as Number;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;
use Throwable;

final class GsbWeeklyPayoutCommand extends Command
{
    protected $signature = 'gsb:weekly-payout
                            {--date= : Batch date override (YYYY-MM-DD, default: today)}
                            {--force : Run for a batch date that is not a Tuesday}';

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

        // The earning week is Wednesday→Tuesday and the batch dated Tuesday T
        // pays it. A batch dated any other day derives a window that splits a
        // week: the entries earned on the leftover days would then wait for
        // — and be swept by — the next real Tuesday batch, a week late.
        if (! $date->isTuesday() && ! $this->option('force')) {
            $refusal = sprintf(
                'A weekly payout batch is dated a Tuesday; %s is a %s. Pass --force only to run a deliberately off-cycle batch.',
                $date->toDateString(),
                $date->format('l'),
            );

            $this->error($refusal);

            // A refusal, not a failure: the date can never become a Tuesday, so
            // recorded as failed it would be reported as an unresolved failure
            // for thirty days with nothing anyone could do about it.
            app(EngineRunContext::class)->noteSkipped($refusal);

            return self::FAILURE;
        }
        // Name the week being paid, not just the batch date: the batch dated T
        // pays the Wednesday–Tuesday week that closed on T−7, and an operator
        // reading only the batch date has no way to tell which earnings moved.
        $window = PayoutBatch::weeklyEarningWindow($date);

        $this->info(sprintf(
            'Weekly payout (Group A) — batch %s pays earnings %s → %s',
            $date->toDateString(),
            $window['start']->toDateString(),
            $window['end']->toDateString(),
        ));

        try {
            $batch = $this->payoutService->runWeeklyBatch($date);
        } catch (Throwable $e) {
            // An exception that escapes the runner leaves the batch stuck in
            // `processing`, which the idempotency guard then skips forever.
            // Mark it failed so the next scheduled run can start clean.
            Log::critical('Weekly payout batch aborted by an unhandled exception', [
                'batch_type' => PayoutBatch::TYPE_WEEKLY,
                'batch_date' => $date->toDateString(),
                'exception' => $e,
            ]);

            PayoutBatch::whereDate('batch_date', $date->toDateString())
                ->where('batch_type', PayoutBatch::TYPE_WEEKLY)
                ->where('status', PayoutBatch::STATUS_PROCESSING)
                ->update(['status' => PayoutBatch::STATUS_FAILED]);

            $this->error("Weekly payout aborted: {$e->getMessage()}");

            app(EngineRunContext::class)->noteFailed("Weekly payout aborted: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Batch #{$batch->id} {$batch->status} — {$batch->distributor_count} distributors, net ₹".Number::format($batch->total_net_paise / 100, 2));

        // Batch moves to PENDING (awaiting admin approval) after a successful run —
        // it only reaches COMPLETED after the admin calls approve(). Treat PENDING
        // with a processed_at timestamp as a successful run to avoid false-positive
        // cron alerts.
        return $batch->status === PayoutBatch::STATUS_PENDING && $batch->processed_at !== null
            ? self::SUCCESS
            : self::FAILURE;
    }
}
