<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter;

use App\Modules\ActionCenter\Contracts\ActionProvider;
use App\Modules\ActionCenter\Providers\Orders\PaidNotPackedProvider;
use App\Modules\ActionCenter\Services\ActionCenterRegistry;
use App\Modules\ActionCenter\Services\ActionCenterService;
use App\Modules\ActionCenter\Services\ActionCenterSettings;
use Illuminate\Support\ServiceProvider;

/**
 * Providers are registered here in catalogue order (plan §4) and tagged, so a
 * later slice adds an action type by writing the provider class and adding one
 * line to `PROVIDERS` — nothing else in the module changes.
 */
final class ActionCenterServiceProvider extends ServiceProvider
{
    /**
     * In catalogue order. Later slices append their types here.
     *
     * @var array<int, class-string<ActionProvider>>
     */
    private const PROVIDERS = [
        PaidNotPackedProvider::class,
    ];

    public function register(): void
    {
        $this->app->singleton(ActionCenterSettings::class);

        foreach (self::PROVIDERS as $provider) {
            $this->app->singleton($provider);
        }

        $this->app->tag(self::PROVIDERS, 'action-center.providers');

        $this->app->singleton(
            ActionCenterRegistry::class,
            fn ($app): ActionCenterRegistry => new ActionCenterRegistry($app->tagged('action-center.providers')),
        );

        $this->app->singleton(ActionCenterService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
