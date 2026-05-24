<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Services\DTOs;

final class PlacementResult
{
    public function __construct(
        public readonly int $distributorId,
        public readonly int $userId,
        public readonly int $parentId,
        public readonly string $side,
        public readonly int $depth,
        public readonly string $sideChosenBy,
    ) {}
}
