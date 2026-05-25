<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class LineChangeRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $requesterAdn,
        public readonly string $decisionNote,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Update on your line-change request')
            ->view('emails.line-change-rejected', [
                'requesterAdn' => $this->requesterAdn,
                'decisionNote' => $this->decisionNote,
            ]);
    }
}
