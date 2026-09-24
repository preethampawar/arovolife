<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Fulfilment\Contracts\CourierGateway;
use App\Modules\Fulfilment\Data\CourierShipment;
use App\Modules\Fulfilment\Data\DispatchInstruction;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Models\ShipmentEvent;
use RuntimeException;

/**
 * The courier that is always available: an operator, a parcel, and a docket
 * they have written a carrier name and an AWB on.
 *
 * This is not a stub. It books nothing and fakes nothing — it records a
 * handover that a human has already performed in the physical world, which is
 * exactly how every arovolife order has shipped since 2026-04. The important
 * difference from `StubGateway` on the payments side: that one marks money
 * received when none was, so it must never quietly take over from the real
 * gateway (R-56, hard rule 2). This one asserts nothing that is not true, so
 * falling back to it is safe.
 *
 * It reports no tracking. The operator is the tracking system: the AWB they
 * typed is the buyer's reference with the carrier directly.
 */
final class ManualCourier implements CourierGateway
{
    /** `shipments.carrier_code` is 32 chars; `awb_no` is 64. */
    public const CARRIER_MAX = 32;

    public const AWB_MAX = 64;

    public function name(): string
    {
        return Shipment::GATEWAY_MANUAL;
    }

    /**
     * Always. A courier integration can be misconfigured, unfunded or down;
     * a person with a parcel cannot be.
     */
    public function permitted(): bool
    {
        return true;
    }

    /** An operator with a parcel can take anything; the carrier name is checked at dispatch. */
    public function preflight(Order $order): void {}

    public function dispatch(Shipment $shipment, DispatchInstruction $instruction, string $idempotencyKey): CourierShipment
    {
        $carrier = trim((string) $instruction->carrierName);
        $awb = trim((string) $instruction->awbNo);

        if ($carrier === '') {
            throw new RuntimeException(
                'A manual dispatch needs the carrier name. Nothing else records who is carrying the parcel.'
            );
        }

        // Truncating here rather than at write time, so what we report back is
        // what was stored. The old markDispatched() truncated silently and the
        // admin screen then showed something the operator had not typed.
        $carrier = mb_substr($carrier, 0, self::CARRIER_MAX);
        $awb = $awb === '' ? null : mb_substr($awb, 0, self::AWB_MAX);

        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'order_id' => $shipment->order_id,
            'gateway' => Shipment::GATEWAY_MANUAL,
            'direction' => ShipmentEvent::DIRECTION_SYSTEM,
            'event_type' => 'manual.dispatch',
            'gateway_event_id' => $idempotencyKey,
            'signature_verified' => false,
            // No address here. A manual dispatch has no remote call to audit,
            // and the consignee is already on the order and the centre record.
            'payload' => ['carrier' => $carrier, 'awb_present' => $awb !== null],
            'created_at' => now(),
        ]);

        return new CourierShipment(
            gateway: Shipment::GATEWAY_MANUAL,
            // Dispatched either way. A collection parcel becomes `at_centre`
            // when the centre acknowledges receiving it, not when it leaves us.
            status: Shipment::STATUS_DISPATCHED,
            carrierCode: $carrier,
            awbNo: $awb,
        );
    }

    /** A manual courier reports nothing. Silence is the honest answer. */
    public function track(Shipment $shipment): ?CourierShipment
    {
        return null;
    }
}
