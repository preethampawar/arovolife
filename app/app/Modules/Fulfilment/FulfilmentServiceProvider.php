<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment;

use App\Modules\Fulfilment\Services\CollectionHandoverService;
use App\Modules\Fulfilment\Services\CourierGatewayResolver;
use App\Modules\Fulfilment\Services\DispatchService;
use App\Modules\Fulfilment\Services\ManualCourier;
use App\Modules\Fulfilment\Services\ShiprocketClient;
use App\Modules\Fulfilment\Services\ShiprocketGateway;
use App\Modules\Fulfilment\Support\FulfilmentSettings;
use App\Modules\Fulfilment\Support\ShiprocketPayloadScrubber;
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
        $this->app->singleton(ShiprocketPayloadScrubber::class);
        $this->app->singleton(ShiprocketClient::class);
        $this->app->singleton(ShiprocketGateway::class);
        $this->app->singleton(CourierGatewayResolver::class);
        $this->app->singleton(DispatchService::class);
        $this->app->singleton(CollectionHandoverService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
