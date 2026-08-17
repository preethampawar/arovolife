<?php

declare(strict_types=1);

namespace App\Modules\Shared;

use App\Modules\Shared\Console\Commands\GenerateSbomCommand;
use App\Modules\Shared\Security\ClamAvScanner;
use App\Modules\Shared\Security\UnconfiguredScanner;
use App\Modules\Shared\Security\VirusScanner;
use Illuminate\Support\ServiceProvider;

final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A real scanner when clamd is configured, and a guard that refuses in
        // production when it is not. Bound here rather than resolved at each
        // upload site so there is one place that decides, and one place to
        // change when the daemon moves (T-6.1 finding H-4).
        $this->app->singleton(VirusScanner::class, function ($app): VirusScanner {
            $host = (string) config('services.clamav.host', '');

            if ($host === '') {
                return new UnconfiguredScanner((string) $app->environment());
            }

            return new ClamAvScanner(
                $host,
                (int) config('services.clamav.port', 3310),
                (int) config('services.clamav.timeout', 15),
            );
        });
    }

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
