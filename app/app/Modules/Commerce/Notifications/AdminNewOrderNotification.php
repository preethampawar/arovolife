<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * New-order alert for the admin/ops mailbox (recipient configured via the
 * `notifications.admin_order_email` setting). Mail-only — distinct from the
 * buyer's confirmation.
 */
final class AdminNewOrderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $orderNo,
        public readonly string $buyerName,
        public readonly string $totalFormatted,
        public readonly string $adminUrl,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New order {$this->orderNo} — {$this->totalFormatted}")
            ->greeting('New order received')
            ->line("Order: {$this->orderNo}")
            ->line("Customer: {$this->buyerName}")
            ->line("Total: {$this->totalFormatted}")
            ->line('Payment: Online')
            ->action('View order in admin', $this->adminUrl)
            ->line('Review and fulfil it from the admin Orders screen.');
    }
}
