<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

/** What one month's Lifetime Award sync wrote: milestones created and credited, and their total. */
final readonly class LifetimeAwardSyncOutcome
{
    public function __construct(
        public int $credited,
        public int $amountPaise,
    ) {}
}
