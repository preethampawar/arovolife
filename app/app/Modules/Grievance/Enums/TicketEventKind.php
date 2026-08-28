<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Enums;

/**
 * Every entry in a ticket's immutable timeline. The timeline is what the
 * complainant sees (policy §2: "Customer and admin both see status
 * transitions") and what the quarterly internal audit reads.
 */
enum TicketEventKind: string
{
    case StatusChange = 'status_change';
    case Comment = 'comment';
    case Assignment = 'assignment';
    case SlaBreach = 'sla_breach';
    case Acknowledgement = 'acknowledgement';
    case Escalation = 'escalation';
    case Attachment = 'attachment';
    case Notification = 'notification';
    case SlaExtension = 'sla_extension';
    case StatusUpdate = 'status_update';
    case InternalNote = 'internal_note';

    public function label(): string
    {
        return match ($this) {
            self::StatusChange => 'Status changed',
            self::Comment => 'Comment',
            self::Assignment => 'Assigned',
            self::SlaBreach => 'SLA breached',
            self::Acknowledgement => 'Acknowledged',
            self::Escalation => 'Escalated',
            self::Attachment => 'Evidence attached',
            self::Notification => 'Notification sent',
            self::SlaExtension => 'Resolution window extended',
            self::StatusUpdate => 'Status update sent',
            self::InternalNote => 'Internal note',
        };
    }

    /**
     * Internal-only kinds are hidden from the complainant's timeline.
     *
     * Assignment names a staff member and notification rows are plumbing
     * detail; neither helps the complainant and the first leaks who inside
     * the company is handling their file.
     *
     * `InternalNote` exists so an investigator can record findings that name a
     * third party — usually another distributor — without either publishing
     * them to the complainant or keeping the investigation out of the
     * statutory register. Before it, those were the only two options.
     */
    public function isVisibleToComplainant(): bool
    {
        return ! in_array($this, [self::Assignment, self::Notification, self::InternalNote], true);
    }
}
