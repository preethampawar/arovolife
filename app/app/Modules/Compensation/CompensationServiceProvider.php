<?php

declare(strict_types=1);

namespace App\Modules\Compensation;

use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\PayoutGatewaySettings;
use App\Modules\Compensation\Services\RazorpayPayoutGateway;
use App\Modules\Compensation\Support\RazorpayPayoutPayloadScrubber;
use App\Modules\Shared\Support\IndianNumber;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class CompensationServiceProvider extends ServiceProvider
{
    /**
     * Views whose copy states the TDS rate.
     *
     * @var list<string>
     */
    private const TDS_RATE_VIEWS = [
        'my-business',
        'dashboard._kpi-strip',
        'dashboard._income-snapshot',
        'components.bonus-credit-head',
        'income.dashboard',
        'income.fortune-bonus',
        'income.gsb-history',
        'income.rank-bonus',
        'income.wallet',
        'income.adc-bonus',
        'admin.compensation.weekly-payouts.index',
        'admin.compensation.weekly-payouts.show',
        'admin.compensation.monthly-payouts.index',
        'admin.compensation.monthly-payouts.show',
        'admin.compensation.fortune-bonus.index',
        'admin.compensation.distributors._tab-payouts',
    ];

    public function register(): void
    {
        // Singleton so the settings map and each ladder load at most once per
        // request/engine run. This is the single source of truth every engine
        // service reads its tunable parameters from.
        $this->app->singleton(CompensationPlanSettingsService::class);

        // Payout routing. PayoutGatewaySettings is a singleton for object
        // reuse only — it deliberately caches nothing, so a long-lived
        // compensation worker still sees a gateway switch immediately.
        $this->app->singleton(PayoutGatewaySettings::class);
        $this->app->singleton(RazorpayPayoutPayloadScrubber::class);
        $this->app->singleton(RazorpayPayoutGateway::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        // Copy that quotes the TDS rate reads the live developer setting
        // (comp.tds.rate_bp), never a hard-coded figure.
        View::composer(self::TDS_RATE_VIEWS, function (\Illuminate\View\View $view): void {
            $view->with('tdsRateLabel', IndianNumber::percentFromBp($this->app->make(CompensationPlanSettingsService::class)->tdsRateBp()));
        });
    }
}
