<?php

declare(strict_types=1);

namespace App\Modules\Admin\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

final class KycApproved
{
    use Dispatchable;

    public function __construct(
        public readonly int $distributorId,
        public readonly int $verifierId,
        public readonly Carbon $verifiedAt,
    ) {}
}
