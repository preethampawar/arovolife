<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\RankBonusService;
use App\Modules\Compensation\Support\RankQualificationsGate;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Support\IndianNumber as Number;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class RankBonusRunCommand extends Command
{
    protected $signature = 'rank:monthly-run
                            {--month= : Month to run (YYYY-MM, defaults to previous month)}
                            {--force : Run even when the rank qualification check has not succeeded for the month}';

    protected $description = 'Calculate and credit the Rank Bonus for a calendar month (runs on the 1st)';

    public function __construct(
        private readonly RankBonusService $rankBonus,
        private readonly CompensationPlanSettingsService $plan,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Feature::for(null)->active(RankBonusFeature::class)) {
            $this->warn('Rank Bonus feature flag is OFF — skipping run.');

            return self::SUCCESS;
        }

        $month = $this->option('month')
            ? Carbon::parse((string) $this->option('month').'-01')
            : Carbon::today()->startOfMonth()->subMonth();

        // Reads the month it pays.
        if (! $this->option('force') && ! RankQualificationsGate::checkedFor($month)) {
            $this->error(RankQualificationsGate::refusalMessage(
                $month,
                'Running now would pay no RAP achiever while still issuing AO-GO grants against the whole'
                ."\nRank 1 pool and consuming a lifetime use.",
            ));

            return self::FAILURE;
        }

        $this->info("Rank Bonus — {$month->format('F Y')}");

        $result = $this->rankBonus->runForMonth($month);

        $this->line('Company turnover: ₹'.Number::format($result['turnover_paise'] / 100, 2));
        $this->line('Distributors credited: '.$result['credited']);
        $this->newLine();

        $rows = [];
        foreach ($result['by_rank'] as $rank => $data) {
            $rankName = $this->plan->rankName($rank);
            $rows[] = [
                $rank,
                $rankName,
                $data['qualifiers'],
                $data['held'] ?? 0,
                $data['aogo_grants'] ?? 0,
                $data['total_points'] !== null ? (string) $data['total_points'] : '—',
                $data['point_value_paise'] !== null ? '₹'.Number::format($data['point_value_paise'] / 100, 0) : '—',
                '₹'.Number::format($data['pool_paise'] / 100, 2),
                '₹'.Number::format($data['gross_total'] / 100, 2),
            ];
        }

        $this->table(
            ['Rank', 'Name', 'Payable', 'Held', 'AO-GO', 'Points', 'Point Value', 'Pool', 'Gross Credited'],
            $rows,
        );

        return self::SUCCESS;
    }
}
