<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Services;

use App\Modules\Fulfilment\Exceptions\ShiprocketApiException;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Models\ShipmentEvent;
use App\Modules\Fulfilment\Support\ShiprocketPayloadScrubber;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin HTTP client over the Shiprocket external API.
 *
 * Every call — success or failure — is written to `shipment_events` with the
 * scrubbed request and response, so "what did we ask the courier and what did
 * it say" is answerable from the database. The log line carries metadata only:
 * a booking holds the consignee's name, phone and address, and the log is not
 * where that belongs.
 *
 * Retries are attempted only for GETs. A POST is never repeated automatically:
 * a retried order create after a timeout can book a second consignment for the
 * same parcel — a real van at a real address.
 *
 * Shiprocket authenticates with an email/password login that returns a bearer
 * token valid for ten days. The token is cached (encrypted — the cache is a
 * Redis shared with other applications) and renewed once on a 401.
 */
final class ShiprocketClient
{
    public const SANDBOX_HOST = 'api-sandbox.shiprocket.in';

    public const LIVE_HOST = 'apiv2.shiprocket.in';

    private const DEFAULT_BASE_URL = 'https://api-sandbox.shiprocket.in/v1/external';

    /** Shiprocket issues 240-hour tokens; renew a day early. */
    private const TOKEN_TTL_SECONDS = 9 * 24 * 3600;

    private const ASSIGN_AWB_TIMEOUT_SECONDS = 60;

    /** A staff member is waiting on the courier list: answer fast or not at all. */
    private const QUOTE_TIMEOUT_SECONDS = 10;

    public function __construct(private readonly ShiprocketPayloadScrubber $scrubber) {}

    // ── Configuration ──────────────────────────────────────────────────

    public function baseUrl(): string
    {
        $url = trim((string) config('arovolife.fulfilment.shiprocket.base_url', ''));

        return rtrim($url === '' ? self::DEFAULT_BASE_URL : $url, '/');
    }

    /** The API host, or null when the base URL is not an https URL. */
    public function host(): ?string
    {
        $url = $this->baseUrl();

        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : null;
    }

    /** Credentials present and the base URL is one of Shiprocket's two hosts. */
    public function configured(): bool
    {
        return $this->email() !== ''
            && $this->password() !== ''
            && in_array($this->host(), [self::SANDBOX_HOST, self::LIVE_HOST], true);
    }

    /**
     * Production books only against the live host; every other environment
     * only against the sandbox. A live host on staging would book real
     * couriers against the company's wallet for test orders.
     */
    public function hostMatchesEnvironment(): bool
    {
        return app()->environment('production')
            ? $this->host() === self::LIVE_HOST
            : $this->host() === self::SANDBOX_HOST;
    }

    private function email(): string
    {
        return trim((string) config('arovolife.fulfilment.shiprocket.email', ''));
    }

    private function password(): string
    {
        return (string) config('arovolife.fulfilment.shiprocket.password', '');
    }

    // ── Orders and shipments ───────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createAdhocOrder(array $body, ?int $shipmentId, ?int $orderId): array
    {
        return $this->request('POST', '/orders/create/adhoc', $body, 'orders.create', $shipmentId, $orderId);
    }

