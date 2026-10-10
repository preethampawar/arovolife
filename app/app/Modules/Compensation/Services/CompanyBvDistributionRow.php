<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

final readonly class CompanyBvDistributionRow
{
    public function __construct(
        public int $number,
        public string $label,
        public int $rateBp,
        public int $bvPaise,
    ) {}
}
