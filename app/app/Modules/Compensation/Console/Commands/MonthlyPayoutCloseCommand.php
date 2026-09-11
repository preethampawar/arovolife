<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compensation\Support\MonthlyEngineCompletionGate;
use App\Modules\Compensation\Support\ResolvesMonthOption;
use App\Modules\Compensation\Support\WorkerFreshness;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The monthly PAYMENT close, a week after the crediting close.
 *
 * Crediting runs on the 1st; payment runs on the 8th. The week is not padding —
 * it is the only window in which a bad month can still be caught, because the
 * monthly payout batch is idempotent per month: once it has swept the wallet
 * and marked the entries paid out, a credit that lands afterwards has nowhere
 * to go. Previously the batch ran three hours after the engines on the same
 * night, so a month in which an engine crashed reached the bank before anyone
 * could look at it.
 *
 * 04:00 rather than 03:30: the weekly GSB batch runs Tuesdays at 03:00, and when
 * the 8th falls on a Tuesday both batches would consult the ₹50,00,000/month
 * income cap concurrently and race over the same month's headroom.
 *
 * The preflight is the entire point of the buffer — see
 * {@see MonthlyEngineCompletionGate}. Without it the buffer just delays the
 * same unchecked payment by seven days.
 *
 * `--month` is the CREDITING month (the month that closed), matching
 * `compensation:monthly-close`. The payout batch itself is dated the following
 * month — the month in which the money actually moves — which is exactly what
 * `payout:monthly-run` has always been given.
 */
final class MonthlyPayoutCloseCommand extends Command
{
    use ResolvesMonthOption;

    protected $signature = 'compensation:monthly-payout-close
                            {--month= : Crediting month to pay out (YYYY-MM, defaults to the previous month)}
                            {--force : Pay out even when a crediting engine has not succeeded for the month}
                            {--in-flight : Testing only — pay a month that has not ended}';

    protected $description = 'Run the monthly payout batch, but only once every crediting engine for the month has succeeded';

    public function handle(): int
    {
        $month = $this->resolveMonth();

        if ($month === null) {
            return self::FAILURE;
        }

        $batchMonth = $month->copy()->addMonthNoOverflow()->startOfMonth();

        $this->info("Monthly payout close — crediting month {$month->format('F Y')}, batch {$batchMonth->format('F Y')}");

        $stale = WorkerFreshness::staleReason();

        if ($stale !== null && ! $this->option('force')) {
            return $this->refuse($month, 'stale_worker', null, $stale);
        }

        $blocker = MonthlyEngineCompletionGate::blockingFailure($month);

        if ($blocker !== null) {
            if (! $this->option('force')) {
                return $this->refuse($month, $blocker['reason'], $blocker['engine_key'], $blocker['message']);
            }

            $this->warn("Crediting is incomplete but --force was passed:\n{$blocker['message']}");
        }

        try {
            $exitCode = Artisan::call('payout:monthly-run', ['--month' => $batchMonth->format('Y-m')]);
        } catch (Throwable $e) {
            Log::error('compensation.monthly_payout_close.crashed', [
                'month' => $month->format('Y-m'),
                'batch_month' => $batchMonth->format('Y-m'),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            $this->error(sprintf('Monthly payout batch threw %s: %s', $e::class, $e->getMessage()));

            app(EngineRunContext::class)->noteFailed(
                sprintf('Monthly payout batch threw %s: %s', $e::class, $e->getMessage()),
            );

            return self::FAILURE;
        }

        $output = trim(Artisan::output());

        if ($output !== '') {
            $this->line($output);
        }

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Refusing is a reported outcome, not a silent no-op: the whole point of
     * splitting the days is that somebody looks at the month before it is paid,
     * and nothing else in the platform reads EngineRun::STATUS_FAILED.
     */
    private function refuse(Carbon $month, string $reason, ?string $engineKey, string $message): int
    {
        $this->error($message);

        // Recorded as a refusal, not a failure. The crediting engine that is
        // actually at fault is already reported as a failure in its own right;
        // repeating it here as a second failed engine sends the reader chasing
        // the close instead of the step, and the close cannot be "fixed" until
        // that step is.
        app(EngineRunContext::class)->noteSkipped($message);

        Log::error('compensation.monthly_payout_close.refused', [
            'month' => $month->format('Y-m'),
            'reason' => $reason,
            'engine_key' => $engineKey,
            'detail' => $message,
        ]);

        try {
            AuditLog::create([
                'actor_id' => null,
                'action' => 'compensation.monthly_payout_close.refused',
                'subject_type' => 'platform',
                'subject_id' => 0,
                'details' => [
                    'month' => $month->format('Y-m'),
                    'reason' => $reason,
                    'engine_key' => $engineKey,
                    'detail' => $message,
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('compensation.monthly_payout_close.audit_failed', [
                'month' => $month->format('Y-m'),
                'error' => $e->getMessage(),
            ]);
        }

        return self::FAILURE;
    }
}
