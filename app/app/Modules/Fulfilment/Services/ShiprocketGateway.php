<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Fulfilment\Contracts\CourierGateway;
use App\Modules\Fulfilment\Data\Consignee;
use App\Modules\Fulfilment\Data\CourierQuote;
use App\Modules\Fulfilment\Data\CourierShipment;
use App\Modules\Fulfilment\Data\DispatchInstruction;
use App\Modules\Fulfilment\Data\ParcelGap;
use App\Modules\Fulfilment\Exceptions\MissingParcelDetailsException;
use App\Modules\Fulfilment\Exceptions\ShiprocketApiException;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Support\FulfilmentSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Books a consignment with Shiprocket: an order, then an AWB (with the courier
 * staff chose from `quotes()`, or one Shiprocket picks), then — best effort — a pickup request and a label.
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

    /** The pickup address's pincode rarely changes; the nickname is in the key. */
    private const PICKUP_PIN_TTL_SECONDS = 24 * 3600;

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
        $quote = null;
        $resumed = $shipment->gateway === Shipment::GATEWAY_SHIPROCKET && $shipment->gateway_shipment_id !== null;
        if ($resumed) {
            $remoteId = (string) $shipment->gateway_shipment_id;
        } else {
            // A courier that no longer serves the route is refused before
            // anything exists in Shiprocket.
            if ($instruction->courierId !== null) {
                $quote = $this->chosenQuote($order, $instruction->consignee, $instruction->courierId, $shipment);
            }
            [$remoteId, $resumed] = $this->book($shipment, $order, $instruction->consignee, $phone, $pickup);
        }

        [$awb, $courier, $quote] = $this->awbFor($remoteId, $shipment, $resumed, $order, $instruction, $quote);

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
            quote: $quote,
        );
    }

    /**
     * Every courier Shiprocket offers for this parcel, recommended first, then
     * cheapest. Nothing is booked. The rates are the company's cost.
     *
     * @param  bool  $interactive  a person is waiting on the list
     * @return list<CourierQuote>
     *
     * @throws MissingParcelDetailsException|RuntimeException|ShiprocketApiException
     */
    public function quotes(Order $order, Consignee $consignee, bool $interactive = true, ?int $shipmentId = null): array
    {
        // Without every product's weight and size the quote is for a parcel
        // that does not exist.
        $gaps = $this->parcelGaps($order);
        if ($gaps !== []) {
            throw new MissingParcelDetailsException($gaps);
        }

        [$weight, $length, $breadth, $height, $subTotalPaise] = $this->parcelMeasures($order);

        $json = $this->client->serviceability([
            'pickup_postcode' => $this->pickupPostcode($interactive),
            'delivery_postcode' => $consignee->pincode,
            // Only prepaid orders reach dispatch.
            'cod' => 0,
            'weight' => $weight,
            'length' => $length,
            'breadth' => $breadth,
            'height' => $height,
            'declared_value' => round($subTotalPaise / 100, 2),
        ], $shipmentId, $order->id, $interactive);

        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $recommended = $data['recommended_courier_company_id'] ?? $data['shiprocket_recommended_courier_id'] ?? null;
        $recommendedId = is_int($recommended) || (is_string($recommended) && ctype_digit($recommended)) ? (int) $recommended : null;
        if ($recommendedId === null) {
            Log::info('shiprocket serviceability without a recommended courier', ['order_id' => $order->id, 'data_keys' => array_keys($data)]);
        }

        $quotes = [];
        foreach (is_array($data['available_courier_companies'] ?? null) ? $data['available_courier_companies'] : [] as $row) {
            $quote = is_array($row) ? CourierQuote::fromShiprocket($row, $recommendedId) : null;
            if ($quote !== null) {
                $quotes[$quote->courierId] = $quote; // one row per courier
            }
        }

        $quotes = array_values($quotes);
        usort($quotes, fn (CourierQuote $a, CourierQuote $b): int => [$b->recommended, $a->ratePaise] <=> [$a->recommended, $b->ratePaise]);

        return $quotes;
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
     * @return array{0: string, 1: string|null, 2: CourierQuote|null}
     */
    private function awbFor(string $remoteId, Shipment $shipment, bool $resumed, Order $order, DispatchInstruction $instruction, ?CourierQuote $quote): array
    {
        if ($resumed) {
            $shown = $this->client->showShipment($remoteId, $shipment->id, $shipment->order_id);
            $data = is_array($shown['data'] ?? null) ? $shown['data'] : [];
            $awb = is_string($data['awb'] ?? null) ? trim($data['awb']) : '';

            if ($awb !== '') {
                // The courier is already fixed; a choice made now cannot apply.
                return [$awb, is_string($data['courier'] ?? null) && $data['courier'] !== '' ? $data['courier'] : null, null];
            }

            // A held booking with no AWB yet: the choice still applies.
            if ($instruction->courierId !== null && $quote === null) {
                $quote = $this->chosenQuote($order, $instruction->consignee, $instruction->courierId, $shipment);
            }
        }

        $assigned = $this->client->assignAwb($remoteId, $shipment->id, $shipment->order_id, $quote?->courierId);
        $data = $assigned['response']['data'] ?? null;
        $awb = is_array($data) && (is_string($data['awb_code'] ?? null) || is_int($data['awb_code'] ?? null))
            ? trim((string) $data['awb_code'])
            : '';

        if ($awb === '') {
            throw new ShiprocketApiException('Shiprocket assigned no AWB for shipment '.$remoteId.'.');
        }

        $courier = is_array($data) && is_string($data['courier_name'] ?? null) && $data['courier_name'] !== ''
            ? $data['courier_name']
            : $quote?->name;

        return [$awb, $courier, $quote];
    }

    /**
     * Re-read the quotes and find the courier staff chose. The rate stored is
     * this server-side answer, never anything the form sent.
     */
    private function chosenQuote(Order $order, Consignee $consignee, int $courierId, Shipment $shipment): CourierQuote
    {
        foreach ($this->quotes($order, $consignee, interactive: false, shipmentId: $shipment->id) as $quote) {
            if ($quote->courierId === $courierId) {
                return $quote;
            }
        }

        throw new RuntimeException(
            "The courier you chose no longer serves this parcel's route, so order {$order->order_no} was not booked with it. "
            .'Reload the courier list and choose again.'
        );
    }

    /**
     * The pincode of the pickup address named in settings, from the
     * Shiprocket account. Cached as a plain string, keyed on the nickname and
     * the API host, so changing either never serves the old origin.
     */
    private function pickupPostcode(bool $interactive): string
    {
        $nickname = $this->settings->shiprocketPickupLocation();
        if ($nickname === '') {
            throw new RuntimeException('No Shiprocket pickup location is set. Set it under Settings → Fulfilment.');
        }

        $key = 'fulfilment:shiprocket:pickup-pin:'.sha1($this->client->baseUrl().'|'.$nickname);
        $cached = Cache::get($key);
        if (is_string($cached) && preg_match('/^\d{6}$/', $cached) === 1) {
            return $cached;
        }

        foreach ($this->client->pickupLocations($interactive) as $row) {
            if ((string) ($row['pickup_location'] ?? '') !== $nickname) {
                continue;
            }
            $pin = trim((string) ($row['pin_code'] ?? ''));
            if (preg_match('/^\d{6}$/', $pin) === 1) {
                Cache::put($key, $pin, self::PICKUP_PIN_TTL_SECONDS);

                return $pin;
            }
        }

        throw new RuntimeException(
            "The pickup location \"{$nickname}\" was not found in the Shiprocket account, so couriers cannot be quoted. "
            .'Check the nickname under Settings → Fulfilment.'
        );
    }

    /**
     * The stacked-box estimate, in Shiprocket's units: the widest footprint,
     * units piled on top of each other. Not a packing algorithm.
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: int} weight kg, length, breadth, height cm, sub-total paise
     */
    private function parcelMeasures(Order $order): array
    {
        $order->loadMissing('items.variant');

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

            $weightG += (int) $variant->weight_g * (int) $item->qty;
            $lengthMm = max($lengthMm, (int) $variant->length_mm);
            $breadthMm = max($breadthMm, (int) $variant->breadth_mm);
            $heightMm += (int) $variant->height_mm * (int) $item->qty;
            $subTotalPaise += (int) $item->line_total_paise;
        }

        return [
            max(self::MIN_MEASURE, round($weightG / 1000, 3)),
            max(self::MIN_MEASURE, round($lengthMm / 10, 1)),
            max(self::MIN_MEASURE, round($breadthMm / 10, 1)),
            max(self::MIN_MEASURE, round($heightMm / 10, 1)),
            $subTotalPaise,
        ];
    }

    /** @return array<string, mixed> */
    private function orderBody(Order $order, Consignee $consignee, string $phone, string $pickup): array
    {
        $order->loadMissing('items.variant');

        $items = [];

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
        }

        [$weight, $length, $breadth, $height, $subTotalPaise] = $this->parcelMeasures($order);

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
            'weight' => $weight,
            'length' => $length,
            'breadth' => $breadth,
            'height' => $height,
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
