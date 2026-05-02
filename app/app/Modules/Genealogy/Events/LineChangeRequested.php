<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

final class LineChangeRequested
{
    use Dispatchable;

    public function __construct(
        public readonly int $requestId,
        public readonly int $distributorId,
        public readonly int $fromSponsorId,
        public readonly int $toSponsorId,
        public readonly Carbon $requestedAt,
    ) {}
}
