<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Events;

/**
 * Fired when an admin changes a live compensation-plan parameter (a gsb_slabs
 * row, a rank_tiers row, a fortune level/tier, or a `comp.*` scalar setting).
 *
 * Carries the area that changed and the identifying key so listeners can bust
 * caches, snapshot the plan version, or notify finance/compliance. Audit
 * logging is handled separately by the controller; this event is the
 * domain-level signal that the plan changed.
 */
final class CompensationPlanChanged
{
    public function __construct(
        public readonly string $area,   // e.g. 'gsb_slab', 'rank_tier', 'fortune_level', 'fortune_tier', 'scalar'
        public readonly string $key,    // e.g. the slab number, rank number, tier name, or setting key
        public readonly ?int $actorId = null,
    ) {}
}
