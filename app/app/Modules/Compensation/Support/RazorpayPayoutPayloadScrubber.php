<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

/**
 * Allow-list scrubber for everything the RazorpayX Payouts API sends or
 * returns.
 *
 * Applied before a payload is written to `payout_gateway_events.payload` or
 * to the `payments` log channel — there is no raw copy anywhere (hard rule 8;
 * DPDP 2023 §4, §8(7)). An allow-list, not a deny-list: a field Razorpay
 * starts sending tomorrow is dropped by default rather than stored by
 * accident.
 *
 * The payouts API is far more dangerous than the checkout API, because the
 * request bodies we send it CONTAIN the distributor's bank account number,
 * IFSC and name. None of those may ever reach a log line or a database row:
 * `account_number`, `ifsc`, `bank_name`, `name`, `email`, `contact` and
 * `debit_account_number` are absent from every list below, so they are
 * dropped wherever they appear and at any depth.
 *
 * What is kept: gateway identifiers (`id`, `contact_id`, `fund_account_id`),
 * the transactional record (amount, currency, mode, status, UTR, our own
 * `reference_id` and narration), and error fields. Those are exactly what
 * an admin needs to answer "where did this transfer go and why did it fail".
 */
final class RazorpayPayoutPayloadScrubber
{
    /** Scalar keys kept wherever they appear. */
    private const ALLOWED_KEYS = [
        // Identity of the record, never of the person
        'id', 'entity', 'event', 'account_id', 'batch_id',
        'contact_id', 'fund_account_id', 'account_type',
        'created_at', 'updated_at', 'processed_at', 'scheduled_at',
        // The transfer itself
        'amount', 'currency', 'status', 'mode', 'purpose', 'utr',
        'reference_id', 'narration', 'fees', 'tax', 'queue_if_low_balance',
        'status_details', 'failure_reason', 'type', 'active',
        // Error reporting
        'code', 'description', 'source', 'step', 'reason', 'field',
        'error_code', 'error_description',
        // Our own notes: only the keys we ourselves set
        'adn', 'payout_batch_id', 'distributor_id',
        'count', 'items',
    ];

    /** Keys whose value is an object we descend into. */
    private const ALLOWED_CONTAINERS = [
        'payload', 'payout', 'contact', 'fund_account', 'transaction',
        'entity', 'error', 'metadata', 'notes', 'items', 'contains',
        'status_details',
    ];

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function scrub(array $payload): array
    {
        return $this->walk($payload);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private function walk(array $node): array
    {
        // A list (e.g. `items: [...]`, `contains: ["payout"]`): keep scalars,
        // recurse into arrays. The list itself was admitted by its parent key.
        if (array_is_list($node)) {
            $out = [];
            foreach ($node as $value) {
                if (is_array($value)) {
                    $out[] = $this->walk($value);
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
                // `bank_account` is deliberately not a container: dropping the
                // whole object is what keeps the account number out of the
                // event trail even if Razorpay renames the field inside it.
                if (in_array($key, self::ALLOWED_CONTAINERS, true)) {
                    $out[$key] = $this->walk($value);
                }

                continue;
            }

            if (in_array($key, self::ALLOWED_KEYS, true) && (is_scalar($value) || $value === null)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
