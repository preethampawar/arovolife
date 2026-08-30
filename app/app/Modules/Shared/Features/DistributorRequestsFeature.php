<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates the distributor-side "Distributor requests" form (name correction,
 * name change, date-of-birth correction, membership transfer to an immediate
 * blood relation, ID cancellation) and the admin review queue for it.
 *
 * Default: `false`. While off the feature leaves no trace: no menu item, no
 * routes (404), no queue in the admin console.
 *
 * Resolved via:
 *     Feature::active(DistributorRequestsFeature::class)
 */
final class DistributorRequestsFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
