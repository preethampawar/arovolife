<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates the Franchise application flow (distributor-facing apply + admin review)
 * across all environments.
 *
 * Default: `false` (hidden). Two hard gates must be cleared before activating
 * in any environment:
 *   1. DSA §6.2 thirty-day written notice to existing distributors.
 *   2. Legal counsel opinion (R-24).
 *
 * Activate from /admin/feature-flags once both conditions are satisfied.
 *
 * Resolved via:
 *     Feature::active(FranchiseFeature::class)
 */
final class FranchiseFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
