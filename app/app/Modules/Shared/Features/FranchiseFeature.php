<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates the franchise programme — the entity, the checkout picker, the admin
 * screens and the 3% commission engine — across every surface.
 *
 * Default: `false`. While off the feature leaves no trace anywhere: no
 * collection-point step at checkout, no menu item, no settings keys, no
 * distributor-facing mention.
 *
 * Two gates must clear before it is switched on in production:
 *
 *  1. the DSA §6.2 thirty-day written notice, because the commission is a new
 *     earning stream in the compensation plan; and
 *  2. R-24 — a written legal-counsel opinion on the combined binary-tree plus
 *     franchise surface. The Product Owner authorised the build on
 *     2026-08-16; that authorises writing the code, not paying the money.
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
