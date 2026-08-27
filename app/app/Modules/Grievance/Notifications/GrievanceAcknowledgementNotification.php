<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The acknowledgement promised within 48 hours by policy §2, carrying the
 * complaint number required by §6.1 and DSR 2021 Rule 12.
 *
 * Takes scalars rather than the Ticket model so no grievance body — which is
 * PII by default and often sensitive — is serialised onto the queue payload.
 */
final class GrievanceAcknowledgementNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $ticketNo,
        public readonly string $subject,
        public readonly string $categoryLabel,
        public readonly string $firstResponseBy,
        public readonly string $resolutionBy,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return GrievanceNotificationChannels::default();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("We have registered your grievance — {$this->ticketNo}")
            ->view('emails.grievance-acknowledgement', [
                'ticketNo' => $this->ticketNo,
                'ticketSubject' => $this->subject,
                'categoryLabel' => $this->categoryLabel,
                'firstResponseBy' => $this->firstResponseBy,
                'resolutionBy' => $this->resolutionBy,
            ]);
    }
}
