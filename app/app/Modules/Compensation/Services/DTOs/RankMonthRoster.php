<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

use App\Modules\Compensation\Models\RankAogoGrant;
use Illuminate\Support\Collection;

/**
 * The Rank Bonus roster for one month, resolved in pass 1 and closed by the
 * freeze: who qualified for which rank, and which of them the §8
 * requalification gate decided against.
 *
 * The roster is the month's population. Once it is frozen alongside
 * rank_monthly_pools, a distributor who qualifies afterwards has no row and is
 * refused — never paid out of a pool that was already divided.
 *
 * Only requalification-held achievers are excluded from the denominator (the
 * MSB precedent, unchanged by the freeze). The repurchase cycle removes nobody
 * from this roster: it reaches Rank Bonus only upstream, through the Genos BV
 * counted at qualification (client 2026-09-07, §2.2).
 */
final readonly class RankMonthRoster
{
    /**
     * @param  array<int, list<int>>  $payableIds  rank → distributor ids in the denominator
     * @param  array<int, list<int>>  $heldIds  rank → distributor ids failing §8 requalification
     * @param  Collection<int, RankAogoGrant>  $aogoGrants  this month's live grants (Rank-1 pool)
     */
    public function __construct(
        public array $payableIds,
        public array $heldIds,
        public Collection $aogoGrants,
    ) {}

    /** @return list<int> */
    public function payableFor(int $rank): array
    {
        return $this->payableIds[$rank] ?? [];
    }

    /** @return list<int> */
    public function heldFor(int $rank): array
    {
        return $this->heldIds[$rank] ?? [];
    }
}
