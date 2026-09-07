<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\FortuneBonusService;
use App\Modules\Compensation\Support\RankQualificationsGate;
use App\Modules\Shared\Features\FortuneBonusFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class FortuneBonusEnrollCommand extends Command
{
    protected $signature = 'fortune:enroll-eligible
                            {--month= : Month to enroll for (YYYY-MM, defaults to previous month)}
                            {--force : Run even when the rank qualification check has not succeeded}';

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
        // are scheduled on the 1st and must always act on the same month.
        $month = $this->option('month')
            ? Carbon::parse((string) $this->option('month').'-01')
            : Carbon::today()->startOfMonth()->subMonth();

        // Fortune reads the month it enrols for: ranks 6–9 are barred. With the
        // month unchecked the bar is empty, and seniors enrolled into the
        // capacity-capped 29,524-position FCFS matrix permanently displace
        // eligible distributors for that month.
        if (! $this->option('force') && ! RankQualificationsGate::checkedFor($month)) {
            $this->error(RankQualificationsGate::refusalMessage(
                $month,
                'Enrolling now would apply no rank 6–9 exclusion and let ineligible seniors take'
                ."\nFCFS positions in the matrix, displacing eligible distributors for the month.",
            ));

            return self::FAILURE;
        }

        $this->info("Fortune Bonus enrollment — {$month->format('F Y')}");

        $result = $this->fortuneBonus->enrollEligible($month);

        if ($result['refused_pool_frozen']) {
            $this->warn("Refused — the {$month->format('F Y')} Fortune pool is already frozen. Nobody can be enrolled after the month's point value is fixed; the month is closed.");

            return self::SUCCESS;
        }

        $this->line('Enrolled: '.$result['enrolled']);
        $this->line('Skipped (ineligible, already enrolled, or not entered): '.$result['skipped_ineligible']);
        $this->line('  of which not entered because the 29,524-position matrix was full: '.$result['skipped_matrix_full']);

        return self::SUCCESS;
    }
}
