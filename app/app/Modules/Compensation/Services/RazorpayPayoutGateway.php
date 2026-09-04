<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Exceptions\BankDecryptionException;
use App\Modules\Compensation\Exceptions\BankValidationException;
use App\Modules\Compensation\Exceptions\PayoutGatewayException;
use App\Modules\Compensation\Exceptions\PayoutGatewayNotConfiguredException;
use App\Modules\Compensation\Models\PayoutGatewayEvent;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Support\RazorpayPayoutPayloadScrubber;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Payments\Services\RazorpayClient;
use App\Modules\Shared\Crypto\PiiCrypter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Thin HTTP client over the RazorpayX Payouts API — money leaving the company.
 *
 * Deliberately not the official SDK, for the same reasons as
 * {@see RazorpayClient}: a handful of
 * endpoints, a fakeable HTTP client in tests, and one `hash_hmac` for the
 * webhook signature.
 *
 * Three objects make one transfer:
 *   Contact (the person)  →  Fund Account (their bank account)  →  Payout.
 * The first two are cached — a contact on the distributor row, a fund account
 * looked up from the contact — so a weekly batch creates one payout per
 * distributor and nothing else.
 *
 * Every call, success or failure, is written to `payout_gateway_events` with
 * a SCRUBBED payload and mirrored to the `payments` log channel. Nothing here
 * may log or store a bank account number, an IFSC or a person's name; the
 * trail carries distributor id, line item id and opaque gateway ids only.
 */
final class RazorpayPayoutGateway
{
    /** Prefix of the `reference_id` we set on every payout. */
    private const REFERENCE_PREFIX = 'AROVOPAY-';

    private const CONTACT_PREFIX = 'ADN-';

    /**
     * Namespace for the deterministic idempotency UUIDs. A v5 UUID over
     * (line item, attempt) means a crash-resumed retry of the SAME attempt
     * sends the SAME key, so Razorpay returns the payout it already made
     * instead of making a second one.
     */
    private const IDEMPOTENCY_NAMESPACE = 'arovolife:payout:';

    public function __construct(
        private readonly PayoutGatewaySettings $settings,
        private readonly RazorpayPayoutPayloadScrubber $scrubber,
    ) {}

    // ── Configuration ──────────────────────────────────────────────────

    /** Credentials AND the debit account present — everything a payout needs. */
    public function configured(): bool
    {
        return $this->settings->razorpayReady();
    }

    /** The reference id carried on every payout body. */
    public static function referenceFor(int $lineItemId): string
    {
        return self::REFERENCE_PREFIX.$lineItemId;
    }

