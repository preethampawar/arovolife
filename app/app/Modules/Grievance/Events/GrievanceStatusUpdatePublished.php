<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Events;

/**
 * A 15-day progress update was published on a ticket whose resolution window
 * was extended for a third-party dependency (policy §2).
 */
final readonly class GrievanceStatusUpdatePublished
{
    public function __construct(
        public int $ticketId,
        public string $note,
    ) {}
}
