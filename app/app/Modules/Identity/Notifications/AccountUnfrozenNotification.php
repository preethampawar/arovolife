<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a distributor the freeze on their account has been lifted and they
 * can sign in again.
 */
final class AccountUnfrozenNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $adn,
        public readonly string $fullName,
        public readonly string $unfrozenAtFormatted,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your arovolife account has been reactivated')
            ->view('emails.account-unfrozen', [
                'adn' => $this->adn,
                'fullName' => $this->fullName,
                'unfrozenAtFormatted' => $this->unfrozenAtFormatted,
            ]);
    }
}
