<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates the Genos Sales Bonus (GSB) — the foundational Phase-4 bonus.
 *
 * Default: `false` (off). When OFF, the gsb:daily-cutoff and gsb:weekly-payout
 * commands no-op, so no GSB (or the Mentorship Bonus computed alongside it) is
 * earned. Activate from /admin/feature-flags once partners sign off.
 *
 * Resolved via:
 *     Feature::active(GenosSalesBonusFeature::class)
 */
final class GenosSalesBonusFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
