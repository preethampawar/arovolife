<?php

declare(strict_types=1);

namespace App\Modules\Grievance;

use App\Modules\Grievance\Console\Commands\GrievancePurgeExpiredCommand;
use App\Modules\Grievance\Console\Commands\GrievanceSlaSweepCommand;
use App\Modules\Grievance\Events\GrievanceEscalated;
use App\Modules\Grievance\Events\GrievanceFiled;
use App\Modules\Grievance\Events\GrievanceReplyReceived;
use App\Modules\Grievance\Events\GrievanceResolved;
use App\Modules\Grievance\Events\GrievanceStatusUpdatePublished;
use App\Modules\Grievance\Listeners\AcknowledgeNewGrievance;
use App\Modules\Grievance\Listeners\AlertGrievanceOwner;
use App\Modules\Grievance\Listeners\AlertOwnerOfComplainantReply;
use App\Modules\Grievance\Listeners\SendGrievanceResolution;
use App\Modules\Grievance\Listeners\SendGrievanceStatusUpdate;
use App\Modules\Grievance\Models\Ticket;
use App\Modules\Grievance\Policies\TicketPolicy;
use App\Modules\Grievance\Services\GrievanceSettingsService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class GrievanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton so the settings table is read once per request or command
        // run rather than once per SLA calculation.
        $this->app->singleton(GrievanceSettingsService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        // Explicit registration: Laravel only auto-discovers policies for
        // App\Models\*, and every model in this app lives under App\Modules.
        Gate::policy(Ticket::class, TicketPolicy::class);

        Event::listen(GrievanceFiled::class, AcknowledgeNewGrievance::class);
        Event::listen(GrievanceFiled::class, AlertGrievanceOwner::class);
        Event::listen(GrievanceEscalated::class, AlertGrievanceOwner::class);
        Event::listen(GrievanceResolved::class, SendGrievanceResolution::class);
        Event::listen(GrievanceStatusUpdatePublished::class, SendGrievanceStatusUpdate::class);
        Event::listen(GrievanceReplyReceived::class, AlertOwnerOfComplainantReply::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                GrievanceSlaSweepCommand::class,
                GrievancePurgeExpiredCommand::class,
            ]);
        }
    }
}
