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
                            {--month= : Month to enroll for (YYYY-MM, defaults to current month)}';

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

        $month = $this->option('month')
            ? Carbon::parse((string) $this->option('month').'-01')
            : Carbon::today()->startOfMonth();

        $this->info("Fortune Bonus enrollment — {$month->format('F Y')}");

        $result = $this->fortuneBonus->enrollEligible($month);

        $this->line('Enrolled: '.$result['enrolled']);
        $this->line('Skipped (ineligible or already enrolled): '.$result['skipped_ineligible']);

        return self::SUCCESS;
    }
}
