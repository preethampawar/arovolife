<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

/**
 * Allow-list scrubber for everything Razorpay sends or returns.
 *
 * Applied before a payload is written to `payment_events`, to
 * `payment_intents.raw_payload`, or to the `payments` log channel — there is
 * no raw copy anywhere (hard rule 8; DPDP 2023 §4, §8(7)). An allow-list,
 * not a deny-list: a new field Razorpay starts sending tomorrow is dropped
 * by default rather than stored by accident.
 *
 * What is kept and why:
 *   - gateway identifiers, amounts, currency, status, method, error fields,
 *     timestamps — the transactional record;
 *   - `card.last4` / `card.network` / issuer code — enough to tell the buyer
 *     which card was charged, and not cardholder data under PCI-DSS SAQ-A;
 *   - `acquirer_data.rrn`, `auth_code`, `arn`, `upi_transaction_id`,
 *     `bank_transaction_id` — the reference numbers a buyer's bank asks for
 *     when they say "the refund never arrived". Transaction references, not
 *     identifiers of a person; needed for grievance redressal (DSR Rule 12).
 *
 * What is dropped, always: `card.name`, `email`, `contact`, `vpa`,
 * `bank_account`, `token_id`, `customer_id`, and anything not named below.
 * We already hold the buyer's contact details on the order.
 */
final class RazorpayPayloadScrubber
{
    /** Scalar keys kept wherever they appear. */
    private const ALLOWED_KEYS = [
        'id', 'entity', 'event', 'account_id', 'created_at', 'updated_at',
        'amount', 'currency', 'status', 'order_id', 'payment_id', 'refund_id', 'invoice_id',
        'international', 'method', 'amount_refunded', 'refund_status', 'captured',
        'description', 'receipt', 'attempts', 'amount_paid', 'amount_due', 'expire_by',
        'error_code', 'error_description', 'error_source', 'error_step', 'error_reason',
        'code', 'reason', 'source', 'step', 'field',
        'speed_processed', 'speed_requested', 'batch_id', 'fee', 'tax',
        // card — never the holder's name
        'last4', 'network', 'type', 'sub_type', 'issuer', 'emi',
        // acquirer references — refund tracing and grievance evidence
        'rrn', 'auth_code', 'arn', 'authentication_reference_number',
        'upi_transaction_id', 'bank_transaction_id',
        // netbanking bank code / wallet name (a code, not an account)
        'bank', 'wallet', 'flow',
        // our own notes: only the keys we ourselves set
        'arovolife_order_id', 'arovolife_order_no',
        'count',
    ];

    /** Keys whose value is an object we descend into. */
    private const ALLOWED_CONTAINERS = [
        'payload', 'payment', 'refund', 'order', 'entity', 'error',
        'card', 'acquirer_data', 'notes', 'items', 'upi', 'metadata', 'contains',
    ];

    /**
     * Containers with a narrower key set than the global one. The card
     * object gets no `id`: a saved-card token identifies a stored instrument
     * and has no use to us.
     */
    private const CONTAINER_KEYS = [
        'card' => ['last4', 'network', 'type', 'sub_type', 'issuer', 'international', 'emi'],
    ];

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function scrub(array $payload): array
    {
        return $this->walk($payload, null);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private function walk(array $node, ?string $parentKey): array
    {
        $allowedKeys = self::CONTAINER_KEYS[$parentKey ?? ''] ?? self::ALLOWED_KEYS;

        // A list (e.g. `contains: ["payment"]`, `items: [...]`): keep scalars,
        // recurse into arrays. The list itself was admitted by its parent key.
        if (array_is_list($node)) {
            $out = [];
            foreach ($node as $value) {
                if (is_array($value)) {
                    $out[] = $this->walk($value, $parentKey);
                } elseif (is_scalar($value) || $value === null) {
                    $out[] = $value;
                }
            }

            return $out;
        }

        $out = [];
        foreach ($node as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (is_array($value)) {
                if (in_array($key, self::ALLOWED_CONTAINERS, true)) {
                    $out[$key] = $this->walk($value, $key);
                }

                continue;
            }

            if (in_array($key, $allowedKeys, true) && (is_scalar($value) || $value === null)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
