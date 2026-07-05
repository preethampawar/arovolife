<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

use App\Modules\Compensation\Models\GsbCutoffResult;

/**
 * One day of a distributor's Genos BV ledger: the per-order credits that
 * landed on their left/right group that day, plus the day's cut-off
 * settlement result (null when the cut-off has not run yet).
 */
final readonly class GenosLedgerDay
{
    /**
     * @param  array<int, \stdClass>  $credits  rows: date, order_id, bv_paise, created_at, side (L|R), buyer_adn, buyer_name
     */
    public function __construct(
        public string $date,
        public array $credits,
        public ?GsbCutoffResult $cutoff,
    ) {}
}
