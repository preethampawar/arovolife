<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcome notice after KYC approval — the account is now active.
 *
 * Carried on both channels, like the document-flag notice. Mail can fail
 * silently at the transport (it did on staging), and an approval a
 * distributor never hears about is an account they don't know they can use;
 * the database copy survives a bad SMTP night.
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
        return ['mail', 'database'];
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

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'kyc.approved',
            'adn' => $this->adn,
            'approved_at' => $this->approvedAtFormatted,
        ];
    }
}
