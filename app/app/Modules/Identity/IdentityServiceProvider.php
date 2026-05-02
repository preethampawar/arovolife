<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Genealogy\Services\PlacementEngine;
use App\Modules\Identity\Services\RegistrationService;
use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Support\ServiceProvider;

final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WizardStateService::class, function ($app) {
            return new WizardStateService($app['session.store']);
        });

        $this->app->singleton(RegistrationService::class, function ($app) {
            return new RegistrationService(
                new PlacementEngine($app['db'], $app['events']),
                $app['db'],
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
