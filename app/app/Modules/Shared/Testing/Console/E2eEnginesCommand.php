<?php

declare(strict_types=1);

namespace App\Modules\Shared\Testing\Console;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Services\Recompute\CompensationRecomputeRunner;
use App\Modules\Compensation\Services\Recompute\RecomputeHorizon;
use App\Modules\Shared\Testing\E2eMode;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Replay every compensation engine on the e2e database up to --as-of, exactly
 * as the scheduler would have fired them, then leave the frozen clock at that
 * instant so the web pages show the same "today" the engines ran to.
 *
 * In-process on purpose: the Playwright suite must never call
 * `compensation:recompute-all`, whose target is whatever database the calling
 * container is pointed at. This command refuses unless the live connection is
 * the e2e database ({@see E2eMode::assertSafe()}), and the recompute guard is
 * pinned to that database by the e2e provider.
 *
 * --settle-repurchase runs the two-pass flow: replay, spend each named cycle's
 * closing repurchase balance (YYYY-MM of the month the cycle closes in), replay
 * again so those cycles complete.
 *
 * --weekly-batch builds the weekly payout batch for each named Tuesday through
 * the real PayoutService, after the replay. --approve-as signs those batches
 * off as that staff e-mail (the checker; the console build has no maker), so
 * the batch page shows the NEFT download as finance would see it.
 *
 * --monthly-batch pays each named CREDITING month (YYYY-MM) through the same
 * compensation:monthly-payout-close the scheduler runs on the 8th, so its gates
 * (engine completion, open-month guard) apply. --as-of must be on or after the
 * 8th of the following month. --approve-as signs these batches off too.
 */
final class E2eEnginesCommand extends Command
{
    protected $signature = 'e2e:engines
                            {--as-of= : Replay through this Asia/Kolkata instant, e.g. "2026-07-01 06:00:00"}
                            {--settle-repurchase= : Second pass: settle the cycles closing in these months (YYYY-MM, comma-separated)}
                            {--weekly-batch= : Build the weekly payout batch for these Tuesdays (YYYY-MM-DD, comma-separated)}
                            {--monthly-batch= : Pay these crediting months through the payout close on the 8th (YYYY-MM, comma-separated)}
                            {--approve-as= : Approve the built weekly and monthly batches as this staff e-mail}';

    protected $description = 'Replay the compensation engines on the e2e database up to a fixed instant';

    public function handle(CompensationRecomputeRunner $runner, PayoutService $payouts): int
    {
        E2eMode::assertSafe($this->laravel);

        $asOf = $this->option('as-of');
        $asOf = is_string($asOf) ? $asOf : '';

        if ($asOf === '') {
            throw new RuntimeException('--as-of is required: the replay must stop at a fixed instant, never the real date.');
        }

        $instant = E2eMode::parseInstant($asOf);
        E2eMode::writeClock($instant);
        E2eMode::freeze($instant);

        $this->replay($runner);

        $settle = $this->option('settle-repurchase');
        $settle = is_string($settle) ? $settle : '';

        if ($settle !== '') {
            $code = Artisan::call('compensation:seed-paying-period', ['--settle-repurchase' => $settle, '--force' => true]);

            if ($code !== self::SUCCESS) {
                throw new RuntimeException('Settling repurchase failed: '.trim(Artisan::output()));
            }

            E2eMode::freeze($instant);
            $this->replay($runner);
        }

        $approverId = $this->approverId();
        $this->buildWeeklyBatches($payouts, $approverId);
        $this->buildMonthlyBatches($payouts, $approverId);

        $this->info('Engines replayed through '.Carbon::now()->format('Y-m-d H:i:s').'.');

        return self::SUCCESS;
    }

    private function approverId(): ?int
    {
        $approver = $this->option('approve-as');

        if (! is_string($approver) || $approver === '') {
            return null;
        }

        $id = DB::table('users')->where('email', $approver)->value('id')
            ?? throw new RuntimeException("--approve-as: no user '{$approver}'.");

        return (int) $id;
    }

    private function buildWeeklyBatches(PayoutService $payouts, ?int $approverId): void
    {
        $dates = $this->option('weekly-batch');
        $dates = is_string($dates) ? array_filter(array_map('trim', explode(',', $dates))) : [];

        foreach ($dates as $date) {
            $tuesday = Carbon::parse($date, 'Asia/Kolkata');

            if ($tuesday->isAfter(Carbon::now())) {
                throw new RuntimeException("--weekly-batch {$date} is after --as-of: a batch cannot be built before its day.");
            }

            $batch = $payouts->runWeeklyBatch($tuesday);

            if ($approverId !== null) {
                $batch = $payouts->approve($batch, $approverId);
            }

            if ($approverId !== null && $batch->status === PayoutBatch::STATUS_PENDING) {
                throw new RuntimeException("Weekly batch {$date} did not approve (still pending).");
            }

            $this->info(sprintf('Weekly batch %s: #%d, %s.', $date, $batch->id, $batch->status));
        }
    }

    private function buildMonthlyBatches(PayoutService $payouts, ?int $approverId): void
    {
        $months = $this->option('monthly-batch');
        $months = is_string($months) ? array_filter(array_map('trim', explode(',', $months))) : [];

        foreach ($months as $month) {
            if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
                throw new RuntimeException("--monthly-batch {$month}: expected YYYY-MM (the crediting month).");
            }

            $batchMonth = Carbon::createFromFormat('Y-m-d', $month.'-01', 'Asia/Kolkata')->addMonthNoOverflow()->startOfMonth();

            if ($batchMonth->copy()->day(8)->isAfter(Carbon::now())) {
                throw new RuntimeException("--monthly-batch {$month} pays on {$batchMonth->format('Y-m')}-08, which is after --as-of.");
            }

            $code = Artisan::call('compensation:monthly-payout-close', ['--month' => $month]);

            if ($code !== self::SUCCESS) {
                throw new RuntimeException("Monthly payout close for {$month} refused: ".trim(Artisan::output()));
            }

            $batch = PayoutBatch::whereDate('batch_date', $batchMonth->toDateString())
                ->where('batch_type', PayoutBatch::TYPE_MONTHLY)
                ->first()
                ?? throw new RuntimeException("Monthly payout close for {$month} built no batch.");

            if ($approverId !== null) {
                $batch = $payouts->approve($batch, $approverId);

                if ($batch->status === PayoutBatch::STATUS_PENDING) {
                    throw new RuntimeException("Monthly batch for {$month} did not approve (still pending).");
                }
            }

            $this->info(sprintf('Monthly batch for %s: #%d, %s.', $month, $batch->id, $batch->status));
        }
    }

    private function replay(CompensationRecomputeRunner $runner): void
    {
        $report = $runner->run(horizon: RecomputeHorizon::Now, progress: function (string $message): void {
            if ($this->output->isVerbose()) {
                $this->line('  '.$message);
            }
        });

        foreach ($report->warnings as $warning) {
            $this->warn($warning);
        }
    }
}
