<?php

declare(strict_types=1);

namespace App\Modules\Compensation;

use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use Illuminate\Support\ServiceProvider;

final class CompensationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton so the settings map and each ladder load at most once per
        // request/engine run. This is the single source of truth every engine
        // service reads its tunable parameters from.
        $this->app->singleton(CompensationPlanSettingsService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
