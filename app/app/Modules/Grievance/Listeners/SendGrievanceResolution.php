<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Listeners;

use App\Modules\Grievance\Enums\EscalationLevel;
use App\Modules\Grievance\Enums\TicketEventKind;
use App\Modules\Grievance\Events\GrievanceResolved;
use App\Modules\Grievance\Models\Ticket;
use App\Modules\Grievance\Notifications\GrievanceResolvedNotification;
use App\Modules\Grievance\Services\GrievanceService;
use App\Modules\Grievance\Services\GrievanceSettingsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Sends the resolution to the complainant, together with where to go next if
 * they disagree with it (policy §4).
 */
final class SendGrievanceResolution implements ShouldQueue
{
    public function __construct(
        private readonly GrievanceSettingsService $settings,
        private readonly GrievanceService $grievances,
    ) {}

    public function handle(GrievanceResolved $event): void
    {
        $ticket = Ticket::find($event->ticketId);

        if ($ticket === null) {
            return;
        }

        $email = $ticket->notifiableEmail();

        if ($email === null) {
            return;
        }

        // Point them one rung above whoever resolved it — escalating back to
        // the person whose answer you are disputing is not an escalation.
        $next = $ticket->escalation_level->next() ?? EscalationLevel::ComplianceCommittee;

        Notification::route('mail', $email)->notify(
            new GrievanceResolvedNotification(
                ticketNo: $ticket->ticket_no,
                subject: $ticket->subject,
                resolutionNote: (string) $ticket->resolution_note,
                escalationContact: $this->settings->mailboxFor($next),
                escalationLabel: $next->label(),
            )
        );

        $this->grievances->recordEvent(
            $ticket,
            TicketEventKind::Notification,
            note: 'Resolution email sent to the complainant.',
        );
    }
}
