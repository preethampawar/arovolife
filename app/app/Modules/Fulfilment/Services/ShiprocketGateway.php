<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Fulfilment\Contracts\CourierGateway;
use App\Modules\Fulfilment\Data\Consignee;
use App\Modules\Fulfilment\Data\CourierShipment;
use App\Modules\Fulfilment\Data\DispatchInstruction;
use App\Modules\Fulfilment\Data\ParcelGap;
use App\Modules\Fulfilment\Exceptions\MissingParcelDetailsException;
use App\Modules\Fulfilment\Exceptions\ShiprocketApiException;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Support\FulfilmentSettings;
use RuntimeException;

/**
 * Books a consignment with Shiprocket: an order, then an AWB (which picks the
 * courier), then — best effort — a pickup request and a label.
 *
 * **Resumable, and never books twice for one shipment.** The remote calls run
 * outside any database transaction (see `DispatchService`), and Shiprocket's
 * shipment id is written to the shipment the moment Shiprocket returns it. A
 * retry after any failure therefore picks up where the last attempt stopped
 * instead of creating a second consignment. Before the create, the shipment is
 * claimed with a conditional update so two operators cannot both reach it.
 *
 * Like every gateway here, this moves no order state. `DispatchService` does.
 */
final class ShiprocketGateway implements CourierGateway
{
    /** `shipments.carrier_code` is 32 chars; `awb_no` is 64. */
    private const CARRIER_MAX = 32;

    private const AWB_MAX = 64;

    /** Shiprocket's floor for weight (kg) and each dimension (cm). */
    private const MIN_MEASURE = 0.5;

    public function __construct(
        private readonly ShiprocketClient $client,
        private readonly FulfilmentSettings $settings,
    ) {}

    public function name(): string
    {
        return Shipment::GATEWAY_SHIPROCKET;
    }

    /**
     * Technically usable: credentials present, the right host for this
     * environment, and a pickup location to book from. Whether the business
     * has switched it on (flag + setting) is `CourierGatewayResolver`'s call.
     */
    public function permitted(): bool
    {
        return $this->client->configured()
            && $this->client->hostMatchesEnvironment()
            && $this->settings->shiprocketPickupLocation() !== '';
    }

    /** The parcel-detail and pickup refusals, before `DispatchService` packs. */
    public function preflight(Order $order): void
    {
        $gaps = $this->parcelGaps($order);
        if ($gaps !== []) {
            throw new MissingParcelDetailsException($gaps);
        }

        if ($this->settings->shiprocketPickupLocation() === '') {
            throw new RuntimeException('No Shiprocket pickup location is set. Set it under Settings → Fulfilment.');
        }
    }

    /**
     * Every line whose product lacks a weight or a packed size. Empty means
     * the order can be booked. The dispatch screen shows these to the operator
     * with a link to each product.
     *
     * @return list<ParcelGap>
     */
    public function parcelGaps(Order $order): array
    {
        $order->loadMissing('items.variant');

        $gaps = [];
        /** @var OrderItem $item */
        foreach ($order->items as $item) {
            $variant = $item->variant;

            if ($variant === null) {
                $gaps[] = new ParcelGap(null, (string) $item->product_name_snapshot,
                    (string) $item->variant_sku_snapshot, [ParcelGap::VARIANT]);

                continue;
            }

            $missing = [];
            if ((int) $variant->weight_g <= 0) {
                $missing[] = ParcelGap::WEIGHT;
            }
            foreach (['length_mm' => ParcelGap::LENGTH, 'breadth_mm' => ParcelGap::BREADTH, 'height_mm' => ParcelGap::HEIGHT] as $column => $label) {
                if ((int) ($variant->{$column} ?? 0) <= 0) {
                    $missing[] = $label;
                }
            }

            if ($missing !== []) {
                $gaps[] = new ParcelGap($variant->id, (string) $item->product_name_snapshot,
                    (string) $item->variant_sku_snapshot, $missing, (int) $variant->product_id);
            }
        }

        return $gaps;
    }

