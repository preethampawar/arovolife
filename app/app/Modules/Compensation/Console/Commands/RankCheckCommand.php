<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\RankQualificationService;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class RankCheckCommand extends Command
{
    protected $signature = 'rank:check-qualifications
                            {--month= : Month to check (YYYY-MM, defaults to the previous month)}
                            {--occurrence=1 : PYP occurrence number (1-3)}';

    protected $description = 'Check and record rank qualifications for a calendar month (PYP-aware)';

    public function __construct(private readonly RankQualificationService $rankQual)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Feature::for(null)->active(RankBonusFeature::class)) {
            $this->warn('Rank Bonus feature flag is OFF — skipping qualification check.');

            return self::SUCCESS;
        }

        // The previous month, matching gbb:monthly-run and rank:monthly-run.
        // Since this became a scheduled engine (1st, 00:15) the month it works
        // is the one that has just closed, and EngineRegistry records the run
        // against that month — a bare invocation must not disagree with the row
        // it writes, which rank:monthly-run reads as its prerequisite.
        $month = $this->option('month')
            ? Carbon::parse((string) $this->option('month').'-01')
            : Carbon::today()->startOfMonth()->subMonthNoOverflow();

        $occurrence = (int) ($this->option('occurrence') ?? 1);

        $this->info("Rank Qualification Check — {$month->format('F Y')} (occurrence #{$occurrence})");

        $result = $this->rankQual->checkForMonth($month, $occurrence);

        $rows = [];
        foreach (range(1, 9) as $rank) {
            $key = 'rank_'.$rank.'_count';
            $rows[] = ['Rank '.$rank, $result[$key]];
        }
        $rows[] = ['Total', $result['total_qualifications']];

        $this->table(['Rank', 'Qualifiers'], $rows);

        return self::SUCCESS;
    }
}
