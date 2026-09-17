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
     * @param  string|null  $route  Gateway name the operator chose. Unavailable or
     *                              unknown routes resolve to manual rather than
     *                              throwing — see CourierGatewayResolver.
     */
    public function dispatch(
        Order $order,
        ?string $route = null,
        ?string $carrierName = null,
        ?string $awbNo = null,
        ?int $actorUserId = null,
        ?string $warehouseCode = null,
    ): Shipment {
        if (! in_array($order->status, [Order::STATUS_PAID, Order::STATUS_READY_TO_SHIP], true)) {
            throw new RuntimeException("Cannot dispatch an order in status {$order->status}.");
        }

        $consignee = $this->consigneeFor($order);
        $gateway = $this->routes->route($route);

        return $this->db->transaction(function () use ($order, $consignee, $gateway, $carrierName, $awbNo, $actorUserId, $warehouseCode): Shipment {
            // The shipment has to exist before a courier can be told about it,
            // and packing is what creates it. markShipped() would also pack,
            // but only after we needed the row.
            if ($order->packed_at === null) {
                $this->fulfilment->pack($order, $warehouseCode, $actorUserId);
                $order->refresh();
            }

            $shipment = Shipment::where('order_id', $order->id)->firstOrFail();

            $result = $gateway->dispatch(
                $shipment,
                new DispatchInstruction($consignee, $carrierName, $awbNo),
                'shipment:'.$shipment->id,
            );

            // markShipped writes the order's own carrier/tracking columns and
            // moves the shipment to dispatched, so the courier's answer is
            // what lands there — never what the operator typed before the
            // gateway had its say.
            $this->orders->markShipped($order, $actorUserId, $result->carrierCode, $result->awbNo);

            $shipment->refresh()->update([
                'gateway' => $result->gateway,
                'gateway_shipment_id' => $result->gatewayShipmentId,
                'label_url' => $result->labelUrl,
                'arete_center_id' => $consignee->areteCenterId,
                'consigned_at' => now(),
            ]);

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
                ],
            ]);

            return $shipment->refresh();
        });
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
