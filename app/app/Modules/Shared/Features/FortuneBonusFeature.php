<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates the Fortune Bonus (Phase 6) feature across admin and distributor views.
 *
 * Default: `false` (hidden). Activate from /admin/feature-flags once
 * partners have signed off on the plan.
 *
 * Resolved via:
 *     Feature::active(FortuneBonusFeature::class)
 */
final class FortuneBonusFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
