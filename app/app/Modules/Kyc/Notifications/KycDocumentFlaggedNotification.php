<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Sent when an admin flags a single KYC document for re-upload. The mail
 * channel carries a 14-day signed link to a page that lets the distributor
 * re-upload only this one document. The database channel surfaces the same
 * in the in-app notification bell.
 */
final class KycDocumentFlaggedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $documentId,
        public readonly string $documentType,
        public readonly string $reason,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'kyc.reupload.show',
            now()->addDays(14),
            ['document' => $this->documentId],
        );

        return (new MailMessage)
            ->subject('Action needed: re-upload your ' . $this->humanType())
            ->view('emails.kyc-document-flagged', [
                'documentType' => $this->humanType(),
                'reason' => $this->reason,
                'reuploadUrl' => $url,
                'expiresOn' => now()->addDays(14)->format('d M Y'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'kyc.document_flagged',
            'document_id' => $this->documentId,
            'document_type' => $this->documentType,
            'document_type_human' => $this->humanType(),
            'reason' => $this->reason,
        ];
    }

    private function humanType(): string
    {
        return ucwords(str_replace('_', ' ', $this->documentType));
    }
}
