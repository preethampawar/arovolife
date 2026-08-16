<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the complainant how their grievance was resolved.
 *
 * Always restates the onward escalation route. A complainant who is told a
 * matter is closed without being told where to go next has, in practice, been
 * told nothing — and policy §4 promises them that route.
 */
final class GrievanceResolvedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $ticketNo,
        public readonly string $subject,
        public readonly string $resolutionNote,
        public readonly string $escalationContact,
        public readonly string $escalationLabel,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return GrievanceNotificationChannels::default();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your grievance {$this->ticketNo} has been resolved")
            ->view('emails.grievance-resolved', [
                'ticketNo' => $this->ticketNo,
                'ticketSubject' => $this->subject,
                'resolutionNote' => $this->resolutionNote,
                'escalationContact' => $this->escalationContact,
                'escalationLabel' => $this->escalationLabel,
            ]);
    }
}
