<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Contracts;

use App\Modules\Commerce\Models\Order;
use App\Modules\Fulfilment\Data\CourierShipment;
use App\Modules\Fulfilment\Data\DispatchInstruction;
use App\Modules\Fulfilment\Models\Shipment;

/**
 * A courier a packed parcel can be handed to.
 *
 * Deliberately narrow, and deliberately not a state machine: neither method
 * moves the order. `DispatchService` owns the order transition, exactly as
 * `PaymentConfirmationService` — not the payment gateway — owns `markPaid()`.
 * A gateway hands over a parcel and reports what the courier says.
 *
 * Unlike `PaymentGateway`, a gateway here may safely be unavailable. There is
 * always `ManualCourier`: an operator with a parcel and a courier receipt. See
 * `CourierGatewayResolver` for why falling back is correct here and is not
 * correct for payments.
 */
interface CourierGateway
{
    /** Stable identifier persisted on `shipments.gateway`. */
    public function name(): string;

    /** Whether this courier can take a parcel right now, in this environment. */
    public function permitted(): bool;

    /**
     * Refuse, before anything is packed or booked, an order this courier
     * cannot take. Packing commits stock, so a refusal after it would leave
     * the order packed for a courier that was never going to accept it.
     *
     * @throws \RuntimeException
     */
    public function preflight(Order $order): void;

    /**
     * Hand the parcel over. Idempotent on `$idempotencyKey`: a double-submitted
     * dispatch form must never book two consignments for one parcel, because
     * the second is a real van arriving at a real address for a parcel that has
     * already gone.
     */
    public function dispatch(Shipment $shipment, DispatchInstruction $instruction, string $idempotencyKey): CourierShipment;

    /**
     * What does the courier say about this parcel now? Null when it has nothing
     * to say — including the ordinary case of a courier that does not report at
     * all.
     */
    public function track(Shipment $shipment): ?CourierShipment;
}
