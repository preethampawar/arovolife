<?php

declare(strict_types=1);

namespace App\Modules\Consent;

use App\Modules\Consent\Console\Commands\BackfillAgreementsCommand;
use Illuminate\Support\ServiceProvider;

final class ConsentServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        if ($this->app->runningInConsole()) {
            // Module commands sit outside app/Console/Commands, where nothing
            // auto-discovers them — an unregistered one fails only at the
            // moment somebody runs it.
            $this->commands([
                BackfillAgreementsCommand::class,
            ]);
        }
    }
}
