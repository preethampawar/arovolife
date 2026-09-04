<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Support\EngineRunContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WalletService
{
    public function balancePaise(int $distributorId): int
    {
        return (int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->sum('amount_paise');
    }

    /**
     * Positive (credit) totals grouped by entry type, optionally from a date.
     *
     * The income dashboard's per-bonus summary reads the wallet ledger rather
     * than the per-engine result tables so that "credited to wallet" means
     * exactly that for every bonus, from one source — held/suspended engine
     * rows never appear here because they were never credited.
     *
     * @return array<string, int> type => total paise
     */
    public function creditTotalsByType(int $distributorId, ?\DateTimeInterface $from = null): array
    {
        return WalletLedgerEntry::where('distributor_id', $distributorId)
            ->where('amount_paise', '>', 0)
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->groupBy('type')
            ->selectRaw('type, SUM(amount_paise) as total_paise')
            ->pluck('total_paise', 'type')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * Positive (credit) totals per calendar month for the trailing `$months`
     * months (current month inclusive), zero-filled and keyed `Y-m` ascending.
     * Same "money that actually reached the wallet" source as
     * creditTotalsByType(), bucketed on IST month boundaries.
     *
     * @return array<string, int> Y-m => total paise
     */
    public function creditTotalsByMonth(int $distributorId, int $months = 6): array
    {
        $months = max(1, $months);
        $nowIst = Carbon::now('Asia/Kolkata');
        $start = $nowIst->copy()->startOfMonth()->subMonthsNoOverflow($months - 1);

        $entries = WalletLedgerEntry::where('distributor_id', $distributorId)
            ->where('amount_paise', '>', 0)
            ->where('created_at', '>=', $start->copy()->timezone(config('app.timezone')))
            ->get(['amount_paise', 'created_at']);

        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $series[$start->copy()->addMonthsNoOverflow($i)->format('Y-m')] = 0;
        }

        foreach ($entries as $entry) {
            $key = $entry->created_at->copy()->timezone('Asia/Kolkata')->format('Y-m');
            if (array_key_exists($key, $series)) {
                $series[$key] += (int) $entry->amount_paise;
            }
        }

        return $series;
    }

    /**
     * Credit a distributor's wallet.
     *
     * A sale-derived credit MUST carry the reference to the result row it came
     * from. Hard rule 2 is not satisfied by the money having been calculated
     * from a sale somewhere upstream — it has to be possible to walk credit →
     * result → BV → order afterwards, on demand, for any rupee the company
     * paid. A credit with no reference is a payment nobody can tie to a sale,
     * and "we know it came from BV" is not an answer to a regulator holding a
     * ledger export.
     *
     * `manual_credit` is deliberately exempt: it is an admin correction, it has
     * no result row by definition, and its control is the audit log rather than
     * this guard.
     *
     * @throws InvalidArgumentException when a sale-derived credit has no reference
     */
    public function credit(
        int $distributorId,
        int $amountPaise,
        string $type,
        ?int $referenceId = null,
        ?string $referenceType = null,
        ?string $memo = null,
    ): WalletLedgerEntry {
        $saleDerived = array_merge(
            CompensationPlanSettingsService::GROUP_A_TYPES,
            CompensationPlanSettingsService::GROUP_B_TYPES,
            CompensationPlanSettingsService::GROUP_C_TYPES,
            CompensationPlanSettingsService::GROUP_D_TYPES,
        );

        if (in_array($type, $saleDerived, true) && ($referenceId === null || $referenceType === null)) {
            throw new InvalidArgumentException(
                "Wallet credit of type '{$type}' requires referenceId and referenceType: every "
                .'sale-derived credit must be traceable back to the product sale it came from '
                .'(hard rule 2, DSR 2021 Rule 5(1)(c)).'
            );
        }

        return WalletLedgerEntry::create([
            'distributor_id' => $distributorId,
            'type' => $type,
            'amount_paise' => abs($amountPaise),  // always positive for credits
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'memo' => $memo,
            'engine_run_id' => $this->activeEngineRunId(),
        ]);
    }

    public function debit(
        int $distributorId,
        int $amountPaise,
        string $type,
        ?int $referenceId = null,
        ?string $referenceType = null,
        ?string $memo = null,
    ): WalletLedgerEntry {
        return WalletLedgerEntry::create([
            'distributor_id' => $distributorId,
            'type' => $type,
            'amount_paise' => -abs($amountPaise),  // always negative for debits
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'memo' => $memo,
            'engine_run_id' => $this->activeEngineRunId(),
        ]);
    }

    /**
     * The engine run this entry belongs to, so a run's committed rows can be
     * listed afterwards — a failed run leaves a set, not archaeology.
     *
     * Resolved per call, never captured in the constructor: EngineRunContext is
     * container-scoped and flushed between queue jobs, while this service may be
     * held by a long-lived worker. Null outside an engine run (order-time
     * entries, admin corrections).
     */
    private function activeEngineRunId(): ?int
    {
        return app(EngineRunContext::class)->activeRunId();
    }

    /**
     * Running balance of the repurchase wallet for a distributor.
     *
     * Credits: every `repurchase_deduction` entry (positive amount_paise).
     * Debits:  every `repurchase_wallet_used` entry (negative amount_paise,
     *          stored as abs() by WalletService::debit()).
     *
     * Returns the net balance, floored at 0. Cannot go negative.
     *
     * `$lockForUpdate` takes a row lock on the entries the sum is read from and
     * MUST be used by any caller that spends against the figure it gets back
     * (mirrors RedeemPointsService::redeem()). Without it two concurrent
     * checkouts by the same distributor both read the same balance and both
     * apply it in full: the debits then exceed the credits and the max(0, …)
     * floor below hides the overspend instead of surfacing it. Read-only
     * callers (dashboards, the checkout screen's preview) leave it false.
     */
    public function repurchaseWalletBalancePaise(int $distributorId, bool $lockForUpdate = false): int
    {
        $credits = (int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->where('type', 'repurchase_deduction')
            ->when($lockForUpdate, fn ($q) => $q->lockForUpdate())
            ->sum('amount_paise');

        $debits = abs((int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->where('type', 'repurchase_wallet_used')
            ->when($lockForUpdate, fn ($q) => $q->lockForUpdate())
            ->sum('amount_paise'));

        return max(0, $credits - $debits);
    }

    /**
     * Give the repurchase-wallet credit back when the order it was spent on is
     * refunded. The restoration is a fresh `repurchase_deduction` credit tied to
     * the order, because the balance is defined as deductions − usages: undoing
     * the usage row itself would break the audit trail of what was spent when.
     *
     * The money must come back as repurchase credit and never as cash — it was
     * withheld from a bonus payout to fund the mandatory monthly repurchase and
     * is explicitly non-withdrawable, so returning it in cash would turn it into
     * a cash-out route (R-60). {@see RefundOrder} keeps the cash side out of the
     * refund payable; this puts the entitlement back in the wallet.
     *
     * `$amountPaise` is what the refund is actually giving back, which can be
     * less than what was spent: a return that does not refund shipping does not
     * return the part of the credit that paid the shipping either. It is capped
     * at the original usage so a refund can never restore more than was taken.
     *
     * Idempotent: a second call for the same order finds the existing
     * restoration and does nothing, so a retried refund cannot mint credit.
     */
    public function restoreRepurchaseCreditForOrder(int $orderId, int $amountPaise, string $memo): ?WalletLedgerEntry
    {
        if ($amountPaise <= 0) {
            return null;
        }

        $spent = WalletLedgerEntry::where('reference_type', 'order')
            ->where('reference_id', $orderId)
            ->where('type', 'repurchase_wallet_used')
            ->first();

        if ($spent === null) {
            return null;
        }

        $amountPaise = min($amountPaise, abs((int) $spent->amount_paise));

        $alreadyRestored = WalletLedgerEntry::where('reference_type', 'order')
            ->where('reference_id', $orderId)
            ->where('type', 'repurchase_deduction')
            ->exists();

        if ($alreadyRestored) {
            return null;
        }

        return $this->credit(
            distributorId: (int) $spent->distributor_id,
            amountPaise: $amountPaise,
            type: 'repurchase_deduction',
            referenceId: $orderId,
            referenceType: 'order',
            memo: $memo,
        );
    }

    /**
     * Repurchase-wallet balance of many distributors at once, as it stood at
     * the end of a given instant (entries created after `$asOf` are ignored).
     * Same arithmetic as repurchaseWalletBalancePaise(); distributors with no
     * entries are simply absent from the result (balance 0).
     *
     * Used by the Fortune enrolment gate, which runs on the 9th but has to
     * judge the wallet as of the last day of the month being enrolled.
     *
     * Only the two repurchase entry types are counted: a repurchase deduction
     * must be undone by a negative `repurchase_deduction` entry, never by a
     * generic `reversal` row, or the balance here overstates and the gate
     * excludes wrongly.
     *
     * @param  list<int>  $distributorIds
     * @return array<int, int> distributor_id → balance in paise, floored at 0
     */
    public function repurchaseWalletBalancesAsOfPaise(array $distributorIds, \DateTimeInterface $asOf): array
    {
        if ($distributorIds === []) {
            return [];
        }

        $rows = DB::table('wallet_ledger_entries')
            ->whereIn('distributor_id', $distributorIds)
            ->whereIn('type', ['repurchase_deduction', 'repurchase_wallet_used'])
            ->where('created_at', '<=', $asOf)
            ->groupBy('distributor_id')
            ->selectRaw("distributor_id, COALESCE(SUM(CASE WHEN type = 'repurchase_deduction' THEN amount_paise ELSE 0 END), 0) AS credits, COALESCE(SUM(CASE WHEN type = 'repurchase_wallet_used' THEN ABS(amount_paise) ELSE 0 END), 0) AS debits")
            ->get();

        $balances = [];
        foreach ($rows as $row) {
            $balances[(int) $row->distributor_id] = max(0, (int) $row->credits - (int) $row->debits);
        }

        return $balances;
    }

    /**
     * The absolute amount of repurchase credit that was applied to a specific
     * order (reference_type = 'order', reference_id = order id).
     * Returns 0 if none was applied.
     */
    public function repurchaseCreditAppliedToOrder(int $orderId): int
    {
        return abs((int) WalletLedgerEntry::where('reference_id', $orderId)
            ->where('reference_type', 'order')
            ->where('type', 'repurchase_wallet_used')
            ->sum('amount_paise'));
    }

    /**
     * Sum of positive unswept credits for a distributor filtered to specific entry types.
     * Used by PayoutService to compute per-stream gross before sweeping.
     *
     * @param  string[]  $types
     */
    public function sumUnsweptByTypes(int $distributorId, array $types): int
    {
        return (int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->whereIn('type', $types)
            ->whereNull('swept_by_payout_batch_id')
            ->where('amount_paise', '>', 0)
            ->sum('amount_paise');
    }

    /**
     * Running balance ledger with cumulative sum, ordered by created_at.
     * Capped at the most recent 500 entries to prevent unbounded memory use.
     */
    public function ledgerWithRunningBalance(int $distributorId, int $limit = 500): Collection
    {
        $entries = WalletLedgerEntry::where('distributor_id', $distributorId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $running = 0;

        return $entries->map(function (WalletLedgerEntry $e) use (&$running) {
            $running += $e->amount_paise;

            return ['entry' => $e, 'running_balance_paise' => $running];
        });
    }
}
