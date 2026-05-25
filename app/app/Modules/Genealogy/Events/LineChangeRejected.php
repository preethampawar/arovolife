<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

final class LineChangeRejected
{
    use Dispatchable;

    public function __construct(
        public readonly int $requestId,
        public readonly int $distributorId,
        public readonly string $decisionNote,
        public readonly int $reviewerId,
        public readonly Carbon $rejectedAt,
    ) {}
}
