<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The 15-day progress update owed while a third-party dependency holds a
 * grievance open past the ordinary 30-day window (policy §2).
 */
final class GrievanceStatusUpdateNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $ticketNo,
        public readonly string $subject,
        public readonly string $updateNote,
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
            ->subject("Progress update on your grievance {$this->ticketNo}")
            ->view('emails.grievance-status-update', [
                'ticketNo' => $this->ticketNo,
                'ticketSubject' => $this->subject,
                'updateNote' => $this->updateNote,
                'resolutionBy' => $this->resolutionBy,
            ]);
    }
}
