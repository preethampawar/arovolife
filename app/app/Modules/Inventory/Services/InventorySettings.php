<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Tunable parameters for the inventory module, read from the `settings` table
 * with the built-in defaults as the fallback — the same scalar pattern as
 * `PurchaseOfferSettings`.
 */
final class InventorySettings
{
    public const KEY_DEFAULT_WAREHOUSE = 'inventory.default_warehouse_code';

    public const KEY_EXPIRY_ALERT_DAYS = 'inventory.expiry_alert_days';

    public const KEY_ALERT_EMAIL = 'inventory.alert_email';

    public const KEY_ENFORCE_AVAILABILITY = 'inventory.enforce_availability';

    /** @var array<string, string> */
    private const SCALAR_DEFAULTS = [
        self::KEY_DEFAULT_WAREHOUSE => Warehouse::DEFAULT_CODE,
        self::KEY_EXPIRY_ALERT_DAYS => '90',
        self::KEY_ALERT_EMAIL => '',
        // Whether the checkout availability check blocks an order. Read
        // together with InventoryFeature — the flag is the killswitch, this is
        // the ops-level toggle underneath it.
        self::KEY_ENFORCE_AVAILABILITY => '1',
    ];

    /** @var array<string, mixed>|null */
    private ?array $scalarCache = null;

    public function defaultWarehouseCode(): string
    {
        $code = trim((string) $this->scalarOrDefault(self::KEY_DEFAULT_WAREHOUSE));

        return $code !== '' ? $code : Warehouse::DEFAULT_CODE;
    }

    public function expiryAlertDays(): int
    {
        return max(1, (int) $this->scalarOrDefault(self::KEY_EXPIRY_ALERT_DAYS));
    }

    public function alertEmail(): ?string
    {
        $email = trim((string) $this->scalarOrDefault(self::KEY_ALERT_EMAIL));

        return $email !== '' ? $email : null;
    }

    public function enforceAvailability(): bool
    {
        return (string) $this->scalarOrDefault(self::KEY_ENFORCE_AVAILABILITY) === '1';
    }

    private function scalarOrDefault(string $key): string
    {
        $value = $this->scalar($key);

        return $value ?? (self::SCALAR_DEFAULTS[$key] ?? '');
    }

    private function scalar(string $key): ?string
    {
        if ($this->scalarCache === null) {
            $this->scalarCache = DB::table('settings')->pluck('value', 'key')->all();
        }

        $value = $this->scalarCache[$key] ?? null;

        return $value === null ? null : (string) $value;
    }
}
