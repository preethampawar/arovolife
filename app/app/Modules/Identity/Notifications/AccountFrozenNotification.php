<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a distributor their account has been temporarily frozen. Reversible —
 * unlike termination — so the email points them at support for questions and
 * makes clear they cannot sign in until the account is reviewed.
 */
final class AccountFrozenNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $adn,
        public readonly string $fullName,
        public readonly ?string $reason,
        public readonly string $frozenAtFormatted,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your arovolife account has been temporarily frozen')
            ->view('emails.account-frozen', [
                'adn' => $this->adn,
                'fullName' => $this->fullName,
                'reason' => $this->reason,
                'frozenAtFormatted' => $this->frozenAtFormatted,
            ]);
    }
}