    public function dispatch(Shipment $shipment, DispatchInstruction $instruction, string $idempotencyKey): CourierShipment
    {
        $order = $shipment->order()->firstOrFail();

        // Everything that can be refused locally is refused before the first
        // remote call, so a refusal never leaves half a booking behind.
        $gaps = $this->parcelGaps($order);
        if ($gaps !== []) {
            throw new MissingParcelDetailsException($gaps);
        }
        $phone = $this->tenDigitMobile($instruction->consignee->phone);
        $pickup = $this->settings->shiprocketPickupLocation();
        if ($pickup === '') {
            throw new RuntimeException('No Shiprocket pickup location is set. Set it under Settings → Fulfilment.');
        }

        $shipment->refresh();
        $resumed = $shipment->gateway === Shipment::GATEWAY_SHIPROCKET && $shipment->gateway_shipment_id !== null;
        if ($resumed) {
            $remoteId = (string) $shipment->gateway_shipment_id;
        } else {
            [$remoteId, $resumed] = $this->book($shipment, $order, $instruction->consignee, $phone, $pickup);
        }

        [$awb, $courier] = $this->awbFor($remoteId, $shipment, $resumed);

        // The AWB is the booking. Pickup and label are conveniences the
        // operator can repeat in the Shiprocket panel; their failure is on
        // the event row and must not undo a consignment that exists.
        try {
            $this->client->generatePickup($remoteId, $shipment->id, $shipment->order_id);
        } catch (ShiprocketApiException) {
            // recorded by the client
        }

        $labelUrl = null;
        try {
            $label = $this->client->generateLabel($remoteId, $shipment->id, $shipment->order_id);
            $labelUrl = is_string($label['label_url'] ?? null) && $label['label_url'] !== '' ? mb_substr($label['label_url'], 0, 512) : null;
        } catch (ShiprocketApiException) {
            // recorded by the client
        }

        return new CourierShipment(
            gateway: Shipment::GATEWAY_SHIPROCKET,
            status: Shipment::STATUS_DISPATCHED,
            carrierCode: $courier === null ? 'Shiprocket' : mb_substr($courier, 0, self::CARRIER_MAX),
            awbNo: mb_substr($awb, 0, self::AWB_MAX),
            gatewayShipmentId: $remoteId,
            labelUrl: $labelUrl,
        );
    }

    public function track(Shipment $shipment): ?CourierShipment
    {
        if ($shipment->gateway !== Shipment::GATEWAY_SHIPROCKET || $shipment->gateway_shipment_id === null) {
            return null;
        }

        $json = $this->client->trackShipment($shipment->gateway_shipment_id, $shipment->id, $shipment->order_id);
        $track = $json['tracking_data']['shipment_track'][0] ?? null;
        $current = is_array($track) && is_string($track['current_status'] ?? null)
            ? strtoupper(trim($track['current_status']))
            : '';

        // Every RTO stage counts as returning: staff need to know the moment
        // a return starts, not only once the parcel is back on the shelf.
        $status = match (true) {
            $current === '' => null,
            $current === 'DELIVERED' => Shipment::STATUS_DELIVERED,
            str_starts_with($current, 'RTO') => Shipment::STATUS_RETURNED,
            default => Shipment::STATUS_DISPATCHED,
        };

        if ($status === null) {
            return null;
        }

        return new CourierShipment(
            gateway: Shipment::GATEWAY_SHIPROCKET,
            status: $status,
            carrierCode: $shipment->carrier_code,
            awbNo: $shipment->awb_no,
            gatewayShipmentId: $shipment->gateway_shipment_id,
            labelUrl: $shipment->label_url,
            courierStatus: mb_substr($current, 0, 40),
        );
    }

