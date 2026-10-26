<?php

declare(strict_types=1);

namespace App\Modules\Consent\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Receipt for a distributor's own click on "I have read the changes and
 * agree": what they agreed to and when, so they keep a copy after the plan
 * page changes again (IT Act §4 — a record they can go back to).
 */
final class PlanChangeAcceptedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{label: string, html: string}>  $entries
     */
    public function __construct(
        public readonly ?string $label,
        public readonly string $acceptedAt,
        public readonly array $entries,
        public readonly string $fingerprint = '',
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You agreed to the arovolife Compensation Plan'.($this->label !== null ? ', version '.$this->label : ''))
            ->view('emails.plan-change-accepted', [
                'label' => $this->label,
                'acceptedAt' => $this->acceptedAt,
                'entries' => $this->entries,
                'fingerprint' => $this->fingerprint,
            ]);
    }
}
