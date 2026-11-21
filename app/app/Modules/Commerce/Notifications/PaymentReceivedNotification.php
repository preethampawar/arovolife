<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the buyer their payment is in and the order is confirmed, with a
 * payment receipt in the mail body. Sent on every order that becomes paid,
 * whatever the status-change email setting, because the order-placed mail
 * told the buyer the order waits on this payment.
 */
final class PaymentReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{label: string, value: string}>  $receiptLines
     */
    public function __construct(
        public readonly string $orderNo,
        public readonly string $buyerName,
        public readonly array $receiptLines,
        public readonly ?string $invoiceNo = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return OrderNotificationChannels::withDatabase();
    }

    public function subject(): string
    {
        return "Payment received for order {$this->orderNo}: your order is confirmed";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->view('emails.payment-received', [
                'subjectLine' => $this->subject(),
                'orderNo' => $this->orderNo,
                'buyerName' => $this->buyerName,
                'receiptLines' => $this->receiptLines,
                'invoiceNo' => $this->invoiceNo,
                'orderUrl' => url('/orders/'.$this->orderNo),
                'receiptUrl' => route('orders.invoice', $this->orderNo),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'order.payment_received',
            'order_no' => $this->orderNo,
            'message' => "Payment received for order {$this->orderNo}. Your order is confirmed.",
            'url' => url('/orders/'.$this->orderNo),
        ];
    }
}
