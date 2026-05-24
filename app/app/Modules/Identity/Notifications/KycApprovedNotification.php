<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcome email after KYC approval — account is now active.
 */
final class KycApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $adn,
        public readonly string $fullName,
        public readonly string $approvedAtFormatted,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome — your arovolife account is now active')
            ->view('emails.kyc-approved', [
                'adn' => $this->adn,
                'fullName' => $this->fullName,
                'approvedAtFormatted' => $this->approvedAtFormatted,
                'dashboardUrl' => url('/dashboard'),
            ]);
    }
}
