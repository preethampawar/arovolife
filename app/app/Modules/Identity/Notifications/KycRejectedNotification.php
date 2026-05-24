<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Rejection email. Includes the admin's reason verbatim and a link to the
 * re-upload page so the applicant can correct the issue and resubmit.
 */
final class KycRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $adn,
        public readonly string $fullName,
        public readonly string $reason,
        public readonly string $rejectedAtFormatted,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Action needed — your arovolife KYC submission needs updates')
            ->view('emails.kyc-rejected', [
                'adn' => $this->adn,
                'fullName' => $this->fullName,
                'reason' => $this->reason,
                'rejectedAtFormatted' => $this->rejectedAtFormatted,
                'resubmitUrl' => url('/kyc/resubmit'),
            ]);
    }
}
