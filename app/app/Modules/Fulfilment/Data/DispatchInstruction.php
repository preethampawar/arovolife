<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Data;

/**
 * Everything a courier gateway needs to take a parcel: where it goes, and —
 * for a manual dispatch only — what the operator wrote on the docket.
 *
 * `carrierName` and `awbNo` are nullable because an integrated courier assigns
 * them itself; an operator typing them in is the manual case. A gateway that
 * assigns its own ignores both, and `ManualCourier` is the only implementation
 * that reads them.
 */
final readonly class DispatchInstruction
{
    public function __construct(
        public Consignee $consignee,
        public ?string $carrierName = null,
        public ?string $awbNo = null,
        /** The courier staff chose from the gateway's quotes. Null lets the gateway choose. */
        public ?int $courierId = null,
    ) {}
}
