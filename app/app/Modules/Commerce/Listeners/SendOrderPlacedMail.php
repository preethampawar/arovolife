<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Listeners;

use App\Modules\Commerce\Events\OrderPlaced;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Notifications\OrderPlacedNotification;
use App\Modules\Commerce\Support\OrderBuyerNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * On a successful order placement, send the buyer their order-received
 * confirmation. Always sent (this is the "order placed" email that was
 * previously missing) — only the per-status-change emails are admin-gated.
 */
final class SendOrderPlacedMail implements ShouldQueue
{
    public function __construct(private readonly OrderBuyerNotifier $notifier) {}

    public function handle(OrderPlaced $event): void
    {
        $order = Order::with('customer')->find($event->orderId);
        if ($order === null) {
            return;
        }

        $this->notifier->send($order, new OrderPlacedNotification(
            orderNo: $order->order_no,
            buyerName: (string) ($order->ship_name ?: 'there'),
            totalFormatted: $order->displayTotal(),
        ));
    }
}
