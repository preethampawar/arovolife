<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the buyer their order was placed and, just as plainly, whether it is
 * paid. An online order is placed before the buyer pays, so the mail must not
 * read as a confirmation until the payment is in. Channel-agnostic via
 * {@see OrderNotificationChannels} — mail now, SMS when the gateway lands.
 */
final class OrderPlacedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Payment received: the order is confirmed. */
    public const PAYMENT_PAID = 'paid';

    /** Online order not yet paid at the gateway. */
    public const PAYMENT_AWAITING_ONLINE = 'awaiting_online';

    /** Offline order waiting for staff to record the payment. */
    public const PAYMENT_AWAITING_OFFLINE = 'awaiting_offline';

    public function __construct(
        public readonly string $orderNo,
        public readonly string $buyerName,
        public readonly string $totalFormatted,
        public readonly string $paymentState = self::PAYMENT_AWAITING_ONLINE,
        public readonly ?string $payByLabel = null,
    ) {}

    public function subject(): string
    {
        return $this->paymentState === self::PAYMENT_PAID
            ? "Order {$this->orderNo} confirmed: payment received"
            : "Order {$this->orderNo} placed: payment pending";
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return OrderNotificationChannels::default();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->view('emails.order-placed', [
                'subjectLine' => $this->subject(),
                'orderNo' => $this->orderNo,
                'buyerName' => $this->buyerName,
                'totalFormatted' => $this->totalFormatted,
                'paymentState' => $this->paymentState,
                'payByLabel' => $this->payByLabel,
                'orderUrl' => url('/orders/'.$this->orderNo),
                'payUrl' => route('shop.pay', $this->orderNo),
            ]);
    }
}
