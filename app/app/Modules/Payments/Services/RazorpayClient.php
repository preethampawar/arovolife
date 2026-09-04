<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Exceptions\RazorpayApiException;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Support\RazorpayPayloadScrubber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Thin HTTP client over the Razorpay REST API.
 *
 * Every call — success or failure — is written to `payment_events` with the
 * scrubbed request and response and mirrored to the `payments` log channel,
 * so "what did we ask the gateway and what did it say" is answerable from
 * the database. Retries are attempted only for calls that are safe to
 * repeat: GETs, and refund creation, which carries an idempotency header.
 *
 * Deliberately not the official SDK: this is a handful of endpoints, the
 * Laravel HTTP client is fakeable in tests without a network, and the two
 * signature checks are one `hash_hmac` each.
 */
final class RazorpayClient
{
    public const MODE_TEST = 'test';

    public const MODE_LIVE = 'live';

    private const KEY_PATTERN = '/^rzp_(test|live)_[A-Za-z0-9]{6,}$/';

    public function __construct(private readonly RazorpayPayloadScrubber $scrubber) {}

    // ── Configuration ──────────────────────────────────────────────────

    public function keyId(): string
    {
        return (string) config('arovolife.payments.razorpay.key_id', '');
    }

    /** `test` or `live` from the key prefix, null when the key is malformed. */
    public function mode(): ?string
    {
        return preg_match(self::KEY_PATTERN, $this->keyId(), $m) === 1 ? $m[1] : null;
    }

    /** All three credentials present and the key well-formed. */
    public function configured(): bool
    {
        return $this->mode() !== null
            && $this->keySecret() !== ''
            && $this->webhookSecret() !== '';
    }

    /**
     * Production takes only live keys; every other environment only test
     * keys. A test key on the live host would pass every payment check
     * against money that does not exist and mark orders paid.
     */
    public function modeMatchesEnvironment(): bool
    {
        $mode = $this->mode();
        if ($mode === null) {
            return false;
        }

        return app()->environment('production')
            ? $mode === self::MODE_LIVE
            : $mode === self::MODE_TEST;
    }

    private function keySecret(): string
    {
        return (string) config('arovolife.payments.razorpay.key_secret', '');
    }

    private function webhookSecret(): string
    {
        return (string) config('arovolife.payments.razorpay.webhook_secret', '');
    }

    // ── Orders ─────────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $notes
     * @return array<string, mixed>
     */
    public function createOrder(int $amountPaise, string $receipt, array $notes, string $refundSpeed, ?int $orderId = null, ?int $intentId = null): array
    {
        return $this->request('POST', '/orders', [
            'amount' => $amountPaise,
            'currency' => 'INR',
            'receipt' => $receipt,
            'notes' => $notes,
            'payment' => [
                'capture' => 'automatic',
                'capture_options' => [
                    'automatic_expiry_period' => 12,
                    'refund_speed' => $refundSpeed,
                ],
            ],
        ], idempotent: false, eventType: 'orders.create', orderId: $orderId, intentId: $intentId);
    }

    /** @return array<string, mixed>|null the first order carrying this receipt */
    public function fetchOrderByReceipt(string $receipt, ?int $orderId = null, ?int $intentId = null): ?array
    {
        $collection = $this->request('GET', '/orders', ['receipt' => $receipt, 'count' => 1],
            idempotent: true, eventType: 'orders.fetch_by_receipt', orderId: $orderId, intentId: $intentId);

        $items = $collection['items'] ?? [];

        return is_array($items) && $items !== [] ? $items[0] : null;
    }

    /** @return array<string, mixed> */
    public function fetchOrder(string $gatewayOrderId, ?int $orderId = null, ?int $intentId = null): array
    {
        return $this->request('GET', '/orders/'.$gatewayOrderId, [],
            idempotent: true, eventType: 'orders.fetch', orderId: $orderId, intentId: $intentId);
    }

