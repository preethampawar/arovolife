<?php

declare(strict_types=1);

namespace App\Modules\Compliance;

use App\Modules\Compliance\Console\Commands\InactivityTerminationSweepCommand;
use App\Modules\Compliance\Console\Commands\RetentionReportCommand;
use App\Modules\Compliance\Console\Commands\VerifyAuditLogCommand;
use App\Modules\Compliance\Console\SendCoolingOffRemindersCommand;
use App\Modules\Compliance\Events\CoolingOffCancelled;
use App\Modules\Compliance\Listeners\SendCoolingOffCancelledMail;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class ComplianceServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        Event::listen(CoolingOffCancelled::class, SendCoolingOffCancelledMail::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                SendCoolingOffRemindersCommand::class,
                InactivityTerminationSweepCommand::class,
                VerifyAuditLogCommand::class,
                RetentionReportCommand::class,
            ]);

            // Daily at 09:00 IST. The Docker scheduler container runs
            // `php artisan schedule:run` every minute (see docker-compose).
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('cooling-off:remind')
                    ->dailyAt('09:00')
                    ->timezone('Asia/Kolkata')
                    ->onOneServer()
                    ->withoutOverlapping();

                // Agreement §21 dormancy sweep, 09:30 IST — after the
                // cooling-off reminders so the two never contend for the mail
                // queue. Safe to schedule while the feature is off: the
                // command reports and writes nothing until
                // `termination.inactivity_sweep_enabled` is turned on.
                $schedule->command('distributors:inactivity-sweep')
                    ->dailyAt('09:30')
                    ->timezone('Asia/Kolkata')
                    ->onOneServer()
                    ->withoutOverlapping();
            });
        }
    }
}
