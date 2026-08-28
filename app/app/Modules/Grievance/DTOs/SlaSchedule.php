<?php

declare(strict_types=1);

namespace App\Modules\Grievance\DTOs;

use Illuminate\Support\Carbon;

/**
 * The three clocks that start the moment a grievance is received.
 */
final readonly class SlaSchedule
{
    public function __construct(
        public Carbon $acknowledgementDueAt,
        public Carbon $firstResponseDueAt,
        public Carbon $resolutionDueAt,
    ) {}
}
