<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment;

use App\Modules\Fulfilment\Services\CourierGatewayResolver;
use App\Modules\Fulfilment\Services\ManualCourier;
use App\Modules\Fulfilment\Support\FulfilmentSettings;
use Illuminate\Support\ServiceProvider;

final class FulfilmentServiceProvider extends ServiceProvider
{
    /**
     * Singletons, following the Payments module: `FulfilmentSettings` caches
     * its rows per instance, so a fresh instance per injection would re-read
     * the settings table on every dispatch.
     */
    public function register(): void
    {
        $this->app->singleton(FulfilmentSettings::class);
        $this->app->singleton(ManualCourier::class);
        $this->app->singleton(CourierGatewayResolver::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
