<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Listeners;

use App\Modules\Grievance\Events\GrievanceEscalated;
use App\Modules\Grievance\Events\GrievanceFiled;
use App\Modules\Grievance\Models\Ticket;
use App\Modules\Grievance\Notifications\GrievanceOfficerAlertNotification;
use App\Modules\Grievance\Services\GrievanceSettingsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the officer who owns a grievance that it is theirs — on filing, and
 * again each time it climbs the §4 ladder.
 *
 * A tracker nobody is told about is a tracker nobody works.
 */
final class AlertGrievanceOwner implements ShouldQueue
{
    public function __construct(private readonly GrievanceSettingsService $settings) {}

    public function handle(GrievanceFiled|GrievanceEscalated $event): void
    {
        $ticket = Ticket::find($event->ticketId);

        if ($ticket === null) {
            return;
        }

        $mailbox = $this->settings->mailboxForTicket($ticket->category, $ticket->escalation_level);

        if ($mailbox === '') {
            return;
        }

        Notification::route('mail', $mailbox)->notify(
            new GrievanceOfficerAlertNotification(
                ticketNo: $ticket->ticket_no,
                categoryLabel: $ticket->category->label(),
                ownerLabel: $ticket->escalation_level->label(),
                resolutionBy: $ticket->sla_resolution_at->toFormattedDayDateString(),
                adminUrl: route('admin.grievances.show', $ticket),
                reason: $event instanceof GrievanceEscalated
                    ? 'Escalated from step '.$event->fromLevel.' to step '.$event->toLevel.'.'
                    : null,
            )
        );
    }
}
