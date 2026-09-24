<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Jobs;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Models\ShipmentEvent;
use App\Modules\Fulfilment\Services\ShiprocketGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Applies a stored Shiprocket tracking webhook.
 *
 * The webhook body is only a prompt to look. What the parcel's status IS comes
 * from Shiprocket's tracking API (`ShiprocketGateway::track()`), because the
 * webhook's only authentication is a static token. That matters most for
 * DELIVERED, which opens the buyer's 30-day cooling-off (user decision D2).
 *
 * - DELIVERED, home delivery, order `shipped` → the order is delivered.
 * - DELIVERED, collection order → nothing: the courier delivered to the centre,
 *   and the centre acknowledges receipt itself.
 * - Any RTO stage → the shipment is `returned_to_origin`; the order is left for
 *   staff, who see it in the Action Center.
 * - Always: the courier's own wording lands on `shipments.courier_status`.
 */
final class ProcessShiprocketWebhookJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(private readonly int $eventId)
    {
        $this->onQueue('default');
    }

    public function handle(ShiprocketGateway $gateway, OrderStateMachine $orders, DatabaseManager $db): void
    {
        $event = ShipmentEvent::find($this->eventId);
        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $shipment = $event->shipment_id === null ? null : Shipment::find($event->shipment_id);
        if ($shipment === null) {
            $event->update(['processed_at' => now(), 'processing_error' => 'no matching shipment']);

            return;
        }

        try {
            $answer = $gateway->track($shipment);
        } catch (Throwable $e) {
            $event->update(['processing_error' => mb_substr($e->getMessage(), 0, 500)]);

            throw $e;
        }

        if ($answer === null) {
            $event->update(['processed_at' => now(), 'processing_error' => 'Shiprocket returned no tracking status']);

            return;
        }

        $outcome = $db->transaction(function () use ($shipment, $answer, $orders, $event): string {
            $shipment->update(['courier_status' => $answer->courierStatus]);

            if ($answer->status === Shipment::STATUS_RETURNED) {
                if ($shipment->status !== Shipment::STATUS_DELIVERED) {
                    $shipment->update(['status' => Shipment::STATUS_RETURNED]);
                }

                return 'returning';
            }

            if ($answer->status !== Shipment::STATUS_DELIVERED) {
                return 'in transit';
            }

            // Locked and re-read: an operator may be recording the delivery
            // by hand at this very moment, and only one of us may open the
            // cooling-off window.
            $order = Order::query()->lockForUpdate()->find($shipment->order_id);
            if ($order === null || $order->isCollection() || $order->status !== Order::STATUS_SHIPPED) {
                return 'delivered: no order change';
            }

            $orders->markDelivered($order, null);

            AuditLog::create([
                'actor_id' => null,
                'action' => 'order.delivered_by_courier',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'details' => [
                    'order_no' => $order->order_no,
                    'gateway' => Shipment::GATEWAY_SHIPROCKET,
                    'shipment_event_id' => $event->id,
                    'verified_by' => 'tracking_api',
                ],
            ]);

            return 'delivered';
        });

        $event->update(['processed_at' => now(), 'processing_error' => null]);

        logger()->info('shiprocket webhook applied', ['event_id' => $event->id, 'outcome' => $outcome]);
    }
}
