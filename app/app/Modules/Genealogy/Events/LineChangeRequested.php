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
        public readonly int $fromPlacementParentId,
        public readonly int $toPlacementParentId,
        public readonly Carbon $requestedAt,
    ) {}
}
