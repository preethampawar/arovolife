<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * A rejected distributor uploaded replacement KYC documents. Listeners send
 * a confirmation email to the distributor and a re-queued notification to
 * the admin compliance team.
 */
final class KycResubmitted
{
    use Dispatchable;

    /**
     * @param  array<int, string>  $documentTypes  document types replaced
     */
    public function __construct(
        public readonly int $distributorId,
        public readonly array $documentTypes,
        public readonly Carbon $resubmittedAt,
    ) {}
}
