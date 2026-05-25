<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class LineChangeRequestedAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $requestId,
        public readonly string $requesterAdn,
        public readonly string $targetParentAdn,
        public readonly ?string $reason,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Line-change request to review — ADN {$this->requesterAdn}")
            ->view('emails.line-change-requested-admin', [
                'requestId' => $this->requestId,
                'requesterAdn' => $this->requesterAdn,
                'targetParentAdn' => $this->targetParentAdn,
                'reason' => $this->reason,
                'reviewUrl' => url("/admin/line-changes/{$this->requestId}"),
            ]);
    }
}
