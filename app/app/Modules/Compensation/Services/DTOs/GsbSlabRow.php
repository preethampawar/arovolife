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
    ) {}
}
