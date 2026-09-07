<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

use Illuminate\Support\Collection;

/**
 * The Growth Booster roster for one month, resolved in pass 1 and closed by the
 * freeze: who earned AGP, how much of it, and who the month-end repurchase
 * wallet gate decided against.
 *
 * The roster is the month's population. Once it is frozen alongside
 * gbb_monthly_pools, a distributor whose AGP appears afterwards has no row and
 * is refused — never paid out of a pool that was already divided.
 *
 * Every collection is keyed by distributor id and holds that distributor's
 * FROZEN AGP for the month. {@see totalAgp()} is the denominator the pool is
 * divided by, so the pool and the roster are consistent by construction:
 * payable only, because wallet-blocked AGP can never be paid for the month and
 * must not dilute anyone else's point value (the MSB rule — only payable
 * participants dilute a pool).
 *
 * There is no held bucket. The repurchase CYCLE never withholds GBB (client
 * spec 2026-09-07 §2.3): a failed day produced no GSB match, so no AGP came
 * from it in the first place.
 */
final readonly class GbbMonthRoster
{
    /**
     * @param  Collection<int, int>  $payable  distributor id → AGP, credited at the frozen point value
     * @param  Collection<int, int>  $walletBlocked  repurchase wallet not cleared at month end; audit-only, gross 0, out of the denominator, never released
     * @param  int  $skippedNoAgp  cut-off earners whose slabs awarded no AGP at all
     */
    public function __construct(
        public Collection $payable,
        public Collection $walletBlocked,
        public int $skippedNoAgp,
    ) {}

    /**
     * Σ AGP over exactly the members counted in the denominator — the payable.
     * This is what gbb_monthly_pools.total_agp is frozen at.
     */
    public function totalAgp(): int
    {
        return (int) $this->payable->sum();
    }
}
