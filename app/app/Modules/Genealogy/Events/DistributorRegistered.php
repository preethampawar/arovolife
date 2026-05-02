<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Events;

final class DistributorRegistered
{
    public function __construct(
        public readonly int $distributorId,
        public readonly int $sponsorId,
    ) {}
}
