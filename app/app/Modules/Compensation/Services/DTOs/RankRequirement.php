<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

/**
 * One published condition of a rank, measured against the distributor's own
 * current figures: "Left Genos BV this month — 12,000 of 15,000".
 *
 * `unit` decides how the view renders the numbers: 'bv' (paise → BV),
 * 'count' (plain integer) or 'people' (plain integer, per-side structure).
 */
final readonly class RankRequirement
{
    public function __construct(
        public string $label,
        public int $current,
        public int $required,
        public string $unit = 'count',
        public ?string $note = null,
    ) {}

    public function met(): bool
    {
        return $this->current >= $this->required;
    }

    /** Progress toward the requirement, 0-100, capped. */
    public function percent(): float
    {
        if ($this->required <= 0) {
            return 100.0;
        }

        return min(100.0, round($this->current / $this->required * 100, 1));
    }
}
