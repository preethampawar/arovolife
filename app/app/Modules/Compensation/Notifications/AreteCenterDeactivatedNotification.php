<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a centre's owner that their Arete Development Centre was
 * deactivated, why, and where to object. Deactivation stops the ADC bonus,
 * so it is never silent.
 */
final class AreteCenterDeactivatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $centreName,
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
            ->subject('Your Arete Development Centre has been deactivated')
            ->line('The Arete Development Centre "'.$this->centreName.'" has been deactivated in the arovolife registry.')
            ->line('Reason: '.$this->reason)
            ->line('While deactivated the centre is not offered to distributors and does not take part in the ADC bonus calculation. Distributors already connected to it are not moved.')
            ->line('If you believe this decision is wrong, you may raise it through the grievance redressal process.')
            ->action('Grievance redressal', route('content.show', 'grievance'));
    }
}