    /**
     * Deterministic idempotency key for one dispatch attempt of one line item.
     * Public so the retry path can reason about it in tests.
     */
    public static function idempotencyKey(int $lineItemId, int $attempt): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, self::IDEMPOTENCY_NAMESPACE.$lineItemId.':'.$attempt)->toString();
    }

    // ── Contacts ───────────────────────────────────────────────────────

    /**
     * The distributor's RazorpayX contact id, creating it on first use.
     *
     * Idempotent three ways: the cached column short-circuits entirely, the
     * `reference_id` is the ADN so a re-create is refused by the gateway, and
     * that refusal is caught and resolved to the contact that already exists.
     */
    public function ensureContact(Distributor $distributor): string
    {
        $cached = (string) ($distributor->razorpay_contact_id ?? '');
        if ($cached !== '') {
            return $cached;
        }

        $referenceId = self::CONTACT_PREFIX.$distributor->adn;

        try {
            $contact = $this->post('/contacts', [
                'name' => $this->contactName($distributor),
                'email' => (string) ($distributor->user?->email ?? ''),
                // Razorpay wants digits, not E.164 — a leading `+` is a 400.
                'contact' => (string) preg_replace('/\D/', '', (string) ($distributor->user?->phone_e164 ?? '')),
                'type' => 'vendor',
                'reference_id' => $referenceId,
                'notes' => ['adn' => (string) $distributor->adn],
            ], 'contacts.create');
        } catch (PayoutGatewayException $e) {
            // "Reference ID Already exist" — a previous run created the
            // contact and crashed before the column was written. Adopt it.
            $existing = $this->duplicateReferenceId($e)
                ? $this->findContactByReference($referenceId)
                : null;

            if ($existing === null) {
                throw $e;
            }

            $contact = $existing;
        }

        $contactId = (string) ($contact['id'] ?? '');
        if ($contactId === '') {
            throw new PayoutGatewayException('Razorpay returned a contact with no id.', operation: 'contacts.create');
        }

        $distributor->forceFill(['razorpay_contact_id' => $contactId])->save();

        return $contactId;
    }

    // ── Fund accounts ──────────────────────────────────────────────────

    /**
     * The fund account for this distributor's bank details, reusing the one
     * already registered against the contact when the IFSC and account number
     * match. Razorpay fund accounts are immutable, so changed bank details
     * legitimately produce a new one.
     *
     * @throws BankDecryptionException the ciphertext on file no longer decrypts (LOG-2)
     * @throws BankValidationException the details are structurally wrong
     */
    public function ensureFundAccount(Distributor $distributor, string $contactId): string
    {
        $ifsc = strtoupper(trim((string) $distributor->bank_ifsc));

        // Same hard stop as PayoutService::bankLast4ForDistributor(): a
        // ciphertext that no longer decrypts holds the payout for THIS
        // distributor. Never log the ciphertext itself.
        try {
            $accountNumber = trim(PiiCrypter::decryptString((string) $distributor->bank_account_enc));
        } catch (Throwable $failure) {
            Log::critical('Bank account decryption failed — payout held for distributor', [
                'distributor_id' => $distributor->id,
                'context' => 'bank_decryption_failure',
                'error' => $failure->getMessage(),
            ]);

            throw new BankDecryptionException((int) $distributor->id);
        }

        if ($ifsc === '' || $accountNumber === '') {
            throw new BankValidationException(
                $ifsc === '' ? 'ifsc' : 'account_number',
                $ifsc === ''
                    ? 'No IFSC code on file — re-capture the distributor’s bank details.'
                    : 'No bank account number on file — re-capture the distributor’s bank details.',
            );
        }

        $existing = $this->findFundAccount($contactId, $ifsc, $accountNumber);
        if ($existing !== null) {
            return $existing;
        }

        try {
            $account = $this->post('/fund_accounts', [
                'contact_id' => $contactId,
                'account_type' => 'bank_account',
                'bank_account' => [
                    'name' => $this->contactName($distributor),
                    'ifsc' => $ifsc,
                    'account_number' => $accountNumber,
                ],
            ], 'fund_accounts.create');
        } catch (PayoutGatewayException $e) {
            throw $this->asBankValidationFailure($e) ?? $e;
        }

        $fundAccountId = (string) ($account['id'] ?? '');
        if ($fundAccountId === '') {
            throw new PayoutGatewayException('Razorpay returned a fund account with no id.', operation: 'fund_accounts.create');
        }

        return $fundAccountId;
    }

    // ── Payouts ────────────────────────────────────────────────────────

    /**
     * Create the bank transfer for one line item.
     *
     * `reference_id` carries AROVOPAY-{line item id} and the
     * `X-Payout-Idempotency` header a deterministic v5 UUID over (line item,
     * attempt): a request replayed after a timeout resolves to the same
     * payout instead of paying the distributor twice.
     *
     * @param  int  $attempt  0 for the batch dispatch, then the retry count
     * @return array{id: string, status: string, utr: string|null}
     */
    public function createPayout(PayoutLineItem $line, Distributor $distributor, string $fundAccountId, int $attempt = 0): array
    {
        $accountNumber = $this->settings->accountNumber();
        if ($accountNumber === '') {
            throw new PayoutGatewayNotConfiguredException('RAZORPAYX_ACCOUNT_NUMBER is not configured — no debit account to pay from.');
        }

        $reference = self::referenceFor((int) $line->id);
        $netPaise = (int) $line->net_transferred_paise;

        try {
            $payout = $this->post('/payouts', [
                'account_number' => $accountNumber,
                'fund_account_id' => $fundAccountId,
                'amount' => $netPaise,
                'currency' => 'INR',
                // modeFor(), not transferMode(): an IMPS transfer above ₹5L is
                // rejected by NPCI, so the largest payouts fall back to NEFT.
                'mode' => $this->settings->modeFor($netPaise),
                'purpose' => 'payout',
                // Razorpay parks the payout instead of rejecting it when the
                // RazorpayX balance is short; it leaves as soon as ops top up.
                'queue_if_low_balance' => true,
                'reference_id' => $reference,
                'narration' => $this->settings->narration(),
                'notes' => [
                    'payout_batch_id' => (string) $line->payout_batch_id,
                    'distributor_id' => (string) $line->distributor_id,
                    'adn' => (string) $distributor->adn,
                ],
            ], 'payouts.create', [
                'X-Payout-Idempotency' => self::idempotencyKey((int) $line->id, $attempt),
            ], $line);
        } catch (PayoutGatewayException $e) {
            $bankFailure = $this->asBankValidationFailure($e);
            if ($bankFailure !== null) {
                throw $bankFailure;
            }

            // A duplicate is proof the transfer already exists — resolve it to
            // that payout rather than letting the batch believe it failed and
            // dispatch a second one.
            $existing = $this->duplicatePayout($e)
                ? ($this->payoutIdIn($e) ?? $this->findPayoutByReference($reference))
                : null;

            if ($existing === null) {
                if ($this->insufficientBalance($e)) {
                    // queue_if_low_balance should have prevented this. If it
                    // still happened, ops must top up the RazorpayX account —
                    // nothing about the distributor is wrong.
                    Log::channel('payments')->critical('RazorpayX payout rejected: insufficient balance', [
                        'payout_line_item_id' => $line->id,
                        'distributor_id' => $line->distributor_id,
                        'amount_paise' => $netPaise,
                    ]);
                }

                throw $e;
            }

            $payout = $existing;
        }

        $payoutId = (string) ($payout['id'] ?? '');
        if ($payoutId === '') {
            throw new PayoutGatewayException('Razorpay returned a payout with no id.', operation: 'payouts.create');
        }

        return [
            'id' => $payoutId,
            'status' => (string) ($payout['status'] ?? 'queued'),
            'utr' => isset($payout['utr']) && $payout['utr'] !== '' ? (string) $payout['utr'] : null,
        ];
    }

    /**
     * Current gateway-side state of a payout — the backstop when a webhook
     * never arrives.
     *
     * @return array<string, mixed>
     */
    public function fetchPayout(string $razorpayPayoutId): array
    {
        return $this->get('/payouts/'.$razorpayPayoutId, [], 'payouts.fetch');
    }

    /**
     * Cheapest possible authenticated call, for the admin "test connection"
     * button. Listing contacts needs no account number and no data to exist,
     * so it proves the key pair works and nothing else. Throws on non-2xx.
     *
     * @return array<string, mixed>
     */
    public function ping(): array
    {
        return $this->get('/contacts', ['count' => 1], 'contacts.ping');
    }

    // ── Signatures ─────────────────────────────────────────────────────

    /** RazorpayX signs the raw webhook body with the payouts webhook secret. */
    public function verifyWebhookSignature(string $rawPayload, string $signature): bool
    {
        $secret = $this->settings->webhookSecret();

        if ($rawPayload === '' || $signature === '' || $secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawPayload, $secret), $signature);
    }

    // ── Error classification ───────────────────────────────────────────

    /**
     * A gateway refusal that is really "these bank details are wrong". Not
     * retryable: nothing changes until ops re-capture the details, so an
     * auto-retry would only burn attempts.
     */
    private function asBankValidationFailure(PayoutGatewayException $e): ?BankValidationException
    {
        $code = strtoupper((string) $e->gatewayCode);
        $description = strtolower((string) ($e->gatewayDescription ?? ''));

        if (str_contains($code, 'INVALID_IFSC') || str_contains($description, 'ifsc')) {
            return new BankValidationException('ifsc', 'Invalid IFSC code — re-capture the distributor’s bank details.');
        }

        if (str_contains($code, 'INVALID_ACCOUNT_NUMBER')
            || str_contains($code, 'FUND_ACCOUNT_INVALID')
            || str_contains($description, 'account number')) {
            return new BankValidationException('account_number', 'Invalid bank account number — re-capture the distributor’s bank details.');
        }

        return null;
    }

    private function duplicateReferenceId(PayoutGatewayException $e): bool
    {
        $description = strtolower((string) ($e->gatewayDescription ?? ''));

        return str_contains($description, 'already exist')
            && (str_contains($description, 'reference id') || str_contains($description, 'reference_id'));
    }

    private function duplicatePayout(PayoutGatewayException $e): bool
    {
        return str_contains(strtoupper((string) $e->gatewayCode), 'DUPLICATE')
            || str_contains(strtolower((string) ($e->gatewayDescription ?? '')), 'duplicate');
    }

    private function insufficientBalance(PayoutGatewayException $e): bool
    {
        return str_contains(strtoupper((string) $e->gatewayCode), 'INSUFFICIENT_BALANCE')
            || str_contains(strtolower((string) ($e->gatewayDescription ?? '')), 'insufficient balance');
    }

    /**
     * Razorpay names the conflicting entity in `error.meta.id` on some refusals.
     *
     * @return array<string, mixed>|null
     */
    private function payoutIdIn(PayoutGatewayException $e): ?array
    {
        $id = isset($e->gatewayMeta['id']) ? (string) $e->gatewayMeta['id'] : '';

        return $id !== '' ? ['id' => $id, 'status' => 'queued'] : null;
    }

    // ── Lookups used to recover from duplicates ────────────────────────

    /** @return array<string, mixed>|null */
    private function findContactByReference(string $referenceId): ?array
    {
        $collection = $this->get('/contacts', ['reference_id' => $referenceId, 'count' => 1], 'contacts.fetch_by_reference');

        return $this->firstItem($collection);
    }

    /** @return array<string, mixed>|null */
    private function findPayoutByReference(string $referenceId): ?array
    {
        $query = ['reference_id' => $referenceId, 'count' => 1];
        if ($this->settings->accountNumber() !== '') {
            $query['account_number'] = $this->settings->accountNumber();
        }

        return $this->firstItem($this->get('/payouts', $query, 'payouts.fetch_by_reference'));
    }

    /**
     * The contact's existing fund account for exactly these bank details, or
     * null. Razorpay masks nothing on this response, so the comparison is on
     * the full account number — which is precisely why the response is
     * scrubbed before it is ever logged or stored.
     */
    private function findFundAccount(string $contactId, string $ifsc, string $accountNumber): ?string
    {
        try {
            $collection = $this->get('/fund_accounts', ['contact_id' => $contactId, 'count' => 100], 'fund_accounts.list');
        } catch (PayoutGatewayException) {
            // A failed lookup is not a failed payout: fall through to create,
            // which the gateway itself de-duplicates for identical details.
            return null;
        }

        $items = is_array($collection['items'] ?? null) ? $collection['items'] : [];

        foreach ($items as $item) {
            if (! is_array($item) || ($item['account_type'] ?? null) !== 'bank_account') {
                continue;
            }
            if (($item['active'] ?? true) === false) {
                continue;
            }

            $bank = is_array($item['bank_account'] ?? null) ? $item['bank_account'] : [];
            if (strtoupper((string) ($bank['ifsc'] ?? '')) !== $ifsc) {
                continue;
            }
            if (! hash_equals((string) ($bank['account_number'] ?? ''), $accountNumber)) {
                continue;
            }

            $id = (string) ($item['id'] ?? '');
            if ($id !== '') {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $collection
     * @return array<string, mixed>|null
     */
    private function firstItem(array $collection): ?array
    {
        $items = is_array($collection['items'] ?? null) ? $collection['items'] : [];

        return $items !== [] && is_array($items[0]) ? $items[0] : null;
    }

    // ── Transport ──────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     *
     * @throws PayoutGatewayException
     */
    private function post(string $path, array $body, string $operation, array $headers = [], ?PayoutLineItem $line = null): array
    {
        return $this->send('POST', $path, $body, $operation, $headers, $line);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws PayoutGatewayException
     */
    private function get(string $path, array $query, string $operation): array
    {
        return $this->send('GET', $path, $query, $operation, [], null);
    }

    /**
     * The parsed body is returned unscrubbed — the fund-account match needs
     * the real account number to compare against. Scrubbing happens on the
     * way OUT, to the event row and the log, never on the value the caller
     * reasons about in memory.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     *
     * @throws PayoutGatewayException
     * @throws PayoutGatewayNotConfiguredException
     */
    private function send(
        string $method,
        string $path,
        array $payload,
        string $operation,
        array $headers,
        ?PayoutLineItem $line,
    ): array {
        if (! $this->settings->razorpayConfigured()) {
            throw new PayoutGatewayNotConfiguredException;
        }

        $started = hrtime(true);
        $scrubbedRequest = $this->scrubber->scrub($payload);

        try {
            $pending = $this->http()->withHeaders($headers);
            $response = $method === 'GET'
                ? $pending->get($path, $payload)
                : $pending->post($path, $payload);
        } catch (ConnectionException $e) {
            $this->record($operation, $line, null, null, $started, ['request' => $scrubbedRequest], 'connection: '.$e->getMessage());

            throw new PayoutGatewayException(
                'RazorpayX could not be reached for '.$operation.'.',
                transport: true,
                operation: $operation,
                previous: $e,
            );
        }

        $status = $response->status();

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];
        $scrubbedResponse = $this->scrubber->scrub($json);

        // Laravel's retry() only sees transport failures, so a throttle that
        // survives the three attempts arrives here as a plain 429.
        if ($status === 429) {
            $this->record($operation, $line, $status, null, $started,
                ['request' => $scrubbedRequest, 'response' => $scrubbedResponse], 'rate limited');

            throw new PayoutGatewayException(
                'Rate limited by Razorpay — try again shortly.',
                httpStatus: $status,
                transport: true,
                operation: $operation,
            );
        }

        if ($response->failed()) {
            $error = is_array($json['error'] ?? null) ? $json['error'] : [];
            $code = (string) ($error['code'] ?? 'UNKNOWN');
            $description = (string) ($error['description'] ?? 'no description');
            $meta = is_array($error['meta'] ?? null) ? $error['meta'] : null;

            $this->record($operation, $line, $status, null, $started,
                ['request' => $scrubbedRequest, 'response' => $scrubbedResponse], $code.': '.$description);

            throw new PayoutGatewayException(
                'RazorpayX '.$operation.' failed: '.$description,
                httpStatus: $status,
                gatewayCode: $code,
                gatewayDescription: $description,
                gatewayMeta: $meta,
                operation: $operation,
            );
        }

        $gatewayPayoutId = str_starts_with((string) ($json['id'] ?? ''), 'pout_') ? (string) $json['id'] : null;

        $this->record($operation, $line, $status, $gatewayPayoutId, $started,
            ['request' => $scrubbedRequest, 'response' => $scrubbedResponse], null);

        return $json;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->settings->baseUrl())
            ->withBasicAuth($this->settings->keyId(), $this->settings->keySecret())
            ->timeout($this->settings->timeoutSeconds())
            ->connectTimeout(5)
            ->acceptJson()
            ->asJson()
            // Transport failures only, 2s → 4s → 8s. A 4xx is the gateway's
            // answer and repeating the question will not change it; the
            // deterministic idempotency key is what makes a replayed
            // POST /payouts safe when the connection itself dropped.
            ->retry(3, fn (int $attempt): int => (int) min(2000 * (2 ** ($attempt - 1)), 8000),
                fn (Throwable $e): bool => $e instanceof ConnectionException,
                throw: false);
    }

    /**
     * Evidence, not a precondition: a failure to write the event row must not
     * turn a successful gateway call into an exception.
     *
     * @param  array<string, mixed>  $payload  already scrubbed by the caller
     */
    private function record(
        string $operation,
        ?PayoutLineItem $line,
        ?int $httpStatus,
        ?string $gatewayPayoutId,
        int $startedNs,
        array $payload,
        ?string $error,
    ): void {
        $durationMs = (int) ((hrtime(true) - $startedNs) / 1_000_000);

        try {
            PayoutGatewayEvent::create([
                'payout_line_item_id' => $line?->id,
                'payout_batch_id' => $line?->payout_batch_id,
                'gateway' => PayoutGatewayEvent::GATEWAY_RAZORPAYX,
                'direction' => PayoutGatewayEvent::DIRECTION_OUTBOUND,
                'event_type' => $operation,
                'gateway_payout_id' => $gatewayPayoutId,
                'signature_verified' => false,
                'http_status' => $httpStatus,
                'duration_ms' => $durationMs,
                'payload' => $payload,
                'error' => $error,
            ]);
        } catch (Throwable $e) {
            Log::channel('payments')->error('payout_gateway_events write failed', [
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }

        Log::channel('payments')->{$error === null ? 'info' : 'warning'}('razorpayx '.$operation, [
            'operation' => $operation,
            'payout_line_item_id' => $line?->id,
            'gateway_payout_id' => $gatewayPayoutId,
            'http_status' => $httpStatus,
            'duration_ms' => $durationMs,
            'error' => $error,
            'payload' => $payload,
        ]);
    }

    private function contactName(Distributor $distributor): string
    {
        $name = trim((string) ($distributor->user?->full_name ?? ''));

        // Razorpay rejects an empty contact name; the ADN is a stable, valid
        // stand-in for the vanishingly rare distributor with no name on file.
        return $name !== '' ? mb_substr($name, 0, 50) : (string) $distributor->adn;
    }
}
