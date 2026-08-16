<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\FranchiseCommissionService;
use App\Modules\Shared\Features\FranchiseFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

/**
 * Monthly franchise commission run.
 *
 * Flag-gated. A flag-off run is reported as **skipped**, never as succeeded —
 * the engine-runs dependency resolver must not be able to treat a period with
 * no computation as computed (the 2026-08-13 compliance finding).
 */
final class FranchiseMonthlyRunCommand extends Command
{
    protected $signature = 'franchise:monthly-run
        {--month= : Month to process as YYYY-MM (default: last month)}';

    protected $description = 'Credit the monthly franchise fulfilment commission (3% of fulfilled product value)';

    public function handle(FranchiseCommissionService $commissions): int
    {
        if (! Feature::for(null)->active(FranchiseFeature::class)) {
            $this->warn('Franchise feature is off — skipped. Nothing computed and nothing credited.');

            return self::SUCCESS;
        }

        $month = $this->resolveMonth();

        if ($month === null) {
            $this->error('--month must be YYYY-MM.');

            return self::FAILURE;
        }

        $this->info('Franchise commission run for '.$month->format('F Y').'...');

        $summary = $commissions->runForMonth($month);

        $this->info(sprintf(
            '%d franchise(s) credited, %d with no fulfilled orders, %d with no operator. Total ₹%s.',
            $summary['credited'],
            $summary['skipped_no_orders'],
            $summary['skipped_no_operator'],
            number_format($summary['total_gross_paise'] / 100, 2)
        ));

        return self::SUCCESS;
    }

    private function resolveMonth(): ?Carbon
    {
        $raw = $this->option('month');

        if ($raw === null || $raw === '') {
            return Carbon::now('Asia/Kolkata')->subMonth()->startOfMonth();
        }

        if (preg_match('/^\d{4}-\d{2}$/', (string) $raw) !== 1) {
            return null;
        }

        $parsed = Carbon::createFromFormat('Y-m-d', $raw.'-01');

        return $parsed?->startOfMonth();
    }
}
