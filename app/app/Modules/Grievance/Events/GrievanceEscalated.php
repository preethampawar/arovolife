<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Events;

/**
 * A grievance moved up the internal escalation ladder (policy §4).
 */
final readonly class GrievanceEscalated
{
    public function __construct(
        public int $ticketId,
        public int $fromLevel,
        public int $toLevel,
    ) {}
}
