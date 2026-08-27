<?php

declare(strict_types=1);

namespace App\Modules\Analytics\DTOs;

/**
 * One milestone in a funnel, with how much of the previous step reached it.
 *
 * `shareOfPrevious` is the number worth looking at — it isolates where the
 * drop happens. `shareOfFirst` is the cumulative survival, which flatters
 * later steps and is easy to misread on its own.
 */
final readonly class FunnelStage
{
    public function __construct(
        public string $label,
        public int $count,
        public string $note,
        public ?float $shareOfFirst,
        public ?float $shareOfPrevious,
    ) {}

    /** How many were lost between the previous stage and this one. */
    public function dropFromPrevious(): ?float
    {
        return $this->shareOfPrevious === null ? null : round(100 - $this->shareOfPrevious, 1);
    }
}
