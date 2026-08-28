<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Events;

/**
 * The 48-hour acknowledgement promised by policy §2 was issued.
 */
final readonly class GrievanceAcknowledged
{
    public function __construct(public int $ticketId) {}
}
