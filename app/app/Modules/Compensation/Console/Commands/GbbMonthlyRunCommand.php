<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Exceptions\RepurchaseWalletVerdictNotAvailable;
use App\Modules\Compensation\Services\GrowthBoosterBonusService;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compensation\Support\FrozenPayoutGuard;
use App\Modules\Compensation\Support\OpenMonthGuard;
use App\Modules\Compensation\Support\RankQualificationsGate;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Support\IndianNumber as Number;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

final class GbbMonthlyRunCommand extends Command
{
    protected $signature = 'gbb:monthly-run
                            {--month= : Month to run (YYYY-MM, defaults to previous month)}
                            {--force : Run even when the rank qualification check has not succeeded}
                            {--in-flight : Testing only — run for a month that has not closed; the freeze is provisional}';

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

        // GBB reads the month BEFORE the one it pays: rejectRankedLastMonth()
        // excludes anyone who held a qualified rank in M-1. With that month
        // unchecked the rejection list is empty, so every excluded distributor
        // is credited and the inflated denominator dilutes everyone else.
        $rankMonth = $month->copy()->subMonthNoOverflow()->startOfMonth();

        if (! $this->option('force') && ! RankQualificationsGate::checkedFor($rankMonth)) {
            // A month with zero Genos BV could not have produced a rank
            // qualification, so the exclusion set is provably empty — this is
            // the first replayed month (no BV precedes it) and never fires in
            // production, where every month has a scheduled check.
            if (! RankQualificationsGate::monthHadNoGenosBv($rankMonth)) {
                $this->error(RankQualificationsGate::refusalMessage(
                    $rankMonth,
                    'Growth Booster excludes anyone who ranked that month. Running now would exclude nobody,'
                    ."\ncredit distributors the plan bars, and dilute the point value for the eligible.",
                ));

                return self::FAILURE;
            }

            Log::info('gbb.monthly.prior_month_check_waived', ['month' => $rankMonth->format('Y-m')]);
        }

        $this->info("Growth Booster Bonus — {$month->format('F Y')}");

        // `--in-flight` freezes a partial month deliberately; it cannot conjure
        // a month-end repurchase-wallet verdict for a month that has not ended,
        // and the gate refuses rather than silently answering "as of now" —
        // which is how one engine's own deductions came to be counted against
        // the month it was judging (staging, 14 Sep 2026). Reported as a clean
        // refusal rather than an uncaught exception; the message says what to
        // do. With the repurchase engine off there is no verdict to want and
        // this never fires.
        try {
            $result = $this->gbb->runForMonth($month);
        } catch (RepurchaseWalletVerdictNotAvailable $e) {
            $this->error($e->getMessage());

            app(EngineRunContext::class)->noteSkipped($e->getMessage());

            return self::FAILURE;
        }

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
