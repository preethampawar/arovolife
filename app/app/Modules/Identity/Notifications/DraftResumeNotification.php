<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

final class DraftResumeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $draftId) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'register.resume',
            now()->addDays(7),
            ['draft' => $this->draftId],
        );

        return (new MailMessage)
            ->subject('Continue your arovolife registration')
            ->greeting('Hello,')
            ->line('You started registering as an arovolife distributor but did not finish.')
            ->line('Click the button below to pick up right where you left off. This link is valid for 7 days.')
            ->action('Continue registration', $url)
            ->line('If you did not start this registration, you can ignore this email.');
    }
}
