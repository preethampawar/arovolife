<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Notifications;

use App\Modules\Compensation\Models\AreteCenterApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Outcome of an admin review: approved, rejected or changes requested. */
final class AreteCenterApplicationReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $centreName,
        public readonly string $status,
        public readonly ?string $reason,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = new MailMessage;

        return match ($this->status) {
            AreteCenterApplication::STATUS_APPROVED => $mail
                ->subject('Your Arete Development Centre has been approved')
                ->line('Your application to open the Arete Development Centre "'.$this->centreName.'" has been approved.')
                ->line('The centre is now active in the arovolife registry at Phase 1 and you are recorded as its owner. Distributors registering or updating their profile can now choose it.')
                ->line('Please keep the centre for training, product demonstration and distributor support only — it is not a retail store or outlet.')
                ->action('View your centre', route('my.adc.status')),
            AreteCenterApplication::STATUS_NEEDS_CHANGES => $mail
                ->subject('Your Arete Development Centre application needs changes')
                ->line('The arovolife team reviewed your application for "'.$this->centreName.'" and needs some changes before it can be approved.')
                ->line('What needs to change: '.($this->reason ?? '—'))
                ->action('Update your application', route('my.adc.edit'))
                ->line('Once you have updated the details or documents, resubmit and we will review it again.'),
            default => $mail
                ->subject('Your Arete Development Centre application was not approved')
                ->line('We have reviewed your application for "'.$this->centreName.'" and are unable to approve it at this time.')
                ->line('Reason: '.($this->reason ?? '—'))
                ->line('You may submit a fresh application later if your circumstances change. If you have questions, contact support.'),
        };
    }
}
