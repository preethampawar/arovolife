<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Acknowledgement to the distributor that their request was received. */
final class DistributorRequestSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $requestNo,
        public readonly string $typeLabel,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your request '.$this->requestNo.' has been received')
            ->line('We have received your request: '.$this->typeLabel.'.')
            ->line('Your request number is '.$this->requestNo.'. The arovolife team will review the details and documents and email you the outcome.')
            ->action('Track your request', route('my.requests.index'))
            ->line('Nothing on your record changes until the request is approved.');
    }
}
