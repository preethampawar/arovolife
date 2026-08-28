<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Events;

/**
 * A grievance was opened on any channel.
 *
 * Carries only the id: the ticket body routinely holds PII and must not be
 * serialised onto the queue payload.
 */
final readonly class GrievanceFiled
{
    public function __construct(public int $ticketId) {}
}
