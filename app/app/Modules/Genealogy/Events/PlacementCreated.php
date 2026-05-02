<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Events;

use App\Modules\Genealogy\Services\DTOs\PlacementResult;

final class PlacementCreated
{
    public function __construct(
        public readonly PlacementResult $result,
        public readonly int $sponsorId,
        public readonly int $placementId,
    ) {}
}
