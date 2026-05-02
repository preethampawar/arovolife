<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

final class CoolingOffCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly int $distributorId,
        public readonly int $actorUserId,
        public readonly Carbon $cancelledAt,
    ) {}
}
