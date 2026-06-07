<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\CustomerAddress;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

/**
 * Owns the distributor's saved shipping-address book. The single place that
 * creates/updates labelled addresses and keeps exactly one default per
 * customer, so checkout and the "My Addresses" page never drift.
 *
 * Shipping only (product decision) — billing keeps its own "on file" default
 * handled inline by {@see CheckoutService}.
 */
final class CustomerAddressService
{
    /** Quick-pick labels offered in the UI; a custom label is also allowed. */
    public const PRESET_LABELS = ['Home', 'Work', 'Office'];

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * The customer's saved shipping addresses, default first.
     *
     * @return Collection<int, CustomerAddress>
     */
    public function forCustomer(int $customerId): Collection
    {
        return CustomerAddress::query()
            ->where('customer_id', $customerId)
            ->shipping()
            ->get();
    }

    /**
     * Create or update a labelled shipping address. De-dupes on the label (a
     * second "Home" updates the first); a blank label de-dupes on line1+pincode
     * so repeat checkouts of the same address don't pile up. The first saved
     * address — or one explicitly flagged — becomes the default.
     *
     * @param  array<string, mixed>  $data  keys: name, phone, line1, line2, city, state, pincode
     */
    public function save(int $customerId, array $data, ?string $label = null, bool $makeDefault = false): CustomerAddress
    {
        $label = $this->normaliseLabel($label);

        return $this->db->transaction(function () use ($customerId, $data, $label, $makeDefault): CustomerAddress {
            $query = CustomerAddress::query()
                ->where('customer_id', $customerId)
                ->where('kind', CustomerAddress::KIND_SHIPPING);

            $existing = $label !== null
                ? (clone $query)->where('label', $label)->first()
                : (clone $query)->whereNull('label')->where('line1', $data['line1'])->where('pincode', $data['pincode'] ?? '')->first();

            $hasDefault = (clone $query)->where('is_default', true)->exists();
            $shouldDefault = $makeDefault || ! $hasDefault;

            $attributes = [
                'kind' => CustomerAddress::KIND_SHIPPING,
                'label' => $label,
                'name' => $data['name'] ?? '',
                'phone_e164' => $data['phone'] ?? '',
                'line1' => $data['line1'],
                'line2' => $data['line2'] ?? null,
                'city' => $data['city'] ?? '',
                'state' => $data['state'] ?? '',
                'pincode' => $data['pincode'] ?? '',
                'country' => 'IN',
            ];

            if ($existing !== null) {
                $existing->update($attributes);
                $address = $existing;
            } else {
                $address = CustomerAddress::create(['customer_id' => $customerId] + $attributes);
            }

            if ($shouldDefault) {
                $this->makeDefault($customerId, $address);
            }

            return $address->fresh();
        });
    }

    /** Mark $addressId the customer's default shipping address (unsets others). */
    public function setDefault(int $customerId, CustomerAddress $address): void
    {
        $this->db->transaction(function () use ($customerId, $address): void {
            $this->makeDefault($customerId, $address);
        });
    }

    /**
     * Delete a saved address. If it was the default and others remain, promote
     * the next one so the customer always has a default to fall back on.
     */
    public function delete(int $customerId, CustomerAddress $address): void
    {
        $this->db->transaction(function () use ($customerId, $address): void {
            $wasDefault = $address->is_default;
            $address->delete();

            if ($wasDefault) {
                $next = CustomerAddress::query()
                    ->where('customer_id', $customerId)
                    ->shipping()
                    ->first();
                $next?->update(['is_default' => true]);
            }
        });
    }

    private function makeDefault(int $customerId, CustomerAddress $address): void
    {
        CustomerAddress::query()
            ->where('customer_id', $customerId)
            ->where('kind', CustomerAddress::KIND_SHIPPING)
            ->whereKeyNot($address->id)
            ->update(['is_default' => false]);

        $address->update(['is_default' => true]);
    }

    /**
     * Trim a label to a sane value; empty becomes null (an unlabelled address).
     */
    private function normaliseLabel(?string $label): ?string
    {
        $label = trim((string) $label);

        return $label === '' ? null : mb_substr($label, 0, 40);
    }
}
