<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

use Illuminate\Support\Carbon;

/**
 * What a full compensation recompute did: what it destroyed, what window it
 * replayed, and what the engines produced the second time around.
 */
final readonly class RecomputeReport
{
    /**
     * @param  array<string, int>  $rowsRemoved  table => rows truncated
     * @param  array<string, int>  $enginesRun  artisan signature => times invoked
     * @param  list<string>  $warnings
     */
    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public array $rowsRemoved,
        public int $ordersPropagated,
        public int $daysReplayed,
        public array $enginesRun,
        public array $warnings,
        public float $durationSeconds,
    ) {}

    public function totalRowsRemoved(): int
    {
        return array_sum($this->rowsRemoved);
    }

    public function totalEngineRuns(): int
    {
        return array_sum($this->enginesRun);
    }
}
