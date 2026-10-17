<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a distributor that a Lifetime Award part they had earned was withdrawn
 * before hand-over, why, and where to object. An adverse decision is never
 * left to be discovered on a page (DSR 2021 Rule 6 — grievance redressal).
 */
final class LifetimeAwardWithdrawnNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $rankName,
        public readonly string $part,
        public readonly string $reason,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A Lifetime Award part has been withdrawn')
            ->line('Part '.$this->part.' of your '.$this->rankName.' Lifetime Award has been withdrawn and will not be handed over.')
            ->line('Reason: '.$this->reason)
            ->line('You can see it under My Income → Awards & Rewards.')
            ->line('If you believe this decision is wrong, you may raise it through the grievance redressal process.')
            ->action('Grievance redressal', route('content.show', 'grievance'));
    }
}