    /**
     * Create the Shiprocket order, or find the one an earlier attempt created,
     * and persist its shipment id before anything else can go wrong.
     *
     * @return array{0: string, 1: bool} the Shiprocket shipment id, and whether it
     *                                   was an existing booking (which may already
     *                                   carry an AWB)
     */
    private function book(Shipment $shipment, Order $order, Consignee $consignee, string $phone, string $pickup): array
    {
        $claimed = Shipment::whereKey($shipment->id)
            ->whereNull('gateway_shipment_id')
            ->where('gateway', '!=', Shipment::GATEWAY_SHIPROCKET)
            ->update(['gateway' => Shipment::GATEWAY_SHIPROCKET]);

        if ($claimed === 0) {
            // Claimed earlier and never resolved: either another dispatch is
            // running right now (the cache lock in DispatchService should have
            // stopped that), or an earlier create got no answer. In both cases
            // Shiprocket may already hold the order, so look before creating.
            $existing = $this->findExisting($order, $shipment);
            if ($existing !== null) {
                $shipment->update(['gateway_shipment_id' => $existing]);

                return [$existing, true];
            }

            // Not found is not proof it was never created: Shiprocket's search
            // can lag a fresh order. Creating now could book the parcel twice,
            // so the operator checks the panel and confirms (DispatchService
            // then releases the claim before calling us again).
            throw new ShiprocketApiException(
                "An earlier Shiprocket booking for order {$order->order_no} got no reply, and Shiprocket does not show it yet. "
                ."Look for order {$order->order_no} in the Shiprocket panel: if it is there, try again in a minute; "
                .'if it is not, confirm that and dispatch again.'
            );
        }

        try {
            $created = $this->client->createAdhocOrder(
                $this->orderBody($order, $consignee, $phone, $pickup),
                $shipment->id,
                $order->id,
            );
        } catch (ShiprocketApiException $e) {
            if ($e->isDefiniteRefusal()) {
                // Shiprocket answered and refused, so nothing was created.
                // Release the claim; no answer or a 5xx keeps it, so the
                // retry searches before creating.
                Shipment::whereKey($shipment->id)->whereNull('gateway_shipment_id')
                    ->update(['gateway' => Shipment::GATEWAY_MANUAL]);
            }

            throw $e;
        }

        $remoteId = $created['shipment_id'] ?? null;
        if (! $this->isShipmentId($remoteId)) {
            throw new ShiprocketApiException('Shiprocket created the order but returned no shipment id.');
        }

        $shipment->update(['gateway' => Shipment::GATEWAY_SHIPROCKET, 'gateway_shipment_id' => (string) $remoteId]);

        return [(string) $remoteId, false];
    }

    private function findExisting(Order $order, Shipment $shipment): ?string
    {
        foreach ($this->client->findOrders($order->order_no, $shipment->id, $order->id) as $row) {
            if ((string) ($row['channel_order_id'] ?? '') !== $order->order_no) {
                continue;
            }
            $shipments = $row['shipments'] ?? null;
            $first = is_array($shipments) ? ($shipments[0] ?? null) : null;
            $id = is_array($first) ? ($first['id'] ?? null) : null;

            if ($this->isShipmentId($id)) {
                return (string) $id;
            }
        }

        return null;
    }

