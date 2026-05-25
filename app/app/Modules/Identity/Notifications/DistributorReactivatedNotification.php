<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a distributor their distributor record is active again.
 */
final class DistributorReactivatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $adn,
        public readonly string $fullName,
        public readonly string $reactivatedAtFormatted,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your arovolife distributor account has been reactivated')
            ->view('emails.distributor-reactivated', [
                'adn' => $this->adn,
                'fullName' => $this->fullName,
                'reactivatedAtFormatted' => $this->reactivatedAtFormatted,
            ]);
    }
}
