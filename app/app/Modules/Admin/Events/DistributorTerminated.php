<?php

declare(strict_types=1);

namespace App\Modules\Admin\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * A distributor account was permanently closed ('terminated' status).
 * Distinct from 'rejected' — there is no path back to active from this state.
 * Reason captured in audit_log and surfaced in the email.
 *
 * `actorUserId` is null when the closure was automatic — the agreement §21
 * twelve-month dormancy sweep has no human actor, and naming one would put a
 * false attribution into the record.
 */
final class DistributorTerminated
{
    use Dispatchable;

    public function __construct(
        public readonly int $distributorId,
        public readonly ?int $actorUserId,
        public readonly string $reason,
        public readonly Carbon $terminatedAt,
    ) {}
}
