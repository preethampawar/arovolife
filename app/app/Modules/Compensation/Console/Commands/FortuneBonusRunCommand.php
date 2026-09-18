<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Exceptions\RepurchaseWalletVerdictNotAvailable;
use App\Modules\Compensation\Services\FortuneBonusService;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compensation\Support\FrozenPayoutGuard;
use App\Modules\Compensation\Support\OpenMonthGuard;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Support\IndianNumber as Number;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class FortuneBonusRunCommand extends Command
{
    protected $signature = 'fortune:monthly-run
                            {--month= : Month to run (YYYY-MM, defaults to previous month)}
                            {--in-flight : Testing only — run for a month that has not closed; the freeze is provisional}';

    protected $description = 'Calculate and credit Fortune Bonus for enrolled participants (runs on the 1st)';

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

        $this->info("Fortune Bonus payout — {$month->format('F Y')}");

        // `--in-flight` freezes a partial month deliberately; it cannot conjure
        // a month-end repurchase-wallet verdict for a month that has not ended,
        // and the gate refuses rather than silently answering "as of now" —
        // which is how one engine's own deductions came to be counted against
        // the month it was judging (staging, 14 Sep 2026). Reported as a clean
        // refusal rather than an uncaught exception; the message says what to
        // do. With the repurchase engine off there is no verdict to want and
        // this never fires.
        try {
            $result = $this->fortuneBonus->runForMonth($month);
        } catch (RepurchaseWalletVerdictNotAvailable $e) {
            $this->error($e->getMessage());

            app(EngineRunContext::class)->noteSkipped($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Pool: ₹'.Number::format($result['pool_paise'] / 100, 2));
        $this->line('Total FB points: '.Number::format($result['total_points']));
        $this->line('Minimum guarantee reserved: ₹'.Number::format($result['guaranteed_total_paise'] / 100, 2));
        if ($result['is_shortfall']) {
            $this->warn('Shortfall month — the pool could not cover the minimum guarantees; every qualifier received the same pro-rated share.');
        }
        $this->line('Credited: '.$result['credited']);
        $this->line('Forfeited (repurchase wallet not cleared at month end): '.$result['repurchase_wallet_blocked'].' — ₹'.Number::format($result['repurchase_wallet_blocked_paise'] / 100, 2));
        $this->line('Skipped (zero income): '.$result['skipped_zero_income']);
        $this->line('Total credited: ₹'.Number::format($result['total_net_paise'] / 100, 2));
        $this->line('Leftover: ₹'.Number::format($result['leftover_paise'] / 100, 2));

        return self::SUCCESS;
    }
}