    /** A real shipment id: a positive integer, however Shiprocket typed it. */
    private function isShipmentId(mixed $id): bool
    {
        return (is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0;
    }

    /**
     * The AWB and courier for a booked shipment. A resumed booking may
     * already have one — assigning again would be refused, or worse, succeed.
     *
     * @return array{0: string, 1: string|null}
     */
    private function awbFor(string $remoteId, Shipment $shipment, bool $resumed): array
    {
        if ($resumed) {
            $shown = $this->client->showShipment($remoteId, $shipment->id, $shipment->order_id);
            $data = is_array($shown['data'] ?? null) ? $shown['data'] : [];
            $awb = is_string($data['awb'] ?? null) ? trim($data['awb']) : '';

            if ($awb !== '') {
                return [$awb, is_string($data['courier'] ?? null) && $data['courier'] !== '' ? $data['courier'] : null];
            }
        }

        $assigned = $this->client->assignAwb($remoteId, $shipment->id, $shipment->order_id);
        $data = $assigned['response']['data'] ?? null;
        $awb = is_array($data) && (is_string($data['awb_code'] ?? null) || is_int($data['awb_code'] ?? null))
            ? trim((string) $data['awb_code'])
            : '';

        if ($awb === '') {
            throw new ShiprocketApiException('Shiprocket assigned no AWB for shipment '.$remoteId.'.');
        }

        $courier = is_array($data) && is_string($data['courier_name'] ?? null) && $data['courier_name'] !== ''
            ? $data['courier_name']
            : null;

        return [$awb, $courier];
    }

    /** @return array<string, mixed> */
    private function orderBody(Order $order, Consignee $consignee, string $phone, string $pickup): array
    {
        $order->loadMissing('items.variant');

        $items = [];
        $weightG = 0;
        $lengthMm = 0;
        $breadthMm = 0;
        $heightMm = 0;
        $subTotalPaise = 0;

        /** @var OrderItem $item */
        foreach ($order->items as $item) {
            $variant = $item->variant;
            if ($variant === null) {
                continue; // unreachable: parcelGaps() refused it
            }

            $items[] = [
                'name' => (string) $item->product_name_snapshot,
                'sku' => (string) $item->variant_sku_snapshot,
                'units' => (int) $item->qty,
                'selling_price' => round($item->unit_price_paise / 100, 2),
            ];

            // A stacked-box estimate, not a packing algorithm: the widest
            // footprint, units piled on top of each other.
            $weightG += (int) $variant->weight_g * (int) $item->qty;
            $lengthMm = max($lengthMm, (int) $variant->length_mm);
            $breadthMm = max($breadthMm, (int) $variant->breadth_mm);
            $heightMm += (int) $variant->height_mm * (int) $item->qty;
            $subTotalPaise += (int) $item->line_total_paise;
        }

        // Shiprocket refuses a booking without a last name (sandbox, 2026-09-24),
        // and plenty of buyers give one word. The label prints both halves.
        $nameParts = preg_split('/\s+/', trim($consignee->name)) ?: [];
        $firstName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 0, -1)) : trim($consignee->name);
        $lastName = count($nameParts) > 1 ? (string) end($nameParts) : '.';

        $body = [
            'order_id' => $order->order_no,
            'order_date' => ($order->placed_at ?? now())->format('Y-m-d H:i'),
            'pickup_location' => $pickup,
            'billing_customer_name' => $firstName,
            'billing_last_name' => $lastName,
            'billing_address' => $consignee->line1,
            'billing_address_2' => (string) ($consignee->line2 ?? ''),
            'billing_city' => $consignee->city,
            'billing_state' => $consignee->state,
            'billing_pincode' => $consignee->pincode,
            'billing_country' => 'India',
            // The company mailbox, never the buyer's (user decision
            // 2026-09-24): the privacy policy lists name, mobile and address
            // as what a logistics partner receives, and nothing more.
            'billing_email' => (string) config('arovolife.support_email'),
            'billing_phone' => $phone,
            'shipping_is_billing' => true,
            'order_items' => $items,
            // Only prepaid orders reach dispatch; there is no COD.
            'payment_method' => 'Prepaid',
            'sub_total' => round($subTotalPaise / 100, 2),
            'weight' => max(self::MIN_MEASURE, round($weightG / 1000, 3)),
            'length' => max(self::MIN_MEASURE, round($lengthMm / 10, 1)),
            'breadth' => max(self::MIN_MEASURE, round($breadthMm / 10, 1)),
            'height' => max(self::MIN_MEASURE, round($heightMm / 10, 1)),
        ];

        if ($consignee->isCollection() && $consignee->collectorName !== null) {
            // The centre signs for the parcel; the buyer's name tells it who
            // may take it. The buyer's phone is not sent.
            $body['comment'] = 'Collect for: '.$consignee->collectorName;
        }

        return $body;
    }

    /** Shiprocket takes a bare 10-digit Indian mobile. */
    private function tenDigitMobile(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }

        if (preg_match('/^[6-9]\d{9}$/', $digits) !== 1) {
            // Never the number itself: this message reaches the admin screen and the log.
            throw new RuntimeException('The consignee phone is not a 10-digit Indian mobile number, which Shiprocket requires. Correct it, or dispatch manually.');
        }

        return $digits;
    }
}
