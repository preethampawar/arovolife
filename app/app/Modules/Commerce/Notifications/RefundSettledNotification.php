<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Notifications;

use App\Modules\Shared\Support\IndianNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the buyer their refund has been paid — by the gateway to the
 * original payment method, or by finance over NEFT. Sent only once the
 * settlement has committed, and never gated by an admin setting: this is
 * money the buyer is owed, so they are always told. Channel-agnostic via
 * {@see OrderNotificationChannels}: mail plus an in-app notification record.
 */
final class RefundSettledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const METHOD_GATEWAY = 'gateway';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public function __construct(
        public readonly string $orderNo,
        public readonly string $buyerName,
        public readonly int $amountPaise,
        public readonly string $method,
        public readonly ?string $reference,
        public readonly string $settledAt,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return OrderNotificationChannels::withDatabase();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Refund for order {$this->orderNo} has been processed")
            ->view('emails.refund-settled', [
                'orderNo' => $this->orderNo,
                'buyerName' => $this->buyerName,
                'amount' => IndianNumber::rupees($this->amountPaise),
                'methodLabel' => $this->methodLabel(),
                'reference' => $this->reference,
                'settledAt' => $this->settledAt,
                'orderUrl' => url('/orders/'.$this->orderNo),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'order.refund_settled',
            'order_no' => $this->orderNo,
            'amount_paise' => $this->amountPaise,
            'message' => 'Your refund of '.IndianNumber::rupees($this->amountPaise)." for order {$this->orderNo} has been processed.",
            'url' => url('/orders/'.$this->orderNo),
        ];
    }

    private function methodLabel(): string
    {
        return $this->method === self::METHOD_BANK_TRANSFER
            ? 'by bank transfer (NEFT)'
            : 'to your original payment method';
    }
}
