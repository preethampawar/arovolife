<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Confirmation to the applicant that their ADC application was received. */
final class AreteCenterApplicationSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $centreName,
        public readonly bool $resubmitted = false,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->resubmitted
                ? 'Your Arete Development Centre application was resubmitted'
                : 'We received your Arete Development Centre application')
            ->line('Thank you — your application to open the Arete Development Centre "'.$this->centreName.'" has been '.($this->resubmitted ? 'resubmitted' : 'received').'.')
            ->line('The arovolife team will review the premises details and documents you provided and let you know the outcome by email. There is no charge for applying.')
            ->action('View application status', route('my.adc.status'))
            ->line('If you have questions, reply to this email or contact support.');
    }
}
