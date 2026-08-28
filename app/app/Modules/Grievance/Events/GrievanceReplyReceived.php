<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Events;

/**
 * A complainant added something to their own grievance.
 *
 * This event exists because policy §4 tells complainants they may escalate by
 * replying to their ticket. A reply that only lands in a database row is a
 * published route that goes nowhere.
 */
final readonly class GrievanceReplyReceived
{
    public function __construct(
        public int $ticketId,
        public bool $requestsEscalation = false,
    ) {}
}
