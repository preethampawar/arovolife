<?php

declare(strict_types=1);

namespace App\Modules\Admin\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * Admin flipped the distributor record's own status enum back to 'active'
 * (distributors.status). The distributor record is in circulation again.
 */
final class DistributorReactivated
{
    use Dispatchable;

    public function __construct(
        public readonly int $distributorId,
        public readonly int $actorUserId,
        public readonly Carbon $reactivatedAt,
    ) {}
}
