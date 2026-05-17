<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Statutory cooling-off reminder. Email channel works today via Mailpit;
 * SMS is the 'sms' channel name and currently a no-op (logs only) until
 * a vendor is picked per PRD D-05. The channel name is enumerated so the
 * eventual MSG91 driver swaps in cleanly.
 */
final class CoolingOffReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $daysRemaining,
        public readonly string $adn,
        public readonly string $coolingOffEndsAt,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail']; // SMS channel attaches in a follow-up once D-05 lands
    }

    public function toMail(object $notifiable): MailMessage
    {
        $plural = $this->daysRemaining === 1 ? '' : 's';

        return (new MailMessage)
            ->subject("Your arovolife cooling-off period ends in {$this->daysRemaining} day{$plural}")
            ->view('emails.cooling-off-reminder', [
                'daysRemaining' => $this->daysRemaining,
                'adn' => $this->adn,
                'coolingOffEndsAt' => $this->coolingOffEndsAt,
            ]);
    }
}
