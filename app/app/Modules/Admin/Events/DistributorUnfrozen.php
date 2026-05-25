<?php

declare(strict_types=1);

namespace App\Modules\Admin\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * Admin lifted a freeze on a distributor's user account (back to 'active').
 * The distributor can sign in again.
 */
final class DistributorUnfrozen
{
    use Dispatchable;

    public function __construct(
        public readonly int $distributorId,
        public readonly int $actorUserId,
        public readonly Carbon $unfrozenAt,
    ) {}
}
