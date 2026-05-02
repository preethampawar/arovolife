<?php

declare(strict_types=1);

namespace App\Modules\Admin\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

final class KycRejected
{
    use Dispatchable;

    public function __construct(
        public readonly int $distributorId,
        public readonly int $verifierId,
        public readonly string $reason,
        public readonly Carbon $rejectedAt,
    ) {}
}
