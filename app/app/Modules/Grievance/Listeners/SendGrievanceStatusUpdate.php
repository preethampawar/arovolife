<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Listeners;

use App\Modules\Grievance\Events\GrievanceStatusUpdatePublished;
use App\Modules\Grievance\Models\Ticket;
use App\Modules\Grievance\Notifications\GrievanceStatusUpdateNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Delivers the 15-day progress update on a third-party-dependent grievance.
 */
final class SendGrievanceStatusUpdate implements ShouldQueue
{
    public function handle(GrievanceStatusUpdatePublished $event): void
    {
        $ticket = Ticket::find($event->ticketId);

        if ($ticket === null) {
            return;
        }

        $email = $ticket->notifiableEmail();

        if ($email === null) {
            return;
        }

        Notification::route('mail', $email)->notify(
            new GrievanceStatusUpdateNotification(
                ticketNo: $ticket->ticket_no,
                subject: $ticket->subject,
                updateNote: $event->note,
                resolutionBy: $ticket->sla_resolution_at->toFormattedDayDateString(),
            )
        );
    }
}
