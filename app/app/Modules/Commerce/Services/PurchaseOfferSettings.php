<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use Illuminate\Support\Facades\DB;

/**
 * Tunable parameters for the two purchase offers (KP 2026-06-26).
 *
 * BV is stored in paise at 100 paise to the BV, so 1,000 BV is 100,000 paise
 * and 3,000 BV is 300,000 — the same convention the compensation settings use.
 */
final class PurchaseOfferSettings
{
    private const SCALAR_DEFAULTS = [
        // Activation: 3,000 BV lifetime personal purchases (Retailer).
        'offers.activation_bv_paise' => 300_000,
        // 1,000 BV repurchased in the month.
        'offers.qualifying_bv_paise' => 100_000,
        // Six consecutive qualifying months complete a cycle.
        'offers.cycle_months' => 6,
        // 20% of the cycle's BV as points.
        'offers.cycle_rate_bp' => 2000,
        // A further 10% of the year on completing twelve consecutive months.
        'offers.full_year_bonus_rate_bp' => 1000,
        // Half the distributor price on the announced product.
        'offers.half_price_rate_bp' => 5000,
        // How long a half-price grant stays usable, in months beyond the month
        // it was earned in.
        'offers.half_price_validity_months' => 1,
    ];

    /** @var array<string, mixed>|null */
    private ?array $scalarCache = null;

    public function activationBvPaise(): int
    {
        return $this->scalarInt('offers.activation_bv_paise');
    }

    public function qualifyingBvPaise(): int
    {
        return $this->scalarInt('offers.qualifying_bv_paise');
    }

    public function cycleMonths(): int
    {
        return max(1, $this->scalarInt('offers.cycle_months'));
    }

    public function cycleRateBp(): int
    {
        return $this->scalarInt('offers.cycle_rate_bp');
    }

    public function fullYearBonusRateBp(): int
    {
        return $this->scalarInt('offers.full_year_bonus_rate_bp');
    }

    public function halfPriceRateBp(): int
    {
        return $this->scalarInt('offers.half_price_rate_bp');
    }

    public function halfPriceValidityMonths(): int
    {
        return max(0, $this->scalarInt('offers.half_price_validity_months'));
    }

    /**
     * The offer price of a variant, from its distributor price.
     */
    public function offerPricePaise(int $distributorPricePaise): int
    {
        return intdiv($distributorPricePaise * $this->halfPriceRateBp(), 10_000);
    }

    private function scalarInt(string $key): int
    {
        $value = $this->scalar($key);

        return $value !== null ? (int) $value : (int) (self::SCALAR_DEFAULTS[$key] ?? 0);
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
