<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirmation email after a distributor invokes cooling-off cancellation.
 * Promised by `compliance/cooling-off.blade.php` ("We send a written
 * confirmation by email") and required by the runbook
 * `docs/runbooks/cooling-off-cancellation.md`.
 */
final class CoolingOffCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $adn,
        public readonly string $cancelledAtFormatted,
        public readonly bool $cascaded,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your arovolife registration has been cancelled')
            ->view('emails.cooling-off-cancelled', [
                'adn' => $this->adn,
                'cancelledAtFormatted' => $this->cancelledAtFormatted,
                'cascaded' => $this->cascaded,
            ]);
    }
}
