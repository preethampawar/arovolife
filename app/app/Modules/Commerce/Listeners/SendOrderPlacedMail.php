<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Listeners;

use App\Modules\Commerce\Events\OrderPlaced;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Notifications\OrderPlacedNotification;
use App\Modules\Commerce\Support\OrderBuyerNotifier;
use App\Modules\Payments\Support\PaymentSettings;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * On a successful order placement, send the buyer their order-placed email.
 * Always sent — only the per-status-change emails are admin-gated. The
 * payment state is read when the queued job runs, so a buyer who paid in the
 * meantime is told the order is confirmed rather than pending.
 */
final class SendOrderPlacedMail implements ShouldQueue
{
    public function __construct(
        private readonly OrderBuyerNotifier $notifier,
        private readonly PaymentSettings $paymentSettings,
    ) {}

    public function handle(OrderPlaced $event): void
    {
        $order = Order::with('customer')->find($event->orderId);
        if ($order === null) {
            return;
        }

        $paymentState = match (true) {
            $order->paid_at !== null => OrderPlacedNotification::PAYMENT_PAID,
            $order->isOffline() => OrderPlacedNotification::PAYMENT_AWAITING_OFFLINE,
            default => OrderPlacedNotification::PAYMENT_AWAITING_ONLINE,
        };

        $this->notifier->send($order, new OrderPlacedNotification(
            orderNo: $order->order_no,
            buyerName: (string) ($order->ship_name ?: 'there'),
            totalFormatted: $order->displayTotal(),
            paymentState: $paymentState,
            payByLabel: $paymentState === OrderPlacedNotification::PAYMENT_AWAITING_ONLINE
                ? $this->payByLabel($order)
                : null,
        ));
    }

    /** When the unpaid order is cancelled, in IST, e.g. "10:41 am on 21 Nov 2026". */
    private function payByLabel(Order $order): string
    {
        return ($order->placed_at ?? now())
            ->copy()
            ->addMinutes($this->paymentSettings->unpaidExpiryMinutes())
            ->timezone('Asia/Kolkata')
            ->format('g:i a \o\n j M Y');
    }
}
