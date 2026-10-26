<?php

declare(strict_types=1);

namespace App\Modules\Consent\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The written notice of a compensation-plan change (plan page §12), emailed to
 * a distributor who has not agreed to the published text three days after the
 * notice started. Not queued: `plan:notify-unread` sends it with notifyNow()
 * so the notice row records an actual send, not a queued job.
 */
final class PlanChangeNoticeNotification extends Notification
{
    /**
     * @param  list<array{label: string, html: string}>  $entries
     */
    public function __construct(
        public readonly string $adn,
        public readonly string $name,
        public readonly ?string $label,
        public readonly array $entries,
        public readonly ?string $coolingOffEndsAt,
        public readonly bool $canConfirmOnline = true,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Changes to the arovolife Compensation Plan — please read')
            ->view('emails.plan-change-notice', [
                'adn' => $this->adn,
                'name' => $this->name,
                'label' => $this->label,
                'entries' => $this->entries,
                'coolingOffEndsAt' => $this->coolingOffEndsAt,
                'canConfirmOnline' => $this->canConfirmOnline,
            ]);
    }
}
