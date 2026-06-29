<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Events;

/** A previously grace/suspended distributor met their repurchase and is
 *  income-eligible again from this point forward. Fire-and-forget. */
final class IncomeReactivated
{
    public function __construct(
        public readonly int $distributorId,
        public readonly int $cycleId,
    ) {}
}
