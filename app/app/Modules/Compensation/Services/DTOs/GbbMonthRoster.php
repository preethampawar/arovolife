<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

use Illuminate\Support\Collection;

/**
 * The Growth Booster roster for one month, resolved in pass 1 and closed by the
 * freeze: who earned AGP and how much of it.
 *
 * The roster is the month's population. Once it is frozen alongside
 * gbb_monthly_pools, a distributor whose AGP appears afterwards has no row and
 * is refused — never paid out of a pool that was already divided.
 *
 * Every collection is keyed by distributor id and holds that distributor's
 * FROZEN AGP for the month. {@see totalAgp()} is the denominator the pool is
 * divided by, so the pool and the roster are consistent by construction.
 *
 * There is no held or blocked bucket: no month-end repurchase condition
 * withholds GBB (user decision 2026-10-17). A failed repurchase day reaches GBB
 * only by producing no GSB slab, and so no AGP, that day.
 */
final readonly class GbbMonthRoster
{
    /**
     * @param  Collection<int, int>  $payable  distributor id → AGP, credited at the frozen point value
     * @param  int  $skippedNoAgp  cut-off earners whose slabs awarded no AGP at all
     */
    public function __construct(
        public Collection $payable,
        public int $skippedNoAgp,
    ) {}

    /**
     * Σ AGP over the roster — every member is payable.
     * This is what gbb_monthly_pools.total_agp is frozen at.
     */
    public function totalAgp(): int
    {
        return (int) $this->payable->sum();
    }
}
