<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Exceptions\RepurchaseWalletVerdictNotAvailable;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\RankBonusService;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compensation\Support\FrozenPayoutGuard;
use App\Modules\Compensation\Support\OpenMonthGuard;
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
                            {--force : Run even when the rank qualification check has not succeeded for the month}
                            {--in-flight : Testing only — run for a month that has not closed; the freeze is provisional}';

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

        if (! $this->option(OpenMonthGuard::OPTION) && ($refusal = OpenMonthGuard::refusal($month)) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        // Not overridable by --force or --in-flight, and checked before any
        // freeze or credit: once finance has approved the month's payout batch,
        // money has left on these figures, and once the batch has been BUILT
        // and is waiting for approval nothing may be credited into the month
        // either — the sweep is over, so the credit would never be picked up
        // and finance would approve a batch that no longer matches the ledger
        // (D9, A10). FrozenPayoutGuard is the one place that decides both.
        if (($frozen = FrozenPayoutGuard::creditingRefusal($month)) !== null) {
            $this->error($frozen);

            app(EngineRunContext::class)->noteSkipped($frozen);

            return self::FAILURE;
        }

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

        // `--in-flight` freezes a partial month deliberately; it cannot conjure
        // a month-end repurchase-wallet verdict for a month that has not ended,
        // and the gate refuses rather than silently answering "as of now" —
        // which is how one engine's own deductions came to be counted against
        // the month it was judging (staging, 14 Sep 2026). Reported as a clean
        // refusal rather than an uncaught exception; the message says what to
        // do. With the repurchase engine off there is no verdict to want and
        // this never fires.
        try {
            $result = $this->rankBonus->runForMonth($month);
        } catch (RepurchaseWalletVerdictNotAvailable $e) {
            $this->error($e->getMessage());

            app(EngineRunContext::class)->noteSkipped($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Company turnover: ₹'.Number::format($result['turnover_paise'] / 100, 2));
        $this->line('Distributors credited: '.$result['credited']);

        if (($result['qualified_after_freeze'] ?? 0) > 0) {
            $this->warn(
                'Qualified after the pool was frozen: '.$result['qualified_after_freeze']
                .' — refused, not paid. Review them on the admin Rank Bonus report for '.$month->format('F Y').'.',
            );
        }

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
