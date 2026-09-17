<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Data;

use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Models\AreteCenter;
use RuntimeException;

/**
 * Where a parcel is actually going, and who signs for it.
 *
 * This is the one place that knows a collection order is consigned to the
 * centre rather than to the buyer, and it is deliberately built from the
 * centre record at dispatch time rather than from `orders.ship_*`. Copying the
 * centre's address into the order was R-47: it made the confirmation page and
 * the invoice render an address the buyer never gave, under the heading
 * "Shipping to", beside the buyer's own name.
 *
 * The buyer's name and phone travel with a collection consignment because the
 * centre has to know who may take the parcel. Nothing else about the buyer
 * does.
 */
final readonly class Consignee
{
    public function __construct(
        public string $name,
        public ?string $phone,
        public string $line1,
        public ?string $line2,
        public string $city,
        public string $state,
        public string $pincode,
        /** Set only when the parcel is consigned to a centre rather than a home address. */
        public ?int $areteCenterId = null,
        /** The buyer who will collect, shown to the centre so it knows who to hand over to. */
        public ?string $collectorName = null,
        public ?string $collectorPhone = null,
    ) {}

    /** A home delivery: the address the buyer gave at checkout. */
    public static function forHomeDelivery(Order $order): self
    {
        if ($order->ship_line1 === null || $order->ship_pincode === null) {
            throw new RuntimeException(
                "Order {$order->order_no} has no delivery address. A home delivery cannot be consigned without one."
            );
        }

        return new self(
            name: (string) $order->ship_name,
            phone: $order->ship_phone_e164,
            line1: (string) $order->ship_line1,
            line2: $order->ship_line2,
            city: (string) $order->ship_city,
            state: (string) $order->ship_state,
            pincode: (string) $order->ship_pincode,
        );
    }

    /**
     * A collection: the parcel goes to the centre, addressed to the centre,
     * carrying the buyer's name so the centre knows who is entitled to it.
     */
    public static function forCollectionAt(AreteCenter $centre, Order $order): self
    {
        if ($centre->pincode === null || $centre->pincode === '') {
            throw new RuntimeException(
                "Arete centre {$centre->id} has no pincode. A parcel cannot be consigned to it until the registry record is complete."
            );
        }

        return new self(
            name: $centre->name,
            phone: $centre->contact_number,
            line1: (string) ($centre->address_line_1 ?? $centre->displayLocation()),
            line2: $centre->address_line_2,
            city: (string) $centre->city,
            state: (string) $centre->state,
            pincode: (string) $centre->pincode,
            areteCenterId: $centre->id,
            collectorName: $order->ship_name ?? $order->customer?->display_name,
            collectorPhone: $order->ship_phone_e164,
        );
    }

    public function isCollection(): bool
    {
        return $this->areteCenterId !== null;
    }
}
