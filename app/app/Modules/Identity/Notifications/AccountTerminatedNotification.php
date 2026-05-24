<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Final-state notification for a distributor whose account has been
 * permanently closed. Unlike rejection there is no recovery path — the
 * email points them at support if they believe this is an error.
 */
final class AccountTerminatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $adn,
        public readonly string $fullName,
        public readonly string $reason,
        public readonly string $terminatedAtFormatted,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your arovolife account has been closed')
            ->view('emails.account-terminated', [
                'adn' => $this->adn,
                'fullName' => $this->fullName,
                'reason' => $this->reason,
                'terminatedAtFormatted' => $this->terminatedAtFormatted,
            ]);
    }
}
