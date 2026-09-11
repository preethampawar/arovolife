<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compensation\Support\MonthlyEngineCompletionGate;
use App\Modules\Compensation\Support\ResolvesMonthOption;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Support\IndianNumber as Number;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * The monthly payout BATCH. `--month` is the month the money moves — the batch's
 * date and its idempotency key — so the credits it sweeps are the ones the
 * crediting close wrote on the 1st, for the month BEFORE it.
 *
 * It is gated, and the gates are the point. The batch is idempotent per month:
 * once it has swept the wallet and marked the entries paid out, a credit that
 * lands afterwards has nowhere to go. `compensation:monthly-payout-close` exists
 * so that never happens before somebody has checked the month — but it is the
 * less obvious of the two commands, and an operator debugging a missing payout
 * reaches for this one. Typed bare it used to default to the month in flight and
 * sweep every unswept Group B/C/D credit with no check at all, which is the
 * whole 1st→8th buffer defeated by one line of shell.
 *
 * So: the same `MonthlyEngineCompletionGate` the close applies, on the month
 * whose credits are being paid; the shared {@see OpenMonthGuard} refusal for a
 * batch month still in flight, which the close lifts deliberately (the batch
 * month always IS in flight when the batch runs — that is what `--in-flight`
 * means here, and passing it by hand is stating that you mean to pay outside the
 * sanctioned path); and a default of the month that has just ended rather than
 * the live one.
 */
final class MonthlyPayoutCommand extends Command
{
    use ResolvesMonthOption;

    protected $signature = 'payout:monthly-run
                            {--month= : Batch month (YYYY-MM) — the month the money moves, defaults to the month that has just ended}
                            {--force : Build the batch even when the month it pays has incomplete crediting}
                            {--in-flight : Build a batch dated a month that has not ended — what compensation:monthly-payout-close passes on the 8th}';

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

        $month = $this->resolveMonth();

        if ($month === null) {
            return self::FAILURE;
        }

        // The credits this batch sweeps were written by the close on the 1st of
        // the batch month, for the month that ended the day before.
        $creditingMonth = $month->copy()->subMonthNoOverflow()->startOfMonth();

        $this->info("Monthly payout (Groups B/C/D) — {$month->format('F Y')}, paying {$creditingMonth->format('F Y')} credits");

        $blocker = MonthlyEngineCompletionGate::blockingFailure($creditingMonth);

        if ($blocker !== null) {
            if (! $this->option('force')) {
                $this->error($blocker['message']);

                Log::error('payout.monthly.refused_incomplete_crediting', [
                    'batch_month' => $month->format('Y-m'),
                    'crediting_month' => $creditingMonth->format('Y-m'),
                    'reason' => $blocker['reason'],
                    'engine_key' => $blocker['engine_key'],
                ]);

                app(EngineRunContext::class)->noteSkipped($blocker['message']);

                return self::FAILURE;
            }

            $this->warn("Crediting is incomplete but --force was passed:\n{$blocker['message']}");
        }

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
