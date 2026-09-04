<?php

declare(strict_types=1);

namespace App\Modules\Compensation;

use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\PayoutGatewaySettings;
use App\Modules\Compensation\Services\RazorpayPayoutGateway;
use App\Modules\Compensation\Support\RazorpayPayoutPayloadScrubber;
use Illuminate\Support\ServiceProvider;

final class CompensationServiceProvider extends ServiceProvider
{
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
    }
}
