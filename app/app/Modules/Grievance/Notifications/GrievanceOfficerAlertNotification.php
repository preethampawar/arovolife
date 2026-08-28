<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Internal alert to the officer who now owns a grievance — on filing, on every
 * escalation up the §4 ladder, and when the complainant replies.
 *
 * Carries the complaint number and category and nothing else the complainant
 * wrote. The subject line was here originally and has been removed: it is
 * complainant free text ("Refund for order ORD-260801-00123"), and these
 * alerts land in shared mailboxes with no audit trail. The officer opens the
 * ticket in the admin console, where the read is logged.
 */
final class GrievanceOfficerAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $ticketNo,
        public readonly string $categoryLabel,
        public readonly string $ownerLabel,
        public readonly string $resolutionBy,
        public readonly string $adminUrl,
        public readonly ?string $reason = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return GrievanceNotificationChannels::default();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $headline = $this->reason === null
            ? "New grievance {$this->ticketNo} needs an owner"
            : "Grievance {$this->ticketNo} escalated to the {$this->ownerLabel}";

        return (new MailMessage)
            ->subject($headline)
            ->view('emails.grievance-officer-alert', [
                'ticketNo' => $this->ticketNo,
                'categoryLabel' => $this->categoryLabel,
                'ownerLabel' => $this->ownerLabel,
                'resolutionBy' => $this->resolutionBy,
                'adminUrl' => $this->adminUrl,
                'reason' => $this->reason,
                'headline' => $headline,
            ]);
    }
}