    /**
     * Shiprocket orders carrying our order number as their channel order id.
     * Used before a re-create, when an earlier create may have succeeded
     * without its answer reaching us.
     *
     * @return list<array<string, mixed>>
     */
    public function findOrders(string $orderNo, ?int $shipmentId, ?int $orderId): array
    {
        $json = $this->request('GET', '/orders', ['search' => $orderNo], 'orders.search', $shipmentId, $orderId);

        $rows = $json['data'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return array<string, mixed> */
    public function assignAwb(string $gatewayShipmentId, ?int $shipmentId, ?int $orderId, ?int $courierId = null): array
    {
        // Without a courier id Shiprocket picks one itself.
        $body = ['shipment_id' => (int) $gatewayShipmentId];
        if ($courierId !== null) {
            $body['courier_id'] = $courierId;
        }

        // Courier allocation is slow: the sandbox took ~32 s to answer on
        // 2026-09-24, past the 20 s default. A timeout here leaves the booking
        // without an AWB, so give it room.
        return $this->request('POST', '/courier/assign/awb', $body,
            'courier.assign_awb', $shipmentId, $orderId, $gatewayShipmentId, timeoutSeconds: self::ASSIGN_AWB_TIMEOUT_SECONDS);
    }

    /**
     * The couriers that can carry a parcel between two pincodes, with their
     * rates and delivery estimates. Nothing is booked.
     *
     * @param  array<string, scalar>  $query
     * @param  bool  $interactive  a person is waiting: short timeout, no retry
     * @return array<string, mixed>
     */
    public function serviceability(array $query, ?int $shipmentId, ?int $orderId, bool $interactive = false): array
    {
        return $this->request('GET', '/courier/serviceability/', $query, 'courier.serviceability',
            $shipmentId, $orderId, interactive: $interactive);
    }

    /**
     * The account's pickup addresses.
     *
     * @param  bool  $interactive  a person is waiting: short timeout, no retry
     * @return list<array<string, mixed>>
     */
    public function pickupLocations(bool $interactive = false): array
    {
        $json = $this->request('GET', '/settings/company/pickup', [], 'settings.pickup', null, null, interactive: $interactive);
        $rows = $json['data']['shipping_address'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return array<string, mixed> */
    public function generatePickup(string $gatewayShipmentId, ?int $shipmentId, ?int $orderId): array
    {
        return $this->request('POST', '/courier/generate/pickup', ['shipment_id' => [(int) $gatewayShipmentId]],
            'courier.generate_pickup', $shipmentId, $orderId, $gatewayShipmentId);
    }

    /** @return array<string, mixed> */
    public function generateLabel(string $gatewayShipmentId, ?int $shipmentId, ?int $orderId): array
    {
        return $this->request('POST', '/courier/generate/label', ['shipment_id' => [(int) $gatewayShipmentId]],
            'courier.generate_label', $shipmentId, $orderId, $gatewayShipmentId);
    }

    /** @return array<string, mixed> */
    public function showShipment(string $gatewayShipmentId, ?int $shipmentId, ?int $orderId): array
    {
        return $this->request('GET', '/shipments/'.rawurlencode($gatewayShipmentId), [],
            'shipments.show', $shipmentId, $orderId, $gatewayShipmentId);
    }

    /** @return array<string, mixed> */
    public function trackShipment(string $gatewayShipmentId, ?int $shipmentId, ?int $orderId): array
    {
        return $this->request('GET', '/courier/track/shipment/'.rawurlencode($gatewayShipmentId), [],
            'courier.track', $shipmentId, $orderId, $gatewayShipmentId);
    }

    // ── Authentication ─────────────────────────────────────────────────

    private function tokenCacheKey(): string
    {
        return 'fulfilment:shiprocket:token:'.sha1($this->baseUrl().'|'.$this->email());
    }

    private function token(bool $renew = false): string
    {
        $key = $this->tokenCacheKey();

        if ($renew) {
            Cache::forget($key);
        } else {
            $cached = Cache::get($key);
            if (is_string($cached) && $cached !== '') {
                try {
                    return Crypt::decryptString($cached);
                } catch (DecryptException) {
                    // APP_KEY rotated since it was cached. Log in again.
                    Cache::forget($key);
                }
            }
        }

        $started = hrtime(true);

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->timeout($this->timeout())
                ->connectTimeout(5)
                ->acceptJson()
                ->asJson()
                ->post('/auth/login', ['email' => $this->email(), 'password' => $this->password()]);
        } catch (ConnectionException $e) {
            // Never the request or the response: one is the password, the other the token.
            $this->record('auth.login', null, null, null, null, $started, null, 'connection failure');

            throw new ShiprocketApiException('Shiprocket could not be reached to log in.', previous: $e);
        }

        $token = $response->json('token');

        if ($response->failed() || ! is_string($token) || $token === '') {
            $this->record('auth.login', null, null, null, $response->status(), $started, null,
                'HTTP '.$response->status().': login refused');

            throw new ShiprocketApiException(
                'Shiprocket refused the API login (HTTP '.$response->status().'). Check the API user in the environment file.',
                httpStatus: $response->status(),
            );
        }

        $this->record('auth.login', null, null, null, $response->status(), $started, null, null);

        Cache::put($key, Crypt::encryptString($token), self::TOKEN_TTL_SECONDS);

        return $token;
    }

    // ── Transport ──────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws ShiprocketApiException
     */
    private function request(
        string $method,
        string $path,
        array $body,
        string $eventType,
        ?int $shipmentId,
        ?int $orderId,
        ?string $gatewayShipmentId = null,
        ?int $timeoutSeconds = null,
        bool $interactive = false,
    ): array {
        $started = hrtime(true);

        try {
            $response = $this->send($method, $path, $body, $this->token(), $timeoutSeconds, $interactive);

            if ($response->status() === 401) {
                // Expired or revoked. One fresh login, one retry — never a loop.
                $response = $this->send($method, $path, $body, $this->token(renew: true), $timeoutSeconds, $interactive);
            }
        } catch (ConnectionException $e) {
            $this->record($eventType, $shipmentId, $orderId, $gatewayShipmentId, null, $started,
                ['request' => $this->scrubber->scrub($body)], 'connection failure');

            throw new ShiprocketApiException('Shiprocket could not be reached for '.$eventType.'.', previous: $e);
        }

        /** @var array<string, mixed> $json */
        $json = is_array($response->json()) ? $response->json() : [];
        $status = $response->status();

        $gatewayMessage = $this->failureMessage($response, $json);
        $error = $gatewayMessage === null ? null : sprintf('HTTP %d: %s', $status, $gatewayMessage);

        $this->record($eventType, $shipmentId, $orderId, $gatewayShipmentId ?? $this->shipmentIdIn($json),
            $status, $started,
            ['request' => $this->scrubber->scrub($body), 'response' => $this->scrubber->scrub($json)], $error);

        if ($error !== null) {
            throw new ShiprocketApiException(
                'Shiprocket '.$eventType.' failed: '.$error,
                httpStatus: $status,
                gatewayMessage: $gatewayMessage,
            );
        }

        return $json;
    }

    /** @param  array<string, mixed>  $body */
    private function send(string $method, string $path, array $body, string $token, ?int $timeoutSeconds = null, bool $interactive = false): Response
    {
        $pending = $this->pending($token, retry: $method === 'GET' && ! $interactive);
        if ($interactive) {
            $pending = $pending->timeout(self::QUOTE_TIMEOUT_SECONDS);
        } elseif ($timeoutSeconds !== null) {
            $pending = $pending->timeout(max($timeoutSeconds, $this->timeout()));
        }

        return $method === 'GET' ? $pending->get($path, $body) : $pending->post($path, $body);
    }

    private function pending(string $token, bool $retry): PendingRequest
    {
        $pending = Http::baseUrl($this->baseUrl())
            ->withToken($token)
            ->timeout($this->timeout())
            ->connectTimeout(5)
            ->acceptJson()
            ->asJson();

        if ($retry) {
            // Transport failure or a gateway 5xx only. A 4xx is an answer.
            $pending = $pending->retry(3, 300, function (Throwable $e): bool {
                if ($e instanceof ConnectionException) {
                    return true;
                }

                return $e instanceof RequestException && $e->response->serverError();
            }, throw: false);
        }

        return $pending;
    }

    private function timeout(): int
    {
        return max(1, (int) config('arovolife.fulfilment.shiprocket.timeout_seconds', 20));
    }

    /**
     * Shiprocket reports some failures inside a 200: an AWB that could not be
     * assigned, or a body `status_code` of 4xx/5xx. Null when the call worked.
     *
     * @param  array<string, mixed>  $json
     */
    private function failureMessage(Response $response, array $json): ?string
    {
        $message = is_string($json['message'] ?? null) ? $this->scrubber->sanitise($json['message']) : null;

        if ($response->failed()) {
            return $message ?? 'no message';
        }

        $bodyStatus = $json['status_code'] ?? null;
        if (is_numeric($bodyStatus) && (int) $bodyStatus >= 400) {
            return $message ?? 'status_code '.(int) $bodyStatus;
        }

        if (array_key_exists('awb_assign_status', $json) && (int) $json['awb_assign_status'] !== 1) {
            $data = $json['response']['data'] ?? null;
            $reason = is_array($data) && is_string($data['awb_assign_error'] ?? null)
                ? $this->scrubber->sanitise($data['awb_assign_error'])
                : null;

            return $reason ?? $message ?? 'AWB not assigned';
        }

        return null;
    }

    /** @param  array<string, mixed>  $json */
    private function shipmentIdIn(array $json): ?string
    {
        $id = $json['shipment_id'] ?? null;

        return is_int($id) || (is_string($id) && $id !== '') ? (string) $id : null;
    }

    /** @param  array<string, mixed>|null  $payload */
    private function record(
        string $eventType,
        ?int $shipmentId,
        ?int $orderId,
        ?string $gatewayShipmentId,
        ?int $httpStatus,
        int $startedNs,
        ?array $payload,
        ?string $error,
    ): void {
        $durationMs = (int) ((hrtime(true) - $startedNs) / 1_000_000);

        try {
            ShipmentEvent::create([
                'shipment_id' => $shipmentId,
                'order_id' => $orderId,
                'gateway' => Shipment::GATEWAY_SHIPROCKET,
                'direction' => ShipmentEvent::DIRECTION_OUTBOUND,
                'event_type' => $eventType,
                // Null for every outbound call: the unique (gateway,
                // gateway_event_id) pair is the webhook replay guard.
                'gateway_event_id' => null,
                'gateway_shipment_id' => $gatewayShipmentId !== null ? mb_substr($gatewayShipmentId, 0, 64) : null,
                'signature_verified' => false,
                'http_status' => $httpStatus,
                'duration_ms' => $durationMs,
                'payload' => $payload,
                'error' => $error,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Evidence, not a precondition: failing to write it must not turn
            // a booked consignment into an exception the operator retries.
            Log::error('shipment_events write failed', ['event_type' => $eventType, 'error' => $e->getMessage()]);
        }

        Log::{$error === null ? 'info' : 'warning'}('shiprocket '.$eventType, [
            'shipment_id' => $shipmentId,
            'order_id' => $orderId,
            'gateway_shipment_id' => $gatewayShipmentId,
            'http_status' => $httpStatus,
            'duration_ms' => $durationMs,
            'error' => $error,
        ]);
    }
}
