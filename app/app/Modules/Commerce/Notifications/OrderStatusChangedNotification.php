<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the buyer their order has moved to a new status (paid, shipped,
 * delivered, …). Gated by the admin setting notifications.email_on_status_change
 * at the listener. Channel-agnostic via {@see OrderNotificationChannels}.
 */
final class OrderStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $orderNo,
        public readonly string $buyerName,
        public readonly string $statusLabel,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return OrderNotificationChannels::default();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Order {$this->orderNo} is now {$this->statusLabel}")
            ->view('emails.order-status-changed', [
                'orderNo' => $this->orderNo,
                'buyerName' => $this->buyerName,
                'statusLabel' => $this->statusLabel,
                'orderUrl' => url('/orders/'.$this->orderNo),
            ]);
    }
}
