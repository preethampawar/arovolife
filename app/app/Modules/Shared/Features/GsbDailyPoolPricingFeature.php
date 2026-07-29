<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates the GSB pro-rated daily pool pricing for slabs 3–7 (KP 2026-07-29).
 *
 * Default: `false` (off). When OFF the daily cut-off writes no gsb_daily_pools
 * rows and every slab pays its fixed gsb_slabs.bonus_paise — byte-identical to
 * the pre-pool engine. When ON, slabs 1–2 stay fixed and slabs 3–7 are priced
 * at the day's frozen variable score value (45% pool ÷ total slab 3–7 scores,
 * capped at the fixed score value).
 *
 * History is safe in either direction: earned days are frozen on
 * gsb_daily_pools + gsb_cutoff_results snapshots, and pre-flag dates simply
 * have no pool row (legacy pricing). Activate from /admin/feature-flags.
 *
 * ⚠ COMPLIANCE GATE (risk register R-33): this changes the slab 3–7 payout
 * formula. DSA §6.2 requires 30 days' written notice to all active
 * Distributors and publication of the formula at /p/compensation BEFORE this
 * flag is enabled in any environment that pays real distributors.
 *
 * Resolved via:
 *     Feature::active(GsbDailyPoolPricingFeature::class)
 */
final class GsbDailyPoolPricingFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
