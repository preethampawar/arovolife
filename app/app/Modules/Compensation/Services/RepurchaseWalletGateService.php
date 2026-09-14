<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Exceptions\RepurchaseWalletVerdictNotAvailable;
use App\Modules\Compensation\Support\OpenMonthGuard;
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
 * **Two questions, two methods, and mixing them up is the bug this class was
 * rewritten to make impossible.**
 *
 *  • {@see clearedAtMonthEnd()} is the ENGINE question — a verdict that decides
 *    money. It exists only for a month that has ENDED, and refuses otherwise:
 *    asked mid-month it would silently answer "as of now", counting deductions
 *    the month had not finished forming, including ones written minutes earlier
 *    by an engine in the same monthly close (staging, 14 Sep 2026).
 *  • {@see standingAtMonthEnd()} is the DISPLAY question — "how does this look
 *    as things stand?" for the dashboard's requalification checklist and the
 *    AO-GO status card, both of which ask about the month the distributor is
 *    living in. It never refuses and never decides anything.
 *
 * Neither writes. Gated by {@see RepurchaseEngineFeature} like every other
 * repurchase effect — with the flag off every distributor is clear.
 */
final class RepurchaseWalletGateService
{
    public function __construct(
        private readonly WalletService $wallet,
    ) {}

    /**
     * ENGINE verdict — whether each distributor's repurchase wallet was empty at
     * the last instant of $month. Every id asked about is present in the answer.
     *
     * @param  int[]  $distributorIds
     * @return array<int, bool> distributor id → cleared
     *
     * @throws RepurchaseWalletVerdictNotAvailable when $month has not ended
     */
    public function clearedAtMonthEnd(array $distributorIds, Carbon $month): array
    {
        if ($distributorIds === []) {
            return [];
        }

        if (! $this->engineActive()) {
            return array_fill_keys($distributorIds, true);
        }

        if (OpenMonthGuard::isOpen($month)) {
            throw RepurchaseWalletVerdictNotAvailable::forOpenMonth($month);
        }

        return $this->verdictsFrom(
            $distributorIds,
            $month->copy()->endOfMonth()->setTime(23, 59, 59),
        );
    }

    /**
     * DISPLAY view — how the same condition looks as things stand.
     *
     * For a month that has ended this is the verdict itself, to the second. For
     * the month a distributor is living in it is "as of now": a balance they
     * still have time to spend. That difference is exactly what the dashboard
     * has to show, and why this must never be used to decide a credit.
     *
     * @param  int[]  $distributorIds
     * @return array<int, bool> distributor id → clear so far
     */
    public function standingAtMonthEnd(array $distributorIds, Carbon $month): array
    {
        if ($distributorIds === []) {
            return [];
        }

        if (! $this->engineActive()) {
            return array_fill_keys($distributorIds, true);
        }

        $monthEnd = $month->copy()->endOfMonth()->setTime(23, 59, 59);
        $now = Carbon::now();

        return $this->verdictsFrom($distributorIds, $now->lt($monthEnd) ? $now : $monthEnd);
    }

    /**
     * @param  int[]  $distributorIds
     * @return array<int, bool>
     */
    private function verdictsFrom(array $distributorIds, Carbon $asOf): array
    {
        $balances = $this->wallet->repurchaseWalletBalancesAsOfPaise(
            array_values($distributorIds),
            $asOf,
        );

        $map = [];

        foreach ($distributorIds as $distributorId) {
            $map[$distributorId] = ($balances[$distributorId] ?? 0) <= 0;
        }

        return $map;
    }

    private function engineActive(): bool
    {
        return Feature::for(null)->active(RepurchaseEngineFeature::class);
    }
}
