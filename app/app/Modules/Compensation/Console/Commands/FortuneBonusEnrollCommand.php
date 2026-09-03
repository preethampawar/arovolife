<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\FortuneBonusService;
use App\Modules\Shared\Features\FortuneBonusFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class FortuneBonusEnrollCommand extends Command
{
    protected $signature = 'fortune:enroll-eligible
                            {--month= : Month to enroll for (YYYY-MM, defaults to previous month)}';

    protected $description = 'Enroll eligible distributors into the Fortune Bonus matrix (FCFS)';

    public function __construct(private readonly FortuneBonusService $fortuneBonus)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Feature::for(null)->active(FortuneBonusFeature::class)) {
            $this->warn('Fortune Bonus feature flag is OFF — skipping enrollment.');

            return self::SUCCESS;
        }

        // Defaults to the PREVIOUS month, matching fortune:monthly-run: both
        // are scheduled on the 9th and must always act on the same month.
        $month = $this->option('month')
            ? Carbon::parse((string) $this->option('month').'-01')
            : Carbon::today()->startOfMonth()->subMonth();

        $this->info("Fortune Bonus enrollment — {$month->format('F Y')}");

        $result = $this->fortuneBonus->enrollEligible($month);

        if ($result['refused_pool_frozen']) {
            $this->warn("Refused — the {$month->format('F Y')} Fortune pool is already frozen. Nobody can be enrolled after the month's point value is fixed; the month is closed.");

            return self::SUCCESS;
        }

        $this->line('Enrolled: '.$result['enrolled']);
        $this->line('Skipped (ineligible, already enrolled, or not entered): '.$result['skipped_ineligible']);
        $this->line('  of which repurchase wallet not zero at month end: '.$result['skipped_wallet_nonzero']);
        $this->line('  of which not entered because the 29,524-position matrix was full: '.$result['skipped_matrix_full']);

        return self::SUCCESS;
    }
}
