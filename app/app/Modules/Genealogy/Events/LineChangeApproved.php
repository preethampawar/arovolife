<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

final class LineChangeApproved
{
    use Dispatchable;

    public function __construct(
        public readonly int $requestId,
        public readonly int $distributorId,
        public readonly int $newPlacementParentId,
        public readonly string $chosenSide,
        public readonly int $reviewerId,
        public readonly Carbon $approvedAt,
    ) {}
}
