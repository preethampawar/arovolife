<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\GrowthBoosterBonusService;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Support\IndianNumber as Number;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class GbbMonthlyRunCommand extends Command
{
    protected $signature = 'gbb:monthly-run
                            {--month= : Month to run (YYYY-MM, defaults to previous month)}';

    protected $description = 'Calculate and credit the Growth Booster Bonus for a calendar month';

    public function __construct(private readonly GrowthBoosterBonusService $gbb)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Feature::for(null)->active(GrowthBoosterBonusFeature::class)) {
            $this->warn('Growth Booster Bonus feature flag is OFF — skipping run.');

            return self::SUCCESS;
        }

        $month = $this->option('month')
            ? Carbon::parse((string) $this->option('month').'-01')
            : Carbon::today()->startOfMonth()->subMonth();

        $this->info("Growth Booster Bonus — {$month->format('F Y')}");

        $result = $this->gbb->runForMonth($month);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Pool', '₹'.Number::format($result['pool_paise'] / 100, 2)],
                ['Total AGP', Number::format($result['total_agp'])],
                ['Point value', '₹'.Number::format($result['point_value_paise'] / 100, 2)],
                ['Distributors credited', $result['credited']],
                ['Held (repurchase grace)', $result['held']],
                ['Suspended (grace lapsed)', $result['suspended']],
                ['Skipped (no AGP)', $result['skipped_no_agp']],
            ],
        );

        return self::SUCCESS;
    }
}
