<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

/**
 * Snapshot of a distributor's position on the GSB slab ladder: today's
 * effective Left/Right group BV (daily accumulator + carry-forward on its
 * side) plus one GsbSlabRow per active slab. Left/right figures are zero when
 * the distributor is below the personal-BV minimum — the cut-off would discard
 * them, so the ladder must not display them either.
 */
final readonly class GsbSlabProgress
{
    /**
     * @param  array<int, GsbSlabRow>  $rows
     */
    public function __construct(
        public array $rows,
        public bool $genosBvEligible,
        public int $gsbMinBvPaise,
        public int $leftEffectivePaise,
        public int $rightEffectivePaise,
        public ?string $title,
        public int $titleMaxSlab,
        public ?int $highestEarnedSlab,
        /** Personal BV already credited to the weaker leg as a topup today (paise). */
        public int $personalBvTopupPaise = 0,
        /** Which side the topup was applied to ('L', 'R', or null if none). */
        public ?string $topupSide = null,
    ) {}
}
