<?php

declare(strict_types=1);

namespace App\Modules\Consent;

use App\Modules\Consent\Console\Commands\BackfillAgreementsCommand;
use App\Modules\Consent\Console\Commands\NotifyUnreadPlanChangesCommand;
use App\Modules\Consent\Services\PlanChangeNotices;
use Illuminate\Support\ServiceProvider;

final class ConsentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request, so the published page is read and hashed
        // once however many times it is asked.
        $this->app->scoped(PlanChangeNotices::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        if ($this->app->runningInConsole()) {
            // Module commands sit outside app/Console/Commands, where nothing
            // auto-discovers them — an unregistered one fails only at the
            // moment somebody runs it.
            $this->commands([
                BackfillAgreementsCommand::class,
                NotifyUnreadPlanChangesCommand::class,
            ]);

            // plan:notify-unread is deliberately not scheduled: plan changes are
            // told to distributors verbally, not by email (user decision 2026-10-26).
        }
    }
}
