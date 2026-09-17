<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the buyer their parcel has reached the Arete Development Centre they
 * chose, and gives them the code that releases it.
 *
 * Deliberately its own notification rather than another
 * `OrderStatusChangedNotification`: that one renders a headline-cased status
 * and nothing else, and "Awaiting Collection" on its own tells a buyer neither
 * where to go nor what to bring.
 *
 * The code goes to the **buyer** and to nobody else. The centre earns a
 * commission on parcels it hands over, so the thing that authorises a handover
 * must not originate with the party being paid for it.
 *
 * Mail only, not `withDatabase()`: the in-app notification record is readable
 * for as long as the account exists, and a collection code is a credential with
 * a job to do and then no reason to persist. It is spent the moment it is used.
 */
final class OrderReadyForCollectionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $orderNo,
        public readonly string $buyerName,
        public readonly string $centreName,
        public readonly string $centreAddress,
        public readonly ?string $centrePhone,
        public readonly string $collectionCode,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return OrderNotificationChannels::default();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Order {$this->orderNo} is ready to collect")
            ->view('emails.order-ready-for-collection', [
                'orderNo' => $this->orderNo,
                'buyerName' => $this->buyerName,
                'centreName' => $this->centreName,
                'centreAddress' => $this->centreAddress,
                'centrePhone' => $this->centrePhone,
                'collectionCode' => $this->collectionCode,
                'orderUrl' => url('/orders/'.$this->orderNo),
            ]);
    }
}
