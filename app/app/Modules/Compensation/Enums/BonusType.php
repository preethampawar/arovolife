<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Enums;

/**
 * The seven Arovolife bonus streams.
 *
 * Single typed list of every bonus the compensation engine pays. The string
 * value is the settings-key suffix used for per-bonus toggles, e.g.
 * `comp.admin_charge.applies_to_{value}`. Each case also declares its own
 * deduction conventions (rounding direction + TDS base) so BonusDeductionService
 * computes every stream exactly the way KP's plan specifies.
 */
enum BonusType: string
{
    case Gsb = 'gsb';
    case Mentorship = 'mb';
    case Rank = 'rank';
    case GrowthBooster = 'gbb';
    case Fortune = 'fortune';
    case Arete = 'adc';
    // No payout engine yet (Lifetime Awards ships in a later phase). The case
    // and its `applies_to_awards` toggle exist for forward-config only and have
    // no runtime effect until that engine reads BonusType::LifetimeAwards.
    case LifetimeAwards = 'awards';

    /**
     * Whether the admin charge is floored (truncated) rather than rounded.
     * Only Rank Bonus floors; every other stream rounds to the nearest paise
     * (preserves the historical per-engine behaviour — do not change without
     * a payout-impact review + KP sign-off).
     */
    public function adminChargeUsesFloor(): bool
    {
        return $this === self::Rank;
    }

    /**
     * Whether TDS is charged on the full gross (true) or on gross minus the
     * admin charge (false). Only Rank Bonus uses gross; the others use the
     * post-admin amount.
     */
    public function tdsOnGross(): bool
    {
        return $this === self::Rank;
    }
}
