<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Data;

/**
 * What a courier gateway says about a parcel, normalised across gateways.
 *
 * Returned by both `dispatch()` and `track()`. `status` is a `Shipment::STATUS_*`
 * value, already mapped out of whatever vocabulary the courier uses, so nothing
 * downstream has to know a gateway's private status names.
 */
final readonly class CourierShipment
{
    public function __construct(
        /** `Shipment::GATEWAY_*` — which courier this came from. */
        public string $gateway,
        /** `Shipment::STATUS_*` — never the gateway's own wording. */
        public string $status,
        /** The carrier actually moving it: "Delhivery", "BlueDart", whatever the operator typed. */
        public ?string $carrierCode = null,
        public ?string $awbNo = null,
        /** The gateway's own id for this shipment. Null for a manual dispatch — there is no remote record. */
        public ?string $gatewayShipmentId = null,
        public ?string $labelUrl = null,
    ) {}
}
