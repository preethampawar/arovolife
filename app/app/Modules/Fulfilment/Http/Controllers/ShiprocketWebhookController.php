<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Http\Controllers;

use App\Modules\Commerce\Models\Order;
use App\Modules\Fulfilment\Jobs\ProcessShiprocketWebhookJob;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Models\ShipmentEvent;
use App\Modules\Fulfilment\Support\ShiprocketPayloadScrubber;
use App\Modules\Shared\Features\ShiprocketFulfilmentFeature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use JsonException;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shiprocket → us: a parcel's tracking moved.
 *
 * Mirrors the Razorpay webhook, with one difference in how trust works.
 * Shiprocket does not sign its deliveries; it sends back a static token we
 * configured in its panel, in `x-api-key`. A static token can leak, so the
 * body is treated as a hint and nothing more: the job asks Shiprocket's API
 * what the parcel's status is and acts only on that answer. A forged
 * "DELIVERED" therefore cannot start anyone's cooling-off clock.
 *
 *   1. No flag or no token → 404: the endpoint does not exist.
 *   2. Wrong token → 401, nothing stored.
 *   3. Stored once (scrubbed, allow-list), keyed on a digest of what makes a
 *      tracking update distinct; a redelivery is a 200 no-op.
 *   4. Applying it is the job's business; this returns as soon as it is queued.
 *
 * The route path must not contain "shiprocket", "kartrocket", "sr" or "kr":
 * Shiprocket's panel refuses to register such an address.
 */
final class ShiprocketWebhookController extends Controller
{
    public function __construct(private readonly ShiprocketPayloadScrubber $scrubber) {}

    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('arovolife.fulfilment.shiprocket.webhook_token', '');

        if ($token === '' || ! Feature::for(null)->active(ShiprocketFulfilmentFeature::class)) {
            throw new NotFoundHttpException;
        }

        if (! hash_equals($token, (string) $request->header('x-api-key', ''))) {
            Log::warning('shiprocket webhook rejected: token did not match', ['ip' => $request->ip()]);

            return response()->json(['error' => 'unauthorised'], 401);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['error' => 'body'], 400);
        }
        if (! is_array($decoded)) {
            return response()->json(['error' => 'body'], 400);
        }
        /** @var array<string, mixed> $body */
        $body = $decoded;

        $awb = $this->text($body['awb'] ?? null);
        $statusId = $this->text($body['current_status_id'] ?? null);
        $shipment = $this->shipmentFor($awb, $this->text($body['order_id'] ?? null));

        $eventId = hash('sha256', implode('|', [
            $this->text($body['sr_order_id'] ?? null),
            $this->text($body['shipment_id'] ?? null),
            $awb,
            $statusId,
            $this->text($body['current_timestamp'] ?? null),
        ]));

        try {
            $event = ShipmentEvent::create([
                'shipment_id' => $shipment?->id,
                'order_id' => $shipment?->order_id,
                'gateway' => Shipment::GATEWAY_SHIPROCKET,
                'direction' => ShipmentEvent::DIRECTION_WEBHOOK,
                'event_type' => 'tracking.'.($statusId === '' ? 'unknown' : mb_substr($statusId, 0, 40)),
                'gateway_event_id' => $eventId,
                'gateway_shipment_id' => $shipment?->gateway_shipment_id,
                'signature_verified' => true,
                'payload' => $this->scrubber->scrub($body),
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate']);
        }

        if ($shipment !== null) {
            ProcessShiprocketWebhookJob::dispatch($event->id);
        }

        return response()->json(['status' => 'queued']);
    }

    /**
     * Our shipment for this update: by AWB first, which is unique to the
     * parcel; else by the order number we sent Shiprocket as its order id.
     */
    private function shipmentFor(string $awb, string $orderNo): ?Shipment
    {
        if ($awb !== '') {
            $byAwb = Shipment::where('gateway', Shipment::GATEWAY_SHIPROCKET)->where('awb_no', $awb)->first();
            if ($byAwb !== null) {
                return $byAwb;
            }
        }

        if ($orderNo === '') {
            return null;
        }

        $orderId = Order::where('order_no', $orderNo)->value('id');

        return $orderId === null
            ? null
            : Shipment::where('gateway', Shipment::GATEWAY_SHIPROCKET)->where('order_id', $orderId)->first();
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
