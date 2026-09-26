<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

use App\Modules\Compensation\Services\RankQualificationService;

/**
 * Who meets each rank's conditions for a month, measured by
 * {@see RankQualificationService::evaluateMonth()}
 * without writing anything.
 *
 * `qualifierIds[r]` lists the distributors meeting rank r; `countedGenosBv` is
 * the month's Left/Right Genos BV net of forfeited days (paise) — the figure
 * recorded against a rank 1–2 qualification.
 */
final readonly class RankEvaluation
{
    /**
     * @param  array<int, array<int, int>>  $qualifierIds  rank_number => distributor ids
     * @param  array<int, array{left: int, right: int}>  $countedGenosBv
     */
    public function __construct(
        public array $qualifierIds,
        public array $countedGenosBv,
    ) {}
}
