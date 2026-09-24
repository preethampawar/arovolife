<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Compensation\Models\AreteCenterDeclaration;
use App\Modules\Compensation\Support\AreteCenterDeclarations;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Fulfilment\Data\Consignee;
use App\Modules\Fulfilment\Data\DispatchInstruction;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Inventory\Services\OrderFulfilmentService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Hands a packed order to a courier and moves the order to `shipped`.
 *
 * The division of labour matches Payments: a gateway talks to the outside
 * world and reports what it was told; this service owns the state change, the
 * way `PaymentConfirmationService` — not the gateway — owns `markPaid()`.
 *
 * A collection order is consigned to the Arete centre the buyer chose, not to
 * the buyer. That is the whole difference between the two journeys; everything
 * else about them is the same, which is why one service covers both.
 */
final class DispatchService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CourierGatewayResolver $routes,
        private readonly OrderFulfilmentService $fulfilment,
        private readonly OrderStateMachine $orders,
    ) {}

    /**
     * The remote booking runs **outside** any database transaction, in three
     * steps: pack (its own transaction), hand the parcel to the courier, then
     * one transaction for the order change, the shipment row and the audit.
     * Inside a single transaction, a courier failure would roll back the
     * `shipment_events` rows that are the only evidence of what was asked, and
     * a local failure after a successful booking would erase our record of a
     * consignment that really exists. A gateway persists its own progress as
     * it goes, so a retry resumes rather than booking twice.
     *
     * @param  string|null  $route  Gateway name the operator chose. Null means
     *                              manual. A named route that is not available is
     *                              refused, never silently swapped for manual.
     * @param  bool  $confirmedRemoteCancelled  The operator has cancelled an earlier
     *                                          courier booking for this parcel in the
     *                                          courier's own panel and is now
     *                                          dispatching by another route — or,
     *                                          re-dispatching through the same
     *                                          courier, has checked its panel and
     *                                          an unanswered booking is not there.
     * @return Shipment|null null only when a manual dispatch shipped an order
     *                       the lenient pack left unpacked (no shipment row)
     */
    public function dispatch(
        Order $order,
        ?string $route = null,
        ?string $carrierName = null,
        ?string $awbNo = null,
        ?int $actorUserId = null,
        ?string $warehouseCode = null,
        bool $confirmedRemoteCancelled = false,
    ): ?Shipment {
        if (! in_array($order->status, [Order::STATUS_PAID, Order::STATUS_READY_TO_SHIP], true)) {
            throw new RuntimeException("Cannot dispatch an order in status {$order->status}.");
        }

        $consignee = $this->consigneeFor($order);
        $gateway = $this->routes->route($route);

        if ($route !== null && $gateway->name() !== $route) {
            // Handing the parcel to manual here would leave the operator
            // believing a courier had been booked when none was.
            throw new RuntimeException(
                "The {$route} route is not available right now, so this order was not dispatched. "
                .'Choose another route.'
            );
        }

        $lock = Cache::lock('fulfilment:dispatch:order:'.$order->id, 300);
        if (! $lock->get()) {
            throw new RuntimeException("Order {$order->order_no} is already being dispatched. Refresh in a moment.");
        }

        try {
            // Before packing: packing commits stock, and a courier refusal
            // after it would leave the order packed for nothing.
            $gateway->preflight($order);

            $manual = $gateway->name() === Shipment::GATEWAY_MANUAL;

            if ($order->packed_at === null) {
                if ($manual) {
                    // Today's one-click ship: while availability is not
                    // enforced, missing stock records never stop a parcel an
                    // operator is holding. It may leave no shipment row.
                    $this->fulfilment->packForShipment($order, $actorUserId);
                } else {
                    // A courier books a real parcel, so it must really be
                    // packed. pack() opens its own transaction and locks the order.
                    $this->fulfilment->pack($order, $warehouseCode, $actorUserId);
                }
                $order->refresh();
            }

            $shipment = Shipment::where('order_id', $order->id)->first();

            if ($shipment === null) {
                if (! $manual) {
                    throw new RuntimeException("Order {$order->order_no} has no parcel to hand to {$gateway->name()}. Pack it first.");
                }

                return $this->shipUnpacked($order, $consignee, $carrierName, $awbNo, $actorUserId);
            }

            $abandonedBooking = $this->guardAbandonedBooking($shipment, $gateway->name(), $confirmedRemoteCancelled);
            $releasedUnansweredClaim = $this->releaseUnansweredClaim($shipment, $gateway->name(), $confirmedRemoteCancelled);

            $result = $gateway->dispatch(
                $shipment,
                new DispatchInstruction($consignee, $carrierName, $awbNo),
                'shipment:'.$shipment->id,
            );

            return $this->db->transaction(function () use ($order, $shipment, $consignee, $result, $actorUserId, $abandonedBooking, $releasedUnansweredClaim): Shipment {
                // The courier call took time; the order may have been
                // cancelled meanwhile. markShipped() checks the in-memory
                // status, so read the locked row into the model first.
                $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
                $order->setRawAttributes($locked->getAttributes(), true);

                if (! in_array($order->status, [Order::STATUS_PAID, Order::STATUS_READY_TO_SHIP], true)) {
                    throw new RuntimeException(
                        "Order {$order->order_no} changed to {$order->status} while the courier was being booked. "
                        .($result->gatewayShipmentId !== null
                            ? "Cancel {$result->gateway} shipment {$result->gatewayShipmentId} in the courier's panel."
                            : 'Nothing was booked.')
                    );
                }

                // markShipped writes the order's own carrier/tracking columns and
                // moves the shipment to dispatched, so the courier's answer is
                // what lands there — never what the operator typed before the
                // gateway had its say.
                $this->orders->markShipped($order, $actorUserId, $result->carrierCode, $result->awbNo);

                $shipment->refresh();
                $update = [
                    'label_url' => $result->labelUrl,
                    'arete_center_id' => $consignee->areteCenterId,
                    'consigned_at' => now(),
                ];
                if ($abandonedBooking === null) {
                    $update['gateway'] = $result->gateway;
                    $update['gateway_shipment_id'] = $result->gatewayShipmentId;
                }
                // Otherwise the row keeps the cancelled booking's gateway and
                // id: they are the only link to a consignment that existed,
                // and the carrier and AWB actually used are on the row already.
                $shipment->update($update);

                AuditLog::create([
                    'actor_id' => $actorUserId,
                    'action' => 'order.dispatched',
                    'subject_type' => 'order',
                    'subject_id' => $order->id,
                    'details' => [
                        'order_no' => $order->order_no,
                        'gateway' => $result->gateway,
                        'carrier' => $result->carrierCode,
                        'awb_present' => $result->awbNo !== null,
                        // Which journey this was, so a later reader does not have to
                        // infer it from a centre id that may since have been nulled.
                        'consigned_to' => $consignee->isCollection() ? 'arete_centre' : 'buyer',
                        'arete_center_id' => $consignee->areteCenterId,
                        'remote_booking_cancelled_by_operator' => $abandonedBooking,
                        'unanswered_booking_confirmed_absent_by_operator' => $releasedUnansweredClaim,
                    ],
                ]);

                return $shipment->refresh();
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * The manual ship of an order the lenient pack left unpacked (stock not
     * recorded). There is no shipment row to hand a courier, so this is
     * exactly today's ship, plus the carrier rule and the dispatch audit.
     */
    private function shipUnpacked(Order $order, Consignee $consignee, ?string $carrierName, ?string $awbNo, ?int $actorUserId): null
    {
        $carrier = trim((string) $carrierName);
        if ($carrier === '') {
            throw new RuntimeException(
                'A manual dispatch needs the carrier name. Nothing else records who is carrying the parcel.'
            );
        }
        $carrier = mb_substr($carrier, 0, ManualCourier::CARRIER_MAX);
        $awb = trim((string) $awbNo);
        $awb = $awb === '' ? null : mb_substr($awb, 0, ManualCourier::AWB_MAX);

        $this->db->transaction(function () use ($order, $consignee, $carrier, $awb, $actorUserId): void {
            $this->orders->markShipped($order, $actorUserId, $carrier, $awb);

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'order.dispatched',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'details' => [
                    'order_no' => $order->order_no,
                    'gateway' => Shipment::GATEWAY_MANUAL,
                    'carrier' => $carrier,
                    'awb_present' => $awb !== null,
                    'consigned_to' => $consignee->isCollection() ? 'arete_centre' : 'buyer',
                    'arete_center_id' => $consignee->areteCenterId,
                    'unpacked' => true,
                ],
            ]);
        });

        return null;
    }

    /**
     * A parcel already booked with a courier must not also be sent another
     * way until that booking is cancelled: the booked courier still arrives,
     * and on the live account it is still charged. The operator cancels it in
     * the courier's panel and says so; we record that they did.
     *
     * A shipment claimed for a courier with no shipment id yet is treated the
     * same way: that is a booking request that got no reply, and the courier
     * may have booked it anyway.
     *
     * @return array{gateway: string, gateway_shipment_id: string|null}|null the booking being abandoned
     */
    private function guardAbandonedBooking(Shipment $shipment, string $route, bool $confirmedRemoteCancelled): ?array
    {
        if ($shipment->gateway === Shipment::GATEWAY_MANUAL || $shipment->gateway === $route) {
            return null;
        }

        if (! $confirmedRemoteCancelled) {
            $which = $shipment->gateway_shipment_id !== null
                ? "is already booked with {$shipment->gateway} (shipment {$shipment->gateway_shipment_id})"
                : "may already be booked with {$shipment->gateway} (an earlier booking request got no reply; search for order {$shipment->order?->order_no})";

            throw new RuntimeException(
                "This parcel {$which}. Cancel that booking in the {$shipment->gateway} panel first, "
                .'then confirm you have cancelled it to dispatch another way.'
            );
        }

        return ['gateway' => $shipment->gateway, 'gateway_shipment_id' => $shipment->gateway_shipment_id];
    }

    /**
     * Re-dispatching through the same courier after a booking request that got
     * no reply: the gateway refuses to create again until the operator has
     * checked the courier's panel. Once they confirm it is not there, drop the
     * claim so the gateway books afresh.
     */
    private function releaseUnansweredClaim(Shipment $shipment, string $route, bool $confirmedRemoteCancelled): bool
    {
        if (! $confirmedRemoteCancelled
            || $shipment->gateway === Shipment::GATEWAY_MANUAL
            || $shipment->gateway !== $route
            || $shipment->gateway_shipment_id !== null) {
            return false;
        }

        $released = Shipment::whereKey($shipment->id)
            ->whereNull('gateway_shipment_id')
            ->update(['gateway' => Shipment::GATEWAY_MANUAL]) === 1;
        $shipment->refresh();

        return $released;
    }

    private function consigneeFor(Order $order): Consignee
    {
        if (! $order->isCollection()) {
            return Consignee::forHomeDelivery($order);
        }

        $centre = $order->areteCenter;

        if ($centre === null) {
            // The centre was deleted after the buyer chose it. `delivery_type`
            // survived that (which is why it exists), so we know this was never
            // meant to be a home delivery and must not silently become one —
            // there is no address to send it to.
            throw new RuntimeException(
                "Order {$order->order_no} was placed for collection but its centre no longer exists. "
                .'Contact the buyer to agree a delivery address or a different centre before dispatching.'
            );
        }

        // R-21/R-95: a centre may not receive a consignment until its owner has
        // accepted the declarations at the version currently in force.
        //
        // This is not paperwork. The declaration is the company's evidence that
        // a centre is not an e-commerce fulfilment point (DSA §5.2, hard rule
        // 7), and routing parcels to an operator who has undertaken not to
        // receive them would be the company inducing breach of its own
        // undertaking — worse for the Rule 4 defence than having no declaration
        // at all. Admin-created centres have no declaration in any version,
        // because `AdminAreteCenterController::store()` creates them with no
        // application; they fail here until their assigned distributor accepts.
        if (! AreteCenterDeclaration::currentVersionAcceptedBy($centre->id)) {
            $outstanding = implode(', ', AreteCenterDeclaration::outstandingFor($centre->id));

            throw new RuntimeException(
                "Centre \"{$centre->name}\" has not accepted the current centre declarations ("
                .AreteCenterDeclarations::VERSION.'), so a parcel cannot be consigned to it. '
                ."Outstanding: {$outstanding}."
            );
        }

        return Consignee::forCollectionAt($centre, $order);
    }
}
