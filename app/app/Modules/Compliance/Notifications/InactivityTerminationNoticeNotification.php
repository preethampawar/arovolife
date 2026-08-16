<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The written notice the agreement §21 requires before a dormant account is
 * terminated.
 *
 * It has to say three things plainly: that the account will close, on what
 * date, and exactly what the seller can do to stop it. A notice that buries
 * the remedy is not really a notice.
 *
 * SMS would be the right second channel here and there is no SMS driver yet
 * (PRD D-05), so this is email-only for now.
 */
final class InactivityTerminationNoticeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $adn,
        public readonly ?string $lastSaleAt,
        public readonly string $noticeExpiresAt,
        public readonly int $noticeDays,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Action needed: your arovolife account {$this->adn} will close on {$this->noticeExpiresAt}")
            ->view('emails.inactivity-termination-notice', [
                'adn' => $this->adn,
                'lastSaleAt' => $this->lastSaleAt,
                'noticeExpiresAt' => $this->noticeExpiresAt,
                'noticeDays' => $this->noticeDays,
            ]);
    }
}
