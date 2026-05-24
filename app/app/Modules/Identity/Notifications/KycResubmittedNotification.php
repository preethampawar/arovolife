<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirmation email after a rejected distributor uploads replacement
 * documents. Tells them their account is back in the review queue.
 */
final class KycResubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $adn,
        public readonly string $fullName,
        /** @var array<int, string> */
        public readonly array $documentTypes,
        public readonly string $resubmittedAtFormatted,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('We received your updated arovolife KYC documents')
            ->view('emails.kyc-resubmitted', [
                'adn' => $this->adn,
                'fullName' => $this->fullName,
                'documentTypes' => $this->documentTypes,
                'resubmittedAtFormatted' => $this->resubmittedAtFormatted,
            ]);
    }
}