    /** @return list<array<string, mixed>> every payment attempt against the order */
    public function fetchPaymentsForOrder(string $gatewayOrderId, ?int $orderId = null, ?int $intentId = null): array
    {
        $collection = $this->request('GET', '/orders/'.$gatewayOrderId.'/payments', [],
            idempotent: true, eventType: 'orders.fetch_payments', orderId: $orderId, intentId: $intentId);

        $items = $collection['items'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    // ── Payments ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function fetchPayment(string $paymentId, ?int $orderId = null, ?int $intentId = null): array
    {
        return $this->request('GET', '/payments/'.$paymentId, [],
            idempotent: true, eventType: 'payments.fetch', orderId: $orderId, intentId: $intentId, gatewayPaymentId: $paymentId);
    }

    // ── Refunds ────────────────────────────────────────────────────────

    /**
     * Create a refund against a captured payment. `$idempotencyKey` goes in
     * the `X-Refund-Idempotency` header (Razorpay: 10–100 characters) and in
     * `receipt`, so a retried request after a timeout cannot create a second
     * refund.
     *
     * @param  array<string, string>  $notes
     * @return array<string, mixed>
     */
    public function createRefund(string $paymentId, int $amountPaise, string $speed, string $receipt, string $idempotencyKey, array $notes, ?int $orderId = null, ?int $refundIntentId = null): array
    {
        // Razorpay's rule for X-Refund-Idempotency; a key outside it is a 400
        // on every attempt, which would strand the refund on the worklist.
        if (preg_match('/^[A-Za-z0-9_-]{10,100}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Refund idempotency key must be 10–100 letters, digits, hyphens or underscores.');
        }

        return $this->request('POST', '/payments/'.$paymentId.'/refund', [
            'amount' => $amountPaise,
            'speed' => $speed,
            'receipt' => $receipt,
            'notes' => $notes,
        ], idempotent: true, eventType: 'refunds.create', orderId: $orderId, refundIntentId: $refundIntentId,
            gatewayPaymentId: $paymentId, headers: ['X-Refund-Idempotency' => $idempotencyKey]);
    }

    /** @return list<array<string, mixed>> */
    public function fetchRefundsForPayment(string $paymentId, ?int $orderId = null, ?int $refundIntentId = null): array
    {
        $collection = $this->request('GET', '/payments/'.$paymentId.'/refunds', [],
            idempotent: true, eventType: 'refunds.fetch_for_payment', orderId: $orderId, refundIntentId: $refundIntentId, gatewayPaymentId: $paymentId);

        $items = $collection['items'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    /** @return array<string, mixed> */
    public function fetchRefund(string $refundId, ?int $orderId = null, ?int $refundIntentId = null): array
    {
        return $this->request('GET', '/refunds/'.$refundId, [],
            idempotent: true, eventType: 'refunds.fetch', orderId: $orderId, refundIntentId: $refundIntentId);
    }

    // ── Signatures ─────────────────────────────────────────────────────

    /**
     * Standard Checkout returns `razorpay_signature` =
     * HMAC-SHA256(order_id + "|" + payment_id, key_secret).
     */
    public function verifyCheckoutSignature(string $gatewayOrderId, string $paymentId, string $signature): bool
    {
        if ($gatewayOrderId === '' || $paymentId === '' || $signature === '' || $this->keySecret() === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $gatewayOrderId.'|'.$paymentId, $this->keySecret());

        return hash_equals($expected, $signature);
    }

    /** Webhooks sign the raw request body with the webhook secret. */
    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        if ($rawBody === '' || $signature === '' || $this->webhookSecret() === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret());

        return hash_equals($expected, $signature);
    }

    // ── Transport ──────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     *
     * @throws RazorpayApiException
     */
    private function request(
        string $method,
        string $path,
        array $body,
        bool $idempotent,
        string $eventType,
        ?int $orderId = null,
        ?int $intentId = null,
        ?int $refundIntentId = null,
        ?string $gatewayPaymentId = null,
        array $headers = [],
    ): array {
        $started = hrtime(true);
        $pending = $this->pending($headers, $idempotent);

        try {
            $response = $method === 'GET'
                ? $pending->get($path, $body)
                : $pending->post($path, $body);
        } catch (ConnectionException $e) {
            $this->record($eventType, $orderId, $intentId, $refundIntentId, $gatewayPaymentId, null, $started,
                ['request' => $this->scrubber->scrub($body)], 'connection: '.$e->getMessage());

            throw new RazorpayApiException('Razorpay could not be reached for '.$eventType, previous: $e);
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];
        $status = $response->status();

        $error = null;
        if ($response->failed()) {
            $gatewayError = is_array($json['error'] ?? null) ? $json['error'] : [];
            $error = sprintf('HTTP %d %s: %s', $status,
                (string) ($gatewayError['code'] ?? 'UNKNOWN'), (string) ($gatewayError['description'] ?? 'no description'));
        }

        $this->record($eventType, $orderId, $intentId, $refundIntentId,
            $gatewayPaymentId ?? (isset($json['id']) && str_starts_with((string) $json['id'], 'pay_') ? (string) $json['id'] : null),
            $status, $started,
            ['request' => $this->scrubber->scrub($body), 'response' => $this->scrubber->scrub($json)], $error);

        if ($error !== null) {
            $gatewayError = is_array($json['error'] ?? null) ? $json['error'] : [];

            throw new RazorpayApiException(
                'Razorpay '.$eventType.' failed: '.$error,
                httpStatus: $status,
                gatewayCode: isset($gatewayError['code']) ? (string) $gatewayError['code'] : null,
                gatewayDescription: isset($gatewayError['description']) ? (string) $gatewayError['description'] : null,
            );
        }

        return $json;
    }

    /** @param  array<string, string>  $headers */
    private function pending(array $headers, bool $idempotent): PendingRequest
    {
        $pending = Http::baseUrl((string) config('arovolife.payments.razorpay.base_url'))
            ->withBasicAuth($this->keyId(), $this->keySecret())
            ->timeout((int) config('arovolife.payments.razorpay.timeout_seconds', 15))
            ->connectTimeout(5)
            ->acceptJson()
            ->asJson()
            ->withHeaders($headers);

        if ($idempotent) {
            // Retry only on transport failure or a gateway-side 5xx. A 4xx is
            // the gateway's answer and repeating the question will not change it.
            $pending = $pending->retry(3, 300, function (Throwable $e): bool {
                if ($e instanceof ConnectionException) {
                    return true;
                }

                return $e instanceof RequestException && $e->response->serverError();
            }, throw: false);
        }

        return $pending;
    }

    /** @param  array<string, mixed>  $payload */
    private function record(
        string $eventType,
        ?int $orderId,
        ?int $intentId,
        ?int $refundIntentId,
        ?string $gatewayPaymentId,
        ?int $httpStatus,
        int $startedNs,
        array $payload,
        ?string $error,
    ): void {
        $durationMs = (int) ((hrtime(true) - $startedNs) / 1_000_000);

        try {
            PaymentEvent::create([
                'order_id' => $orderId,
                'payment_intent_id' => $intentId,
                'refund_intent_id' => $refundIntentId,
                'gateway' => 'razorpay',
                'direction' => PaymentEvent::DIRECTION_OUTBOUND,
                'event_type' => $eventType,
                'gateway_payment_id' => $gatewayPaymentId,
                'signature_verified' => false,
                'http_status' => $httpStatus,
                'duration_ms' => $durationMs,
                'payload' => $payload,
                'error' => $error,
            ]);
        } catch (Throwable $e) {
            // The record is evidence, not a precondition: a failure to write
            // it must not turn a successful gateway call into an exception.
            Log::channel('payments')->error('payment_events write failed', ['event_type' => $eventType, 'error' => $e->getMessage()]);
        }

        Log::channel('payments')->{$error === null ? 'info' : 'warning'}('razorpay '.$eventType, [
            'order_id' => $orderId,
            'payment_intent_id' => $intentId,
            'refund_intent_id' => $refundIntentId,
            'gateway_payment_id' => $gatewayPaymentId,
            'http_status' => $httpStatus,
            'duration_ms' => $durationMs,
            'error' => $error,
            'payload' => $payload,
        ]);
    }
}
