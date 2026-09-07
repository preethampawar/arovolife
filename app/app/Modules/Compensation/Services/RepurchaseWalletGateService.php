<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

/**
 * The single place the month-end repurchase-wallet question is answered: did
 * this distributor still hold repurchase-wallet money at the last instant of
 * the calendar month?
 *
 * Client 2026-09-05, re-confirmed 2026-09-07. This gate is SEPARATE from the
 * repurchase CYCLE. The cycle's verdict is a per-day forfeit of group BV
 * ({@see IncomeEligibilityService}) and never holds a monthly bonus; this gate
 * is a monthly qualification condition on money the distributor was credited
 * and has not spent. A distributor holding a balance at 23:59:59 on the last
 * day of the month forfeits that month's Growth Booster and Fortune Bonus and
 * fails the rank requalification / AO-GO wallet condition. Never held, never
 * released — the month is simply not paid.
 *
 * The balance comes from the ledger, not from a cycle row's frozen snapshot, so
 * a re-run of a closed month reaches the same verdict the original run reached:
 * ledger entries are append-only and carry their own created_at.
 *
 * Gated by {@see RepurchaseEngineFeature} like every other repurchase effect —
 * with the flag off every distributor is clear.
 */
final class RepurchaseWalletGateService
{
    public function __construct(
        private readonly WalletService $wallet,
    ) {}

    /**
     * Whether each distributor's repurchase wallet was empty at the last
     * instant of $month. Every id asked about is present in the answer.
     *
     * @param  int[]  $distributorIds
     * @return array<int, bool> distributor id → cleared
     */
    public function clearedAtMonthEnd(array $distributorIds, Carbon $month): array
    {
        if ($distributorIds === []) {
            return [];
        }

        if (! Feature::for(null)->active(RepurchaseEngineFeature::class)) {
            return array_fill_keys($distributorIds, true);
        }

        $balances = $this->wallet->repurchaseWalletBalancesAsOfPaise(
            array_values($distributorIds),
            $month->copy()->endOfMonth()->setTime(23, 59, 59),
        );

        $map = [];

        foreach ($distributorIds as $distributorId) {
            $map[$distributorId] = ($balances[$distributorId] ?? 0) <= 0;
        }

        return $map;
    }
}
