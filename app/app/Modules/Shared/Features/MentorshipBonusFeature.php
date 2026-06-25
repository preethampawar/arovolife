<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates the Mentorship Bonus feature across admin and distributor views.
 *
 * Default: `false` (hidden). Activate from /admin/feature-flags once
 * partners have signed off on the plan.
 *
 * Resolved via:
 *     Feature::active(MentorshipBonusFeature::class)
 */
final class MentorshipBonusFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
