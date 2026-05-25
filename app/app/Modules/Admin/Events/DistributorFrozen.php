<?php

declare(strict_types=1);

namespace App\Modules\Admin\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * Admin temporarily froze a distributor's user account ('frozen' status).
 * The distributor cannot sign in until an admin unfreezes it. Reversible,
 * unlike termination. Reason captured in audit_log and surfaced in the email.
 */
final class DistributorFrozen
{
    use Dispatchable;

    public function __construct(
        public readonly int $distributorId,
        public readonly int $actorUserId,
        public readonly string $reason,
        public readonly Carbon $frozenAt,
    ) {}
}
