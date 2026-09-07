<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\EngineStatusService;
use App\Modules\Compensation\Services\IncomeEligibilityService;
use App\Modules\Compensation\Services\RankQualificationService;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

final class RankCheckCommand extends Command
{
    protected $signature = 'rank:check-qualifications
                            {--month= : Month to check (YYYY-MM, defaults to the previous month)}
                            {--occurrence=1 : PYP occurrence number (1-3)}
                            {--force : Run even though repurchase:evaluate has not run past the month end}';

    protected $description = 'Check and record rank qualifications for a calendar month (PYP-aware)';

    public function __construct(
        private readonly RankQualificationService $rankQual,
        private readonly IncomeEligibilityService $eligibility,
        private readonly EngineStatusService $engineStatus,
    ) {
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

        // Rank qualification sums Genos BV over the days the distributor was NOT
        // failed, and which days those are is written by exactly one process,
        // `repurchase:evaluate`. A cycle due on the last day of the month is only
        // resolved by the run dated the 1st of the next month, and a late
        // fulfilment is stamped there too — run before it and days the client's
        // rules forfeit would count toward the rank, permanently, on a rank the
        // Rank Bonus then pays. An evaluate run dated on or after the 1st of the
        // following month is proof every cycle due in the month has been judged.
        $evaluateFrom = $month->copy()->startOfMonth()->addMonthNoOverflow();

        if ($this->eligibility->engineActive()
            && ! $this->option('force')
            && ! $this->engineStatus->hasSucceededRunOnOrAfter('repurchase.evaluate', $evaluateFrom)) {
            Log::critical('rank.check.refused_missing_evaluate', [
                'month' => $month->format('Y-m'),
                'evaluate_required_from' => $evaluateFrom->toDateString(),
            ]);

            $this->error(
                "Refusing to check {$month->format('F Y')} rank qualifications: the repurchase engine is on but "
                ."`repurchase:evaluate` has no succeeded run for {$evaluateFrom->toDateString()} or later, so cycles "
                ."due at the end of the month are unjudged and forfeited days would count toward the rank.\n"
                ."Run `php artisan repurchase:evaluate --date={$evaluateFrom->toDateString()}` first, then re-run this "
                .'command (or pass --force to override).'
            );

            return self::FAILURE;
        }

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
