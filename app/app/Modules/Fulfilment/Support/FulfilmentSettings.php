<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Support;

use App\Modules\Fulfilment\Models\Shipment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The business levers for dispatch, read from the `settings` table where the
 * admin console edits them. Credentials never live here — see
 * `config/arovolife.php` `fulfilment.shiprocket`.
 *
 * Deliberately does NOT read `commerce.collection_fee_rupees`. That key belongs
 * to `ShippingService`, which is the single source of truth for what a buyer
 * is charged; a second reader is how the cart, the checkout summary and the
 * order total drift apart.
 */
final class FulfilmentSettings
{
    public const KEY_DEFAULT_ROUTE = 'fulfilment.default_route';

    public const KEY_SHIPROCKET_ENABLED = 'fulfilment.shiprocket.enabled';

    public const KEY_SHIPROCKET_PICKUP_LOCATION = 'fulfilment.shiprocket.pickup_location';

    /** @var array<string, string|null>|null */
    private ?array $cache = null;

    /**
     * Which route the dispatch queue pre-selects. Never which route is used —
     * an operator chooses that per order (plan AD-8).
     */
    public function defaultRoute(): string
    {
        return $this->raw(self::KEY_DEFAULT_ROUTE) === Shipment::GATEWAY_SHIPROCKET
            ? Shipment::GATEWAY_SHIPROCKET
            : Shipment::GATEWAY_MANUAL;
    }

    /** Absent means off. There is no account yet, so off is also the truthful default. */
    public function shiprocketEnabled(): bool
    {
        return $this->raw(self::KEY_SHIPROCKET_ENABLED) === 'true';
    }

    public function shiprocketPickupLocation(): string
    {
        return trim((string) ($this->raw(self::KEY_SHIPROCKET_PICKUP_LOCATION) ?? ''));
    }

    private function raw(string $key): ?string
    {
        if ($this->cache === null) {
            try {
                /** @var array<string, string|null> $rows */
                $rows = DB::table('settings')
                    ->whereIn('key', [
                        self::KEY_DEFAULT_ROUTE,
                        self::KEY_SHIPROCKET_ENABLED,
                        self::KEY_SHIPROCKET_PICKUP_LOCATION,
                    ])
                    ->pluck('value', 'key')
                    ->all();
                $this->cache = $rows;
            } catch (QueryException) {
                // No settings table yet (a fresh install mid-migration). Every
                // lever falls back to its safe default, which for the courier
                // flag is "off" and for the route is "manual".
                $this->cache = [];
            }
        }

        $value = $this->cache[$key] ?? null;

        return $value === null ? null : (string) $value;
    }
}
