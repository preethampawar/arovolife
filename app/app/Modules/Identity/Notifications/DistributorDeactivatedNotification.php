<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a distributor their distributor record has been marked inactive.
 * Reversible — the email points them at support to reactivate.
 */
final class DistributorDeactivatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $adn,
        public readonly string $fullName,
        public readonly string $deactivatedAtFormatted,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your arovolife distributor account has been deactivated')
            ->view('emails.distributor-deactivated', [
                'adn' => $this->adn,
                'fullName' => $this->fullName,
                'deactivatedAtFormatted' => $this->deactivatedAtFormatted,
            ]);
    }
}
