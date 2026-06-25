<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates the Growth Booster Bonus (Phase 4 Part 2) feature across
 * admin and distributor views. Also guards the monthly artisan command.
 *
 * Default: `false` (hidden). Activate from /admin/feature-flags once
 * partners have signed off on the plan.
 *
 * Resolved via:
 *     Feature::active(GrowthBoosterBonusFeature::class)
 */
final class GrowthBoosterBonusFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
