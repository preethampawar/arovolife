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
 * @phpstan-type PrematureFreezeItem array{engine: string, key: string, period: string, period_value: string, frozen_at: string, detected_at: string, steps: list<string>}
 * @phpstan-type ChainAlertItem array{kind: string, headline: string, date: string, recorded_at: string, steps: list<string>}
 */
final readonly class EngineHealthReport
{
    /**
     * @param  list<FailureItem>  $failures  Failed runs with no later succeeded run for the same period.
     * @param  list<MissingItem>  $missing  Scheduled periods for which no run of any kind was recorded.
     * @param  list<StuckItem>  $stuck  Runs still `running` long after they started.
     * @param  list<PrematureFreezeItem>  $prematureFreezes  Pools frozen too early that the self-heal had to keep.
     * @param  list<ChainAlertItem>  $chainAlerts  What the three scheduled runs could not do: a night one never started, a gap too wide to heal, a month or a batch deferred.
     */
    public function __construct(
        public array $failures,
        public array $missing,
        public array $stuck,
        public array $prematureFreezes = [],
        public array $chainAlerts = [],
    ) {}

    public function isHealthy(): bool
    {
        return $this->total() === 0;
    }

    public function total(): int
    {
        return count($this->failures)
            + count($this->missing)
            + count($this->stuck)
            + count($this->prematureFreezes)
            + count($this->chainAlerts);
    }
}
