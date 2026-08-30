<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Modules\Identity\Models\DistributorRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Outcome of the review: approved (applied or acknowledged) or rejected. */
final class DistributorRequestDecidedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $requestNo,
        public readonly string $typeLabel,
        public readonly string $status,
        public readonly ?string $note,
        public readonly bool $applied,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->action('View your requests', route('my.requests.index'));

        if ($this->status === DistributorRequest::STATUS_APPROVED) {
            $mail->subject('Your request '.$this->requestNo.' has been approved')
                ->line('Your request ('.$this->typeLabel.') has been approved.');

            if ($this->applied) {
                $mail->line('Your record has been updated. Please check your profile and contact support if anything looks wrong.');
            } else {
                $mail->line('The arovolife compliance team will now carry it out and will contact you about the next steps.');
            }
        } else {
            $mail->subject('Your request '.$this->requestNo.' was not approved')
                ->line('We have reviewed your request ('.$this->typeLabel.') and are unable to approve it.');
        }

        if (filled($this->note)) {
            $mail->line('Note from arovolife: '.$this->note);
        }

        return $mail;
    }
}
