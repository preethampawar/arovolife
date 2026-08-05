<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

/**
 * One rung of the distributor-facing GSB slab ladder.
 *
 * progressPaise mirrors what tonight's cut-off would measure against this
 * slab's threshold: slab 1 uses the lifetime-accumulated matched BV (capped by
 * the other side, since 15K/15K needs both sides), slabs 2-7 use today's fresh
 * matched BV only.
 *
 * leftProgressPaise/rightProgressPaise split that same measurement per side, so
 * min(left, right) always equals progressPaise: the slab-1 weaker carry-forward
 * is folded into whichever side is currently weaker (tie ⇒ Right is weaker,
 * because the engine treats Left as the power side).
 */
final readonly class GsbSlabRow
{
    public function __construct(
        public int $slab,
        public string $titleRequired,
        public int $titleMinBvPaise,
        public int $matchedBvPaise,
        public int $earnedCount,
        public bool $lockedByTitle,
        public bool $isNext,
        public int $progressPaise,
        public int $remainingPaise,
        /** Left group BV counting toward this slab (slab 1 adds the weaker CF when Left is weaker). */
        public int $leftProgressPaise = 0,
        /** Right group BV counting toward this slab (slab 1 adds the weaker CF when Right is weaker). */
        public int $rightProgressPaise = 0,
    ) {}
}
