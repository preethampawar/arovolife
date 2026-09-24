<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Support;

/**
 * Allow-list scrubber for everything sent to or received from Shiprocket.
 *
 * Applied before a payload is written to `shipment_events` — there is no raw
 * copy anywhere. A booking carries the consignee's name, phone and full
 * address, so this is the same rule as `RazorpayPayloadScrubber` (hard rule 8
 * by analogy; DPDP 2023 §8(7)): an allow-list, so a field Shiprocket starts
 * sending tomorrow is dropped by default rather than stored by accident.
 *
 * Kept: Shiprocket's identifiers, the AWB and courier, statuses, the parcel's
 * weight and size, and the order lines' product name/SKU/units/price — the
 * record of what was booked. Dropped, always: every `billing_*` / `shipping_*`
 * field, email, phone, pincode, the password and the token. `name` is kept only
 * inside `order_items`, where it is the product name; anywhere else Shiprocket
 * uses it for a person.
 *
 * Free text is kept only after `sanitise()`: validation errors and courier
 * messages can echo the input they are complaining about.
 */
final class ShiprocketPayloadScrubber
{
    /** Scalar keys kept wherever they appear. */
    private const ALLOWED_KEYS = [
        'id', 'order_id', 'channel_order_id', 'shipment_id', 'status', 'status_code',
        'awb', 'awb_code', 'awb_assign_status', 'courier', 'courier_company_id', 'courier_name',
        'label_url', 'label_created', 'pickup_status', 'pickup_scheduled_date', 'pickup_token_number',
        'pickup_location', 'payment_method', 'sub_total', 'weight', 'length', 'breadth', 'height',
        'order_date', 'current_status', 'shipment_status', 'onboarding_completed_now',
        'count', 'track_status', 'is_return',
        // Tracking webhook. `scans` is deliberately absent: its locations
        // trace the parcel to the buyer's door.
        'current_status_id', 'shipment_status_id', 'current_timestamp', 'sr_order_id', 'etd',
        // Courier quotes. Never the pickup or delivery pincode.
        'recommended_courier_company_id', 'shiprocket_recommended_courier_id', 'courier_id',
    ];

    /** Scalar keys kept only after sanitising, because they are free text. */
    private const SANITISED_KEYS = ['message', 'awb_assign_error'];

    /** Keys whose value is an object or list we descend into. */
    private const ALLOWED_CONTAINERS = [
        'response', 'data', 'order_items', 'tracking_data', 'shipment_track', 'shipments',
        'available_courier_companies',
    ];

    /** Containers with a narrower key set than the global one. */
    private const CONTAINER_KEYS = [
        'order_items' => ['name', 'sku', 'units', 'selling_price'],
        'available_courier_companies' => ['courier_company_id', 'courier_name', 'rate', 'estimated_delivery_days'],
    ];

    private const MAX_TEXT = 200;

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function scrub(array $payload): array
    {
        return $this->walk($payload, null);
    }

    /**
     * Strip anything that could be an email, phone number, pincode or
     * AWB-like identifier from free text — including spaced or hyphenated
     * forms like "98123 45678" — and bound its length.
     */
    public function sanitise(string $text): string
    {
        $text = (string) preg_replace('/\S+@\S+/', '#', $text);
        $text = (string) preg_replace('/\+?\d[\d\s\-]{4,}\d/', '#', $text);

        return mb_substr(trim($text), 0, self::MAX_TEXT);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private function walk(array $node, ?string $parentKey): array
    {
        $allowedKeys = self::CONTAINER_KEYS[$parentKey ?? ''] ?? self::ALLOWED_KEYS;

        if (array_is_list($node)) {
            $out = [];
            foreach ($node as $value) {
                if (is_array($value)) {
                    $out[] = $this->walk($value, $parentKey);
                } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                    // Lists of ids (`shipment_id: [123]`). Strings in a list
                    // are unlabelled, so there is no way to know they are safe.
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

            // Validation errors: keep which fields failed, never what was said
            // about them — the message can quote the rejected value.
            if ($key === 'errors' && is_array($value)) {
                $out['errors'] = array_fill_keys(array_filter(array_keys($value), 'is_string'), 'invalid');

                continue;
            }

            if (is_array($value)) {
                if (in_array($key, self::ALLOWED_CONTAINERS, true)) {
                    $out[$key] = $this->walk($value, $key);
                } elseif (in_array($key, $allowedKeys, true) && array_is_list($value)) {
                    // A list under a scalar key, e.g. `shipment_id: [123]`.
                    $out[$key] = $this->walk($value, $parentKey);
                }

                continue;
            }

            if (in_array($key, self::SANITISED_KEYS, true) && is_string($value)) {
                $out[$key] = $this->sanitise($value);

                continue;
            }

            if (in_array($key, $allowedKeys, true) && (is_scalar($value) || $value === null)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
