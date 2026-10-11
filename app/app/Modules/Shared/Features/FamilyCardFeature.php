<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Master switch for the arovolife Family Card — the display-only card on the
 * distributor dashboard (name, ADN, "1 + 5 family members"), its printable
 * page, and the "Family members" section of My Profile where a distributor
 * registers up to five family members (name, relation, under-18 flag) with a
 * consent declaration and, for a child, a parent/guardian consent.
 *
 * Default: `true` (user decision 2026-10-11 — on by default, production
 * included). The card has no defined benefit yet, so family names are
 * collected as an accepted risk (client decision 2026-10-11, R-115); the
 * Privacy Policy revision that notices the collection (v2026-10-11) must be
 * published on every environment this runs on. This is a killswitch, not a
 * launch gate.
 *
 * While OFF the surface leaves no trace: no dashboard card, the printable page
 * and the add route 404, and My Profile shows no family section — except that
 * a distributor who already has family members registered can still see and
 * remove them (Privacy Policy §10.3, erasure on request). Rows are retained
 * while the flag is off; termination and cooling-off erasure run regardless.
 *
 * Resolved at runtime via:
 *     Feature::for(null)->active(FamilyCardFeature::class)
 *
 * The class name is the Pennant feature key; do not refactor without also
 * updating the existing flag row in the `features` table.
 */
final class FamilyCardFeature
{
    /** Pennant resolver — global scope, defaults to enabled. */
    public function resolve(mixed $scope): bool
    {
        return true;
    }
}
