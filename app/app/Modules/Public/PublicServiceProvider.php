<?php

declare(strict_types=1);

namespace App\Modules\Public;

use App\Modules\Public\Console\PurgeStaleContactInquiriesCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class PublicServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeStaleContactInquiriesCommand::class]);

            // Daily at 03:00 IST — well outside business hours, so the
            // audit log entry isn't surrounded by other admin activity.
            // The Docker scheduler container runs `schedule:run` every
            // minute (see docker-compose).
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('contact-inquiries:purge')
                    ->dailyAt('03:00')
                    ->timezone('Asia/Kolkata')
                    ->onOneServer()
                    ->withoutOverlapping();
            });
        }
    }
}
