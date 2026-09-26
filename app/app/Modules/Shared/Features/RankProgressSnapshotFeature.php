<?php

declare(strict_types=1);

namespace App\Modules\Shared\Features;

/**
 * Gates the nightly rank progress snapshot and every surface that reads it:
 * live Rank 3–9 partner counts on the distributor's rank progress, the
 * "as of" note, and the admin's provisional standing panel.
 *
 * Default: `false`. Killswitch: turn it off at /admin/feature-flags — the
 * snapshot stops, the pages fall back to recorded ranks only, and no trace
 * of it remains in the UI.
 *
 * Resolved via:
 *     Feature::active(RankProgressSnapshotFeature::class)
 */
final class RankProgressSnapshotFeature
{
    public function resolve(mixed $scope): bool
    {
        return false;
    }
}
