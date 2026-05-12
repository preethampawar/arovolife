<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Services\DTOs;

final class PlaceDistributorInput
{
    /**
     * @param  string|null  $panEncrypted  Crypt::encryptString output of the full PAN.
     *                                     Null only in tests / contexts that don't capture full PAN.
     * @param  string|null  $aadhaarEncrypted  Crypt::encryptString output of the full Aadhaar.
     *                                          Null only in tests / contexts that don't capture full Aadhaar.
     *                                          Both are nulled by ApproveKycSubmission after admin verification.
     */
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
        public readonly ?string $panEncrypted = null,
        public readonly ?string $aadhaarEncrypted = null,
    ) {}
}
