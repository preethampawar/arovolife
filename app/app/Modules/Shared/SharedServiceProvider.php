<?php

declare(strict_types=1);

namespace App\Modules\Shared;

use App\Modules\Shared\Console\Commands\GenerateSbomCommand;
use Illuminate\Support\ServiceProvider;

final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateSbomCommand::class,
            ]);
        }
    }
}
