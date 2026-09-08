<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

/**
 * What the compensation engines need a human to do right now — the payload of
 * the daily health digest.
 *
 * Every item carries its own remedy steps rather than a code the reader has to
 * look up: the reader is an admin, not a developer, and the email is the only
 * place the problem is visible at all.
 *
 * @phpstan-type FailureItem array{engine: string, key: string, period: string, period_value: string, started_at: string, error: string, steps: list<string>}
 * @phpstan-type MissingItem array{engine: string, key: string, period: string, period_value: string, due_at: string, steps: list<string>}
 * @phpstan-type StuckItem array{engine: string, key: string, period: string, period_value: string, started_at: string, steps: list<string>}
 */
final readonly class EngineHealthReport
{
    /**
     * @param  list<FailureItem>  $failures  Failed runs with no later succeeded run for the same period.
     * @param  list<MissingItem>  $missing  Scheduled periods for which no run of any kind was recorded.
     * @param  list<StuckItem>  $stuck  Runs still `running` long after they started.
     */
    public function __construct(
        public array $failures,
        public array $missing,
        public array $stuck,
    ) {}

    public function isHealthy(): bool
    {
        return $this->total() === 0;
    }

    public function total(): int
    {
        return count($this->failures) + count($this->missing) + count($this->stuck);
    }
}
