<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Listeners;

use App\Modules\Commerce\Events\OrderPlaced;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Notifications\AdminNewOrderNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Alert the admin/ops mailbox when a new order is placed. The recipient is the
 * `notifications.admin_order_email` setting; a blank/invalid value disables the
 * alert (the gate and the address are the same setting).
 */
final class SendAdminNewOrderMail implements ShouldQueue
{
    public function handle(OrderPlaced $event): void
    {
        $recipient = (string) DB::table('settings')
            ->where('key', 'notifications.admin_order_email')
            ->value('value');

        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return; // not configured → no admin alert
        }

        $order = Order::find($event->orderId);
        if ($order === null) {
            return;
        }

        Notification::route('mail', $recipient)->notify(new AdminNewOrderNotification(
            orderNo: $order->order_no,
            buyerName: (string) ($order->ship_name ?: 'Customer'),
            totalFormatted: $order->displayTotal(),
            adminUrl: url('/admin/commerce/orders/'.$order->id),
        ));
    }
}
