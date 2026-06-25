<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Services\RankBonusService;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class RankBonusRunCommand extends Command
{
    protected $signature = 'rank:monthly-run
                            {--month= : Month to run (YYYY-MM, defaults to previous month)}';

    protected $description = 'Calculate and credit the Rank Bonus for a calendar month (runs on 8th)';

    public function __construct(private readonly RankBonusService $rankBonus)
    {
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
            : Carbon::today()->subMonth()->startOfMonth();

        $this->info("Rank Bonus — {$month->format('F Y')}");

        $result = $this->rankBonus->runForMonth($month);

        $this->line('Company turnover: ₹'.number_format($result['turnover_paise'] / 100, 2));
        $this->line('Distributors credited: '.$result['credited']);
        $this->newLine();

        $rows = [];
        foreach ($result['by_rank'] as $rank => $data) {
            $rankName = RankQualification::RANK_NAMES[$rank];
            $rows[] = [
                $rank,
                $rankName,
                $data['qualifiers'],
                '₹'.number_format($data['pool_paise'] / 100, 2),
                '₹'.number_format($data['net_total'] / 100, 2),
            ];
        }

        $this->table(['Rank', 'Name', 'Qualifiers', 'Pool', 'Net Credited'], $rows);

        return self::SUCCESS;
    }
}
