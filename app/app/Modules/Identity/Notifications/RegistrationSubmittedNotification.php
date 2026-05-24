<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcome / awaiting-review email sent to a new distributor right after they
 * finish the wizard at step 10. Their KYC is in the queue; this message
 * confirms receipt and explains the next step.
 */
final class RegistrationSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $adn,
        public readonly string $fullName,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to arovolife — your registration is being reviewed')
            ->view('emails.registration-submitted', [
                'adn' => $this->adn,
                'fullName' => $this->fullName,
            ]);
    }
}
