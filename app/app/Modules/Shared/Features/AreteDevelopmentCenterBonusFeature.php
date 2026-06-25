<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates the Arete Development Center Bonus (Phase 7) feature across
 * admin and distributor views.
 *
 * Default: `false` (hidden). Activate from /admin/feature-flags once
 * partners have signed off on the plan.
 *
 * Resolved via:
 *     Feature::active(AreteDevelopmentCenterBonusFeature::class)
 */
final class AreteDevelopmentCenterBonusFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
