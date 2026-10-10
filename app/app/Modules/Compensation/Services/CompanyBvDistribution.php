<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use Illuminate\Support\Carbon;

final readonly class CompanyBvDistribution
{
    /**
     * @param  list<CompanyBvDistributionRow>  $rows
     */
    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public int $companyBvPaise,
        public array $rows,
    ) {}

    public function totalRateBp(): int
    {
        return array_sum(array_map(static fn (CompanyBvDistributionRow $row): int => $row->rateBp, $this->rows));
    }

    public function totalBvPaise(): int
    {
        return array_sum(array_map(static fn (CompanyBvDistributionRow $row): int => $row->bvPaise, $this->rows));
    }
}
