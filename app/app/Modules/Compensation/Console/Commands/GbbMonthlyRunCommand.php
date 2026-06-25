<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\GrowthBoosterBonusService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

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
        $month = $this->option('month')
            ? Carbon::parse((string) $this->option('month').'-01')
            : Carbon::today()->subMonth()->startOfMonth();

        $this->info("Growth Booster Bonus — {$month->format('F Y')}");

        $result = $this->gbb->runForMonth($month);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Pool', '₹'.number_format($result['pool_paise'] / 100, 2)],
                ['Total AGP', number_format($result['total_agp'])],
                ['Distributors credited', $result['credited']],
                ['Skipped (no AGP)', $result['skipped_no_agp']],
            ],
        );

        return self::SUCCESS;
    }
}
