<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services\DTOs;

use Carbon\CarbonImmutable;

/** The payment staff recorded for an offline order, as submitted. */
final readonly class OfflinePaymentDetails
{
    public function __construct(
        public string $channel,
        public ?string $channelOther,
        public int $amountPaise,
        public CarbonImmutable $receivedOn,
        public ?string $referenceNo,
        public ?string $payerName,
        public ?string $notes,
    ) {}
}
