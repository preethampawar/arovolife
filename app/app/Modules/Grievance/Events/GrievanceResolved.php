<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Events;

/**
 * A grievance reached a resolution. Closure is a separate, later act.
 */
final readonly class GrievanceResolved
{
    public function __construct(public int $ticketId) {}
}
