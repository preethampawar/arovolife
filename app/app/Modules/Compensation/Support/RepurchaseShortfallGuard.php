<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Shared\Support\IndianNumber;
use Illuminate\Support\Facades\DB;

/**
 * What a rebuild has left the repurchase wallet short by — measured AFTER the
 * period has been replayed, because that is the first moment it is knowable.
 *
 * A rebuild deletes a period's `repurchase_deduction` credits and writes them
 * again from the same orders, so for the distributor who cleared their wallet on
 * a repurchase order the balance dips and comes back. Refusing up front because
 * a spend exists at all refuses every month that ever paid a gated bonus:
 * reaching zero at month end — which is exactly what the GBB, Fortune, rank
 * requalification and AO-GO gates require — means having spent during the month.
 * An ordinary product order months later refused the rebuild just the same,
 * because that test had no upper bound in time.
 *
 * What is worth stopping on is narrower: the replay crediting LESS than it did
 * before, so a discount already taken on an order is no longer covered. That is
 * a real position below zero, and {@see WalletService::repurchaseWalletBalancePaise()}
 * floors the balance at zero, so nothing downstream would ever show it. This
 * measures the unfloored position and names who is short.
 */
final class RepurchaseShortfallGuard
{
    private const CHUNK = 500;

    /** How many distributors a warning names before it summarises the rest. */
    private const NAMED = 10;

    /**
     * The unfloored repurchase position of each of these distributors.
     *
     * Same arithmetic as repurchaseWalletBalancePaise(), without the
     * max(0, ...) floor — the floor is the thing that hides a shortfall.
     * ABS() covers the debit whichever sign it was written with.
     *
     * @param  list<int>  $distributorIds
     * @return array<int, int> distributor id => position in paise, negative when short
     */
    public static function positions(array $distributorIds): array
    {
        $positions = [];

        foreach (array_chunk($distributorIds, self::CHUNK) as $chunk) {
            $rows = WalletLedgerEntry::query()
                ->whereIn('distributor_id', $chunk)
                ->whereIn('type', ['repurchase_deduction', 'repurchase_wallet_used'])
                ->groupBy('distributor_id')
                ->select('distributor_id', DB::raw(
                    "SUM(CASE WHEN type = 'repurchase_deduction' THEN amount_paise ELSE -ABS(amount_paise) END) AS position"
                ))
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                $positions[(int) $row->distributor_id] = (int) $row->position;
            }
        }

        ksort($positions);

        return $positions;
    }

    /**
     * Who is below zero now, and where they stood before the rebuild.
     *
     * Both halves matter: a position can already have been negative going in —
     * the concurrent-checkout race {@see WalletService}
     * documents is one way — and reporting that as the replay's doing would
     * record a causal claim the arithmetic never made.
     *
     * @param  array<int, int>  $before
     * @param  array<int, int>  $after
     * @return array<int, array{before: int, after: int}>
     */
    public static function shortfalls(array $before, array $after): array
    {
        $short = [];

        foreach ($after as $distributorId => $position) {
            if ($position < 0) {
                $short[$distributorId] = ['before' => $before[$distributorId] ?? 0, 'after' => $position];
            }
        }

        return $short;
    }

    /**
     * @param  array<int, array{before: int, after: int}>  $shortfalls
     */
    public static function warning(array $shortfalls): ?string
    {
        if ($shortfalls === []) {
            return null;
        }

        $named = array_slice($shortfalls, 0, self::NAMED, true);
        $parts = [];

        foreach ($named as $distributorId => $position) {
            $parts[] = sprintf(
                '#%d short %s (was %s)',
                $distributorId,
                IndianNumber::rupees(-$position['after']),
                $position['before'] < 0 ? 'short '.IndianNumber::rupees(-$position['before']) : IndianNumber::rupees($position['before']),
            );
        }

        $remaining = count($shortfalls) - count($named);

        return sprintf(
            '%d distributor(s) hold a repurchase position below zero after this rebuild: %s%s. '
            .'Measured after the replay, so some of it may predate this rebuild. The wallet balance floors at '
            .'zero and will not show it; record a correction against these ledger positions.',
            count($shortfalls),
            implode(', ', $parts),
            $remaining > 0 ? sprintf(' and %d more', $remaining) : '',
        );
    }
}
