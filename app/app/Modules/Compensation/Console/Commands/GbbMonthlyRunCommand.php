<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Exceptions\RepurchaseVerdictsPending;
use App\Modules\Compensation\Exceptions\RepurchaseWalletVerdictNotAvailable;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\RankQualification;
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

        // GBB reads EVERY month before the one it pays: rejectEverRanked()
        // excludes anyone who held a qualified rank in any earlier month (the
        // client 2026-10-09). An unchecked month's rankers are missing from the
        // rejection list, so they are credited and dilute everyone else — and
        // M-1's own check does not prove the older ones ran (a close that
        // aborted at the rank check and was never re-run leaves a gap behind a
        // later green month). So every month from the first one with Genos BV
        // or a qualification row up to M-1 must be checked, oldest first.
        if (! $this->option('force')) {
            $rankMonth = $month->copy()->subMonthNoOverflow()->startOfMonth();

            foreach ($this->rankMonthsToVerify($rankMonth) as $checkMonth) {
                // Before M-1, a flag-off skipped check counts whatever the flag
                // is now: the engine was off, so no qualification row exists
                // for that month and nobody can be missed. M-1 stays strict.
                if (RankQualificationsGate::checkedFor($checkMonth)
                    || ($checkMonth->lt($rankMonth) && RankQualificationsGate::skippedForFeatureFlagOff($checkMonth))) {
                    continue;
                }

                // A month with zero Genos BV could not have produced a rank
                // qualification, so its exclusion set is provably empty — the
                // first replayed month of a recompute (no BV precedes it).
                // Together with the flag-off acceptance above, these are the
                // only two ways a month passes without a succeeded check.
                if (! RankQualificationsGate::monthHadNoGenosBv($checkMonth)) {
                    $refusal = RankQualificationsGate::refusalMessage(
                        $checkMonth,
                        'Growth Booster excludes anyone who has ever ranked. Running now would miss that month\'s rankers,'
                        ."\ncredit distributors the plan bars, and dilute the point value for the eligible.",
                    );

                    $this->error($refusal);

                    app(EngineRunContext::class)->noteSkipped($refusal);

                    return self::FAILURE;
                }

                Log::info('gbb.monthly.prior_month_check_waived', ['month' => $checkMonth->format('Y-m')]);
            }
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
        } catch (RepurchaseVerdictsPending $e) {
            // An earner's cycle due on or before the month end has no verdict
            // yet (A-G1, fail-safe principle 2). Recorded as FAILED with the
            // message — not skipped — because running `repurchase:evaluate`
            // and re-running fixes it, and the digest must say so.
            $this->error($e->getMessage());

            app(EngineRunContext::class)->noteFailed($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Pool', '₹'.Number::format($result['pool_paise'] / 100, 2)],
                ['Total AGP', Number::format($result['total_agp'])],
                ['Point value', '₹'.Number::format($result['point_value_paise'] / 100, 2)],
                ['Distributors credited', $result['credited']],
                ['Blocked (repurchase condition failed at month end)', $result['repurchase_failed']],
                ['Forfeited (repurchase wallet not cleared at month end)', $result['wallet_blocked']],
                ['Held — legacy rows only', $result['held']],
                ['Suspended — legacy rows only', $result['suspended']],
                ['Skipped (no AGP)', $result['skipped_no_agp']],
                ['Refused (AGP earned after the freeze)', $result['qualified_after_freeze']],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Every month whose rank qualifications the lifetime exclusion reads, oldest
     * first: from the first month with a group_bv_daily or rank_qualifications
     * row up to and including $lastMonth (M-1 is always included, as before).
     *
     * @return list<Carbon>
     */
    private function rankMonthsToVerify(Carbon $lastMonth): array
    {
        $first = $lastMonth->copy();

        foreach ([GroupBvDaily::query()->min('date'), RankQualification::query()->min('month_start')] as $earliest) {
            if ($earliest !== null) {
                $candidate = Carbon::parse((string) $earliest)->startOfMonth();
                $first = $candidate->lt($first) ? $candidate : $first;
            }
        }

        $months = [];

        for ($m = $first; $m->lte($lastMonth); $m = $m->copy()->addMonthNoOverflow()) {
            $months[] = $m->copy();
        }

        return $months;
    }
}
