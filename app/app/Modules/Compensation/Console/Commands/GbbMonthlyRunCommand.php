<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\GrowthBoosterBonusService;
use App\Modules\Compensation\Support\RankQualificationsGate;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Support\IndianNumber as Number;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class GbbMonthlyRunCommand extends Command
{
    protected $signature = 'gbb:monthly-run
                            {--month= : Month to run (YYYY-MM, defaults to previous month)}
                            {--force : Run even when the rank qualification check has not succeeded}';

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

        // GBB reads the month BEFORE the one it pays: rejectRankedLastMonth()
        // excludes anyone who held a qualified rank in M-1. With that month
        // unchecked the rejection list is empty, so every excluded distributor
        // is credited and the inflated denominator dilutes everyone else.
        $rankMonth = $month->copy()->subMonthNoOverflow()->startOfMonth();

        if (! $this->option('force') && ! RankQualificationsGate::checkedFor($rankMonth)) {
            $this->error(RankQualificationsGate::refusalMessage(
                $rankMonth,
                'Growth Booster excludes anyone who ranked that month. Running now would exclude nobody,'
                ."\ncredit distributors the plan bars, and dilute the point value for the eligible.",
            ));

            return self::FAILURE;
        }

        $this->info("Growth Booster Bonus — {$month->format('F Y')}");

        $result = $this->gbb->runForMonth($month);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Pool', '₹'.Number::format($result['pool_paise'] / 100, 2)],
                ['Total AGP', Number::format($result['total_agp'])],
                ['Point value', '₹'.Number::format($result['point_value_paise'] / 100, 2)],
                ['Distributors credited', $result['credited']],
                ['Forfeited (repurchase wallet not cleared at month end)', $result['wallet_blocked']],
                ['Held — legacy rows only', $result['held']],
                ['Suspended — legacy rows only', $result['suspended']],
                ['Skipped (no AGP)', $result['skipped_no_agp']],
                ['Refused (AGP earned after the freeze)', $result['qualified_after_freeze']],
            ],
        );

        return self::SUCCESS;
    }
}
