<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Services\DTOs;

final class PlaceDistributorInput
{
    public function __construct(
        public readonly int $userId,
        public readonly int $sponsorId,
        public readonly int $placementId,
        public readonly string $panHash,
        public readonly string $panLast4,
        public readonly string $bankAccountEnc,
        public readonly string $bankIfsc,
        public readonly string $state,
        public readonly ?string $sideOpt = null,
        public readonly ?string $aadhaarRef = null,
        public readonly ?string $aadhaarLast4 = null,
        public readonly bool $isPrimaryCouple = false,
    ) {}
}
