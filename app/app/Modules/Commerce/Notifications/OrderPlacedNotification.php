<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirms to the buyer that their order was placed successfully. Channel-
 * agnostic via {@see OrderNotificationChannels} — mail now, SMS when the
 * gateway lands.
 */
final class OrderPlacedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $orderNo,
        public readonly string $buyerName,
        public readonly string $totalFormatted,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return OrderNotificationChannels::default();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Order {$this->orderNo} received")
            ->view('emails.order-placed', [
                'orderNo' => $this->orderNo,
                'buyerName' => $this->buyerName,
                'totalFormatted' => $this->totalFormatted,
                'orderUrl' => url('/orders/'.$this->orderNo),
            ]);
    }
}
