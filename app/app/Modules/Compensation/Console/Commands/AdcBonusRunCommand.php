<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\AreteDevelopmentCenterBonusService;
use App\Modules\Compensation\Support\OpenMonthGuard;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Support\IndianNumber as Number;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class AdcBonusRunCommand extends Command
{
    protected $signature = 'adc:monthly-run
                            {--month= : Month to run (YYYY-MM, defaults to previous month)}
                            {--in-flight : Testing only — run for a month that has not closed; the freeze is provisional}';

    protected $description = 'Calculate and credit Arete Development Center Bonus (runs on the 1st)';

    public function __construct(private readonly AreteDevelopmentCenterBonusService $adcBonus)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class)) {
            $this->warn('Arete Development Center Bonus feature flag is OFF — skipping run.');

            return self::SUCCESS;
        }

        $month = $this->option('month')
            ? Carbon::parse((string) $this->option('month').'-01')
            : Carbon::today()->startOfMonth()->subMonth();

        if (! $this->option(OpenMonthGuard::OPTION) && ($refusal = OpenMonthGuard::refusal($month)) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $this->info("ADC Bonus — {$month->format('F Y')}");

        $result = $this->adcBonus->runForMonth($month);

        $this->line('Centers credited: '.$result['credited']);
        $this->line('Skipped (no BV): '.$result['skipped_no_bv']);
        $this->line('Skipped (net-negative BV): '.$result['skipped_net_negative']);
        $this->line('Total net credited: ₹'.Number::format($result['total_net_paise'] / 100, 2));

        return self::SUCCESS;
    }
}
