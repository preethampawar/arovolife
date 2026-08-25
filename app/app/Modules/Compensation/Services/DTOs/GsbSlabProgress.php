<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

/**
 * Snapshot of a distributor's position on the GSB slab ladder: today's
 * effective Left/Right group BV (daily accumulator + carry-forward on its
 * side) plus one GsbSlabRow per active slab. Personal-purchase BV that the
 * 23:59 cut-off has yet to credit is carried separately in
 * pendingPersonalBvTopupPaise and is never folded into these figures.
 * Left/right figures are zero when the distributor is below the personal-BV
 * minimum — the cut-off would discard them, so the ladder must not display
 * them either.
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
        /**
         * Personal BV waiting for tonight's cut-off (paise). Deliberately NOT part of
         * left/right effective: personal purchases join the weaker leg only when the
         * 23:59 cut-off credits them, never at purchase time.
         */
        public int $pendingPersonalBvTopupPaise = 0,
        /** Side the pending topup is expected to land on ('L', 'R', or null). */
        public ?string $pendingTopupSide = null,
        /** Lifetime weaker-side BV accumulating toward slab 1 (paise); side-less by design. */
        public int $slab1WeakerCfPaise = 0,
        /** Power-side carry-forward already folded into the effective figures (paise). */
        public int $powerCfPaise = 0,
        /** Side the power carry-forward sits on ('L', 'R', or null when there is none). */
        public ?string $powerCfSide = null,
    ) {}

    /**
     * Carry-forward that opened the day on the Left side — the part of
     * leftEffectivePaise that did not arrive today.
     */
    public function carriedLeftPaise(): int
    {
        return $this->powerCfSide === 'L' ? $this->powerCfPaise : 0;
    }

    /**
     * Carry-forward that opened the day on the Right side.
     */
    public function carriedRightPaise(): int
    {
        return $this->powerCfSide === 'R' ? $this->powerCfPaise : 0;
    }

    /**
     * The side tonight's cut-off will treat as stronger: the higher effective
     * figure, with the engine's Left-wins tie-break (the stored carry-forward
     * side breaks an exact tie when one exists).
     */
    public function powerSide(): string
    {
        if ($this->leftEffectivePaise === $this->rightEffectivePaise) {
            return $this->powerCfSide === 'R' ? 'R' : 'L';
        }

        return $this->leftEffectivePaise > $this->rightEffectivePaise ? 'L' : 'R';
    }

    /**
     * The side that will be matched against the slab table tonight.
     */
    public function weakerSide(): string
    {
        return $this->powerSide() === 'L' ? 'R' : 'L';
    }

    /**
     * Everything currently carried on one side: its power-side carry-forward
     * plus, on the weaker side only, the side-less slab-1 weaker accumulator
     * (which counts toward whichever side is weaker at the next cut-off).
     */
    public function totalCarriedPaise(string $side): int
    {
        $carried = $side === 'L' ? $this->carriedLeftPaise() : $this->carriedRightPaise();

        return $carried + ($side === $this->weakerSide() ? $this->slab1WeakerCfPaise : 0);
    }
}
