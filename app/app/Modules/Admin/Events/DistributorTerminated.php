<?php

declare(strict_types=1);

namespace App\Modules\Admin\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * Admin permanently closed a distributor account ('terminated' status).
 * Distinct from 'rejected' — there is no path back to active from this state.
 * Reason captured in audit_log and surfaced in the email.
 */
final class DistributorTerminated
{
    use Dispatchable;

    public function __construct(
        public readonly int $distributorId,
        public readonly int $actorUserId,
        public readonly string $reason,
        public readonly Carbon $terminatedAt,
    ) {}
}
