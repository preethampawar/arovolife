<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Listeners;

use App\Modules\Commerce\Events\OrderStatusChanged;
use App\Modules\Commerce\Models\OfflinePayment;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Notifications\PaymentReceivedNotification;
use App\Modules\Commerce\Support\OrderBuyerNotifier;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Shared\Support\IndianNumber;
use App\Modules\Tax\Models\Invoice;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * When an order becomes paid, send the buyer a payment-received email with a
 * receipt. Always sent — it is not gated by the status-change email setting,
 * and {@see SendOrderStatusChangedMail} skips the paid transition so the buyer
 * gets this one mail, not two.
 */
final class SendPaymentReceivedMail implements ShouldQueue
{
    /** Razorpay `method` values the buyer sees in plain words. */
    private const ONLINE_METHODS = [
        'upi' => 'UPI',
        'card' => 'Card',
        'netbanking' => 'Netbanking',
        'wallet' => 'Wallet',
        'emi' => 'EMI',
        'paylater' => 'Pay later',
    ];

    public function __construct(private readonly OrderBuyerNotifier $notifier) {}

    public function handle(OrderStatusChanged $event): void
    {
        if ($event->newStatus !== Order::STATUS_PAID) {
            return;
        }

        $order = Order::with('customer')->find($event->orderId);
        if ($order === null || $order->paid_at === null) {
            return;
        }

        $this->notifier->send($order, new PaymentReceivedNotification(
            orderNo: $order->order_no,
            buyerName: (string) ($order->ship_name ?: 'there'),
            receiptLines: $this->receiptLines($order),
            invoiceNo: Invoice::where('order_id', $order->id)->latest('id')->value('invoice_no'),
        ));
    }

    /** @return list<array{label: string, value: string}> */
    private function receiptLines(Order $order): array
    {
        [$cashPaise, $method, $reference] = $this->cashPayment($order);
        $nonCashPaise = max(0, $order->total_paise - $cashPaise);

        $lines = [
            ['label' => 'Order number', 'value' => $order->order_no],
            ['label' => 'Payment date', 'value' => $order->paid_at?->timezone('Asia/Kolkata')->format('j M Y, g:i a').' IST'],
            ['label' => 'Order total', 'value' => $this->rupees($order->total_paise)],
        ];

        if ($nonCashPaise > 0) {
            $lines[] = ['label' => 'Paid with points / repurchase credit', 'value' => $this->rupees($nonCashPaise)];
        }

        $lines[] = ['label' => 'Amount paid', 'value' => $this->rupees($cashPaise)];

        if ($cashPaise > 0) {
            $lines[] = ['label' => 'Payment method', 'value' => $method];
            if ($reference !== null) {
                $lines[] = ['label' => 'Payment reference', 'value' => $reference];
            }
        }

        return $lines;
    }

    /**
     * The cash part of the payment and how it was made.
     *
     * @return array{0: int, 1: string, 2: string|null}
     */
    private function cashPayment(Order $order): array
    {
        if ($order->isOffline()) {
            $offline = OfflinePayment::where('order_id', $order->id)->latest('id')->first();

            return $offline === null
                ? [$order->total_paise, 'Offline payment', null]
                : [
                    (int) $offline->amount_paise,
                    OfflinePayment::CHANNELS[$offline->channel] ?? 'Offline payment',
                    $offline->reference_no ?: null,
                ];
        }

        $intent = PaymentIntent::where('order_id', $order->id)
            ->where('status', PaymentIntent::STATUS_CAPTURED)
            ->latest('id')
            ->first();

        if ($intent === null) {
            return [0, 'Points / repurchase credit', null];
        }

        return [
            (int) $intent->amount_paise,
            self::ONLINE_METHODS[(string) $intent->method] ?? 'Online payment',
            $intent->gateway_payment_id ?: null,
        ];
    }

    private function rupees(int $paise): string
    {
        return '₹'.IndianNumber::format($paise / 100, 2);
    }
}
