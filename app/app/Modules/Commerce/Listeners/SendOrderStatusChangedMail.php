<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Listeners;

use App\Modules\Commerce\Events\OrderStatusChanged;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Notifications\OrderStatusChangedNotification;
use App\Modules\Commerce\Support\OrderBuyerNotifier;
use App\Modules\Fulfilment\Models\Shipment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * On every order status transition, email the buyer — but only when the admin
 * has the "email on every order status change" toggle on
 * (notifications.email_on_status_change, default on).
 */
final class SendOrderStatusChangedMail implements ShouldQueue
{
    public function __construct(private readonly OrderBuyerNotifier $notifier) {}

    public function handle(OrderStatusChanged $event): void
    {
        if (! $this->emailOnStatusChangeEnabled()) {
            return;
        }

        $order = Order::with('customer')->find($event->orderId);
        if ($order === null) {
            return;
        }

        // A home delivery that has just shipped also says who carries it and
        // how to follow it. A collection parcel goes to the centre, and the
        // buyer hears from us when it is ready there, not while it travels.
        $shipped = $event->newStatus === Order::STATUS_SHIPPED && ! $order->isCollection();
        $shipment = $shipped ? Shipment::where('order_id', $order->id)->latest('id')->first() : null;

        $this->notifier->send($order, new OrderStatusChangedNotification(
            orderNo: $order->order_no,
            buyerName: (string) ($order->ship_name ?: 'there'),
            statusLabel: Str::headline($event->newStatus),
            carrier: $shipped && $order->ship_carrier !== '' ? $order->ship_carrier : null,
            awbNo: $shipped && $order->ship_tracking_no !== '' ? $order->ship_tracking_no : null,
            trackingUrl: $shipment?->trackingUrl(),
        ));
    }

    private function emailOnStatusChangeEnabled(): bool
    {
        $value = DB::table('settings')->where('key', 'notifications.email_on_status_change')->value('value');

        // Default ON when the setting row is absent (matches the registry default).
        return ($value ?? 'true') === 'true';
    }
}
