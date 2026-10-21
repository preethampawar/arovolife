<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The welcome letter on its own, for distributors an admin registers from
 * /admin/distributors/create (sent only when the admin leaves the
 * "send the welcome letter" box ticked). Self-registered distributors get
 * the same letter inside {@see RegistrationSubmittedNotification}.
 */
final class WelcomeLetterNotification extends Notification implements ShouldQueue
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
            ->subject('Welcome to the arovolife family')
            ->view('emails.welcome-letter', [
                'adn' => $this->adn,
                'fullName' => $this->fullName,
            ]);
    }
}
