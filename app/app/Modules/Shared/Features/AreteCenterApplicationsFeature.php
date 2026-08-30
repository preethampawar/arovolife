<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates the distributor-side Arete Development Centre application flow and
 * the admin review queue that approves it.
 *
 * Default: `false`. While off the feature leaves no trace: no "Apply for an
 * Arete Development Centre" menu item, no application routes (404), no
 * review queue in the admin console, and the `adc.min_premises_sqft` setting
 * is hidden. The admin centre registry itself (add / edit / phase tracking)
 * is NOT gated by this flag — company centres exist before any distributor
 * applies to open one.
 *
 * Resolved via:
 *     Feature::active(AreteCenterApplicationsFeature::class)
 */
final class AreteCenterApplicationsFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
