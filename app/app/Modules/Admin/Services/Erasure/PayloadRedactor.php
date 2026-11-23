<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Erasure;

/**
 * Strips personal data from a stored gateway payload (Razorpay, Shiprocket)
 * while keeping its shape, ids and amounts. Any key on the list, at any depth
 * and in any letter case, has its whole value replaced.
 */
final class PayloadRedactor
{
    public const REPLACEMENT = '[erased]';

    private const KEYS = [
        'email', 'contact', 'phone', 'mobile', 'name', 'first_name', 'last_name',
        'customer_name', 'address', 'line1', 'line2', 'city', 'pincode', 'zip',
        'postal_code', 'vpa', 'card', 'bank_account', 'account_number', 'notes',
        'customer_details', 'shipping_address', 'billing_address',
    ];

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::KEYS, true)) {
                $payload[$key] = self::REPLACEMENT;
            } elseif (is_array($value)) {
                $payload[$key] = $this->redact($value);
            }
        }

        return $payload;
    }
}
