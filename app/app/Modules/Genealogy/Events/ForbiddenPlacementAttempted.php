<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Events;

use App\Modules\Genealogy\Services\DTOs\PlaceDistributorInput;

final class ForbiddenPlacementAttempted
{
    public function __construct(
        public readonly PlaceDistributorInput $input,
        public readonly int $attemptedPlacementId,
    ) {}
}
