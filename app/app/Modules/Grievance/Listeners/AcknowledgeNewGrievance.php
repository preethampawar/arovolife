<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Listeners;

use App\Modules\Grievance\Enums\TicketEventKind;
use App\Modules\Grievance\Events\GrievanceFiled;
use App\Modules\Grievance\Models\Ticket;
use App\Modules\Grievance\Notifications\GrievanceAcknowledgementNotification;
use App\Modules\Grievance\Services\GrievanceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Issues the acknowledgement the moment a grievance is filed.
 *
 * Policy §2 allows 48 hours; sending immediately is the easy way to never
 * breach that clock, and a complainant holding a complaint number within
 * seconds is materially better served than one waiting two days for it.
 *
 * An anonymous complainant (policy §6.5) gets no acknowledgement — there is
 * nowhere to send it — but the ticket is still marked acknowledged so it does
 * not sit in the breach queue forever for a promise we never owed.
 */
final class AcknowledgeNewGrievance implements ShouldQueue
{
    public function __construct(private readonly GrievanceService $grievances) {}

    public function handle(GrievanceFiled $event): void
    {
        $ticket = Ticket::find($event->ticketId);

        if ($ticket === null) {
            return;
        }

        $email = $ticket->notifiableEmail();

        if ($email !== null) {
            Notification::route('mail', $email)->notify(
                new GrievanceAcknowledgementNotification(
                    ticketNo: $ticket->ticket_no,
                    subject: $ticket->subject,
                    categoryLabel: $ticket->category->label(),
                    firstResponseBy: $ticket->sla_first_response_at->toFormattedDayDateString(),
                    resolutionBy: $ticket->sla_resolution_at->toFormattedDayDateString(),
                )
            );

            $this->grievances->recordEvent(
                $ticket,
                TicketEventKind::Notification,
                note: 'Acknowledgement email sent to the complainant.',
            );
        }

        $this->grievances->acknowledge($ticket);
    }
}
