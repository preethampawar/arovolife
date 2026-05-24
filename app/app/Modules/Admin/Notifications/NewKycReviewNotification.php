<?php

declare(strict_types=1);

namespace App\Modules\Admin\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies the admin compliance team that a new KYC submission is in the
 * review queue. Fired both on initial registration and on re-submission
 * after a rejection — the $isResubmission flag tells the template which
 * copy to use.
 */
final class NewKycReviewNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $adn,
        public readonly string $applicantName,
        public readonly int $distributorId,
        public readonly bool $isResubmission = false,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->isResubmission
            ? "[arovolife] KYC re-submission received — ADN {$this->adn}"
            : "[arovolife] New KYC submission — ADN {$this->adn}";

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.admin.new-kyc-review', [
                'adn' => $this->adn,
                'applicantName' => $this->applicantName,
                'distributorId' => $this->distributorId,
                'isResubmission' => $this->isResubmission,
                'reviewUrl' => url("/admin/kyc/{$this->distributorId}"),
            ]);
    }
}
