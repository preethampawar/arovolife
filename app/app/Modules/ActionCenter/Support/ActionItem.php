<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One row on a type page (plan §3.1). Immutable, hydrated by a provider from
 * a read-only query; it carries only what the row renders plus the deep link
 * to the screen that already fixes it — the Action Center builds no new
 * fixing screens (plan §10.3).
 */
final readonly class ActionItem
{
    /** @param array<string, scalar|null> $meta */
    public function __construct(
        public string $subjectType,
        public int $subjectId,
        public string $title,
        public string $subtitle,
        public CarbonInterface $occurredAt,
        public ?CarbonInterface $dueAt,
        public string $severity,
        public ?string $url = null,
        public array $meta = [],
    ) {}

    /** Whole hours since the condition first held; 0 for anything in the future. */
    public function ageHours(): int
    {
        $seconds = CarbonImmutable::now()->getTimestamp() - $this->occurredAt->getTimestamp();

        return $seconds <= 0 ? 0 : intdiv($seconds, 3600);
    }

    public function isOverdue(): bool
    {
        return $this->dueAt !== null && $this->dueAt->isPast();
    }
}
