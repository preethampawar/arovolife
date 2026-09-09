<?php

declare(strict_types=1);

namespace App\Modules\Messaging;

use App\Modules\Messaging\Console\Commands\PurgeExpiredMessagesCommand;
use App\Modules\Messaging\Services\MessagingSettingsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MessagingSettingsService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeExpiredMessagesCommand::class]);

            // Weekly rather than daily: the window is 24 months, so a day's
            // drift is meaningless, and one audit row a week is easier to read
            // than seven. Sunday 03:20 IST keeps it clear of the 03:00 contact
            // -inquiry purge so the two do not contend for the same rows of
            // the audit log at the same second.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('messages:purge')
                    ->weeklyOn(0, '03:20')
                    ->timezone('Asia/Kolkata')
                    ->onOneServer()
                    ->withoutOverlapping();
            });
        }
    }
}
