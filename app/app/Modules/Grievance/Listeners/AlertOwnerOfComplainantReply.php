<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Listeners;

use App\Modules\Grievance\Events\GrievanceReplyReceived;
use App\Modules\Grievance\Models\Ticket;
use App\Modules\Grievance\Notifications\GrievanceOfficerAlertNotification;
use App\Modules\Grievance\Services\GrievanceSettingsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the owning officer that the complainant has come back.
 *
 * Policy §4 invites complainants to reply to their ticket in order to escalate.
 * Without this, that reply is invisible to everyone except whoever next opens
 * the ticket by chance.
 */
final class AlertOwnerOfComplainantReply implements ShouldQueue
{
    public function __construct(private readonly GrievanceSettingsService $settings) {}

    public function handle(GrievanceReplyReceived $event): void
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
                resolutionBy: $ticket->sla_resolution_at?->toFormattedDayDateString() ?? 'unset',
                adminUrl: route('admin.grievances.show', $ticket->id),
                reason: $event->requestsEscalation
                    ? 'The complainant has asked for this to be escalated, and it has been moved to you.'
                    : 'The complainant has added something to this grievance.',
            )
        );
    }
}
