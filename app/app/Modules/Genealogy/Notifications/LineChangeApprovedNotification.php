<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class LineChangeApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $requesterAdn,
        public readonly string $newParentAdn,
        public readonly string $side,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sideLabel = $this->side === 'L' ? 'left' : 'right';

        return (new MailMessage)
            ->subject('Your line-change request was approved')
            ->view('emails.line-change-approved', [
                'requesterAdn' => $this->requesterAdn,
                'newParentAdn' => $this->newParentAdn,
                'sideLabel' => $sideLabel,
            ]);
    }
}
