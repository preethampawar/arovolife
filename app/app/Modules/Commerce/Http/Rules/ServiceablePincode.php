<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Http\Rules;

use App\Modules\Commerce\Services\ShippingService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a delivery pincode we do not serve.
 *
 * The admin setting `commerce.shipping.india_mainland_only` promised that
 * orders are restricted to mainland Indian addresses, but nothing read it —
 * an order to Port Blair was accepted with the setting ON (QA F57). This rule
 * is the one place that enforcement lives, so the saved address book and the
 * checkout form cannot drift apart; {@see ShippingService::servesPincode()}
 * owns the ranges.
 *
 * Shipping addresses only. A billing address may legitimately sit anywhere.
 */
final class ServiceablePincode implements ValidationRule
{
    public function __construct(private readonly ShippingService $shipping) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (! $this->shipping->servesPincode($value)) {
            $fail('We cannot deliver to this pincode. arovolife currently ships to mainland India only — the Andaman & Nicobar Islands and Lakshadweep are not served. Please use a mainland delivery address or contact support.');
        }
    }
}
