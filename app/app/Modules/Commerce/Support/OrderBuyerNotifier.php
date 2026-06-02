<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Support;

use App\Modules\Commerce\Models\Order;
use App\Modules\Identity\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifier;

/**
 * Single source of truth for "who receives an order notification". A claimed
 * order goes to the buyer's User (which carries Notifiable + the routing for
 * each channel); a guest order routes on-demand to the customer's email. Either
 * way the same channel-agnostic Notification is delivered.
 */
final class OrderBuyerNotifier
{
    public function send(Order $order, Notification $notification): void
    {
        $customer = $order->customer;
        if ($customer === null) {
            return;
        }

        if ($customer->user_id !== null) {
            $user = User::find($customer->user_id);
            if ($user !== null) {
                Notifier::send($user, $notification);

                return;
            }
        }

        $email = $customer->email_enc;
        if (is_string($email) && $email !== '') {
            Notifier::route('mail', $email)->notify($notification);
        }
    }
}
