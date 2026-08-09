<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\FortuneBonusService;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Support\IndianNumber as Number;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class FortuneBonusRunCommand extends Command
{
    protected $signature = 'fortune:monthly-run
                            {--month= : Month to run (YYYY-MM, defaults to previous month)}';

    protected $description = 'Calculate and credit Fortune Bonus for enrolled participants (runs on 9th)';

    public function __construct(private readonly FortuneBonusService $fortuneBonus)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Feature::for(null)->active(FortuneBonusFeature::class)) {
            $this->warn('Fortune Bonus feature flag is OFF — skipping run.');

            return self::SUCCESS;
        }

        $month = $this->option('month')
            ? Carbon::parse((string) $this->option('month').'-01')
            : Carbon::today()->startOfMonth()->subMonth();

        $this->info("Fortune Bonus payout — {$month->format('F Y')}");

        $result = $this->fortuneBonus->runForMonth($month);

        $this->line('Pool: ₹'.Number::format($result['pool_paise'] / 100, 2));
        $this->line('Total FB points: '.Number::format($result['total_points']));
        $this->line('Minimum guarantee reserved: ₹'.Number::format($result['guaranteed_total_paise'] / 100, 2));
        if ($result['is_shortfall']) {
            $this->warn('Shortfall month — the pool could not cover the minimum guarantees; every qualifier received the same pro-rated share.');
        }
        $this->line('Credited: '.$result['credited']);
        $this->line('Skipped (zero income): '.$result['skipped_zero_income']);
        $this->line('Total credited: ₹'.Number::format($result['total_net_paise'] / 100, 2));
        $this->line('Leftover: ₹'.Number::format($result['leftover_paise'] / 100, 2));

        return self::SUCCESS;
    }
}
