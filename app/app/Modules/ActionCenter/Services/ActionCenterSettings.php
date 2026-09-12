<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Services;

use Illuminate\Support\Facades\DB;

/**
 * The SLA clocks and the snooze cap (plan §3.3), read from the `settings`
 * table with the built-in defaults as the fallback — the same scalar pattern
 * as `InventorySettings`.
 *
 * The digest recipients key is deliberately absent: the daily digest was
 * deferred on 2026-09-12 (plan §8).
 */
final class ActionCenterSettings
{
    public const KEY_PACK_SLA_HOURS = 'action_center.pack_sla_hours';

    public const KEY_SHIP_SLA_HOURS = 'action_center.ship_sla_hours';

    public const KEY_DELIVERY_CHASE_DAYS = 'action_center.delivery_chase_days';

    public const KEY_TRANSFER_TRANSIT_DAYS = 'action_center.transfer_transit_days';

    public const KEY_GRN_DRAFT_DAYS = 'action_center.grn_draft_days';

    public const KEY_PO_OVERDUE_DAYS = 'action_center.po_overdue_days';

    public const KEY_KYC_REVIEW_HOURS = 'action_center.kyc_review_hours';

    public const KEY_MAX_SNOOZE_DAYS = 'action_center.max_snooze_days';

    /** @var array<string, string> */
    private const SCALAR_DEFAULTS = [
        self::KEY_PACK_SLA_HOURS => '24',
        self::KEY_SHIP_SLA_HOURS => '24',
        self::KEY_DELIVERY_CHASE_DAYS => '7',
        self::KEY_TRANSFER_TRANSIT_DAYS => '5',
        self::KEY_GRN_DRAFT_DAYS => '3',
        self::KEY_PO_OVERDUE_DAYS => '7',
        self::KEY_KYC_REVIEW_HOURS => '48',
        self::KEY_MAX_SNOOZE_DAYS => '30',
    ];

    /** @var array<string, mixed>|null */
    private ?array $scalarCache = null;

    public function packSlaHours(): int
    {
        return $this->positiveInt(self::KEY_PACK_SLA_HOURS);
    }

    public function shipSlaHours(): int
    {
        return $this->positiveInt(self::KEY_SHIP_SLA_HOURS);
    }

    public function deliveryChaseDays(): int
    {
        return $this->positiveInt(self::KEY_DELIVERY_CHASE_DAYS);
    }

    public function transferTransitDays(): int
    {
        return $this->positiveInt(self::KEY_TRANSFER_TRANSIT_DAYS);
    }

    public function grnDraftDays(): int
    {
        return $this->positiveInt(self::KEY_GRN_DRAFT_DAYS);
    }

    public function poOverdueDays(): int
    {
        return $this->positiveInt(self::KEY_PO_OVERDUE_DAYS);
    }

    public function kycReviewHours(): int
    {
        return $this->positiveInt(self::KEY_KYC_REVIEW_HOURS);
    }

    public function maxSnoozeDays(): int
    {
        return $this->positiveInt(self::KEY_MAX_SNOOZE_DAYS);
    }

    /** Drop the memoised read — used after a settings write in the same request. */
    public function flush(): void
    {
        $this->scalarCache = null;
    }

    private function positiveInt(string $key): int
    {
        $value = $this->scalar($key) ?? self::SCALAR_DEFAULTS[$key];

        return max(1, (int) $value);
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
