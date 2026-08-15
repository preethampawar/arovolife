<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

/**
 * One published AO-GO condition next to whether this distributor meets it
 * right now. A plan fact plus the distributor's own state — never a
 * prediction that the offer will be granted (DSR 2021 r.5(1)(d)).
 */
final readonly class AogoCondition
{
    public function __construct(
        public string $label,
        public bool $met,
        public ?string $note = null,
    ) {}
}
