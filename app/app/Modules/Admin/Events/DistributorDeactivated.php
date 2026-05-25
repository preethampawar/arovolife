<?php

declare(strict_types=1);

namespace App\Modules\Admin\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * Admin flipped the distributor record's own status enum to 'inactive'
 * (distributors.status, distinct from the user-account lifecycle that
 * freeze / unfreeze / terminate operate on). Reversible via reactivation.
 */
final class DistributorDeactivated
{
    use Dispatchable;

    public function __construct(
        public readonly int $distributorId,
        public readonly int $actorUserId,
        public readonly Carbon $deactivatedAt,
    ) {}
}
