<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Fulfilment\Models\Shipment;
use Illuminate\Database\DatabaseManager;

/**
 * Reads a Shiprocket parcel's status from the tracking API and applies it.
 *
 * The single place a courier status moves an order, shared by the tracking
 * webhook job and the staff "Check courier status" button. What the parcel's
 * status IS always comes from `ShiprocketGateway::track()`, never from a
 * webhook body, because DELIVERED opens the buyer's 30-day cooling-off.
 *
 * - DELIVERED, home delivery, order `shipped` → the order is delivered.
 * - DELIVERED, collection order → nothing: the courier delivered to the centre,
 *   and the centre acknowledges receipt itself.
 * - Any RTO stage → the shipment is `returned_to_origin`; the order is left for
 *   staff, who see it in the Action Center.
 * - Always: the courier's own wording lands on `shipments.courier_status`.
 */
final class CourierTrackingSync
{
    public const OUTCOME_NO_STATUS = 'no status';

    public const OUTCOME_IN_TRANSIT = 'in transit';

    public const OUTCOME_RETURNING = 'returning';

    public const OUTCOME_DELIVERED = 'delivered';

    public const OUTCOME_DELIVERED_NO_CHANGE = 'delivered: no order change';

    public function __construct(
        private readonly ShiprocketGateway $gateway,
        private readonly OrderStateMachine $orders,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, mixed>  $trigger  merged into the delivery audit row
     *                                         (e.g. the webhook event id, or `staff_check`)
     *
     * @throws \Throwable when the tracking API cannot be reached; nothing is changed
     */
    public function sync(Shipment $shipment, ?int $actorId, array $trigger): string
    {
        $answer = $this->gateway->track($shipment);
        if ($answer === null) {
            return self::OUTCOME_NO_STATUS;
        }

        return $this->db->transaction(function () use ($shipment, $answer, $actorId, $trigger): string {
            // A delivery locks the order before touching the shipment, the same
            // order as a hand-recorded delivery, so the two never deadlock.
            // Locked and re-read: only one of us may open the cooling-off window.
            $order = $answer->status === Shipment::STATUS_DELIVERED
                ? Order::query()->lockForUpdate()->find($shipment->order_id)
                : null;

            $shipment->update(['courier_status' => $answer->courierStatus]);

            if ($answer->status === Shipment::STATUS_RETURNED) {
                if ($shipment->status !== Shipment::STATUS_DELIVERED) {
                    $shipment->update(['status' => Shipment::STATUS_RETURNED]);
                }

                return self::OUTCOME_RETURNING;
            }

            if ($answer->status !== Shipment::STATUS_DELIVERED) {
                return self::OUTCOME_IN_TRANSIT;
            }

            if ($order === null || $order->isCollection() || $order->status !== Order::STATUS_SHIPPED) {
                return self::OUTCOME_DELIVERED_NO_CHANGE;
            }

            $this->orders->markDelivered($order, $actorId);

            AuditLog::create([
                'actor_id' => $actorId,
                'action' => 'order.delivered_by_courier',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'details' => [
                    'order_no' => $order->order_no,
                    'gateway' => Shipment::GATEWAY_SHIPROCKET,
                    'verified_by' => 'tracking_api',
                ] + $trigger,
            ]);

            return self::OUTCOME_DELIVERED;
        });
    }
}
