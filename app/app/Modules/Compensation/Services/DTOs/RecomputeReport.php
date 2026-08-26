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
    /** Every derived row was truncated and rebuilt from the first BV date. */
    public const MODE_FULL = 'full';

    /** Only the rows from the window start onwards were removed and rebuilt. */
    public const MODE_WINDOWED = 'windowed';

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
        public string $mode = self::MODE_FULL,
    ) {}

    public function totalRowsRemoved(): int
    {
        return array_sum($this->rowsRemoved);
    }

    public function totalEngineRuns(): int
    {
        return array_sum($this->enginesRun);
    }

    public function isWindowed(): bool
    {
        return $this->mode === self::MODE_WINDOWED;
    }

    /** "Everything" or "25 Aug 2026 → 25 Aug 2026" — for the report header. */
    public function windowLabel(): string
    {
        return $this->from->format('d M Y').' → '.$this->to->format('d M Y')
            .($this->isWindowed() ? ' (windowed)' : ' (full)');
    }
}
