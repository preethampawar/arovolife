<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Genealogy\Events\DistributorRegistered;
use App\Modules\Genealogy\Services\PlacementEngine;
use App\Modules\Identity\Events\KycResubmitted;
use App\Modules\Identity\Listeners\SendKycResubmittedMails;
use App\Modules\Identity\Listeners\SendRegistrationSubmittedMails;
use App\Modules\Identity\Services\RegistrationService;
use App\Modules\Identity\Services\TeamStatsService;
use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Support\Facades\Event;
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
                new PlacementEngine($app['db'], $app['events'], $app->make(TeamStatsService::class)),
                $app['db'],
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        // Registration / resubmission email wiring. Listeners send a welcome
        // (or confirmation) email to the distributor AND a new-queue-item
        // alert to the admin compliance team.
        Event::listen(DistributorRegistered::class, SendRegistrationSubmittedMails::class);
        Event::listen(KycResubmitted::class, SendKycResubmittedMails::class);
    }
}
