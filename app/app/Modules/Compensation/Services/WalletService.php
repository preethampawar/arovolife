<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\DTOs\BonusCreditOutcome;
use App\Modules\Compensation\Services\DTOs\BonusReversalOutcome;
use App\Modules\Compensation\Support\EngineRunContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WalletService
{
    /**
     * Repurchase wallet entry types that must never be counted in the main wallet balance.
     *
     * `repurchase_transfer` is deliberately absent: it is the debit that moves
     * the deduction OUT of the main wallet, so it has to keep reducing the main
     * balance. Adding it here would leave the main wallet showing the gross.
     */
    public const REPURCHASE_TYPES = ['repurchase_deduction', 'repurchase_wallet_used'];

    /**
     * Suffix appended to the reversed row's reference_type by
     * {@see reverseBonusCredit()} so the unwinding `repurchase_deduction` entry
     * does not collide with the original credit's row on
     * `uniq_wallet_ledger_source (type, reference_type, reference_id)`.
     */
    public const REVERSAL_REFERENCE_SUFFIX = '_reversal';

    public function __construct(
        private readonly CompensationPlanSettingsService $planSettings,
    ) {}

    public function balancePaise(int $distributorId): int
    {
        return (int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->whereNotIn('type', self::REPURCHASE_TYPES)
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
     * `$bonusMonth` is the first day of the IST month the income was EARNED for,
     * and every credit of a {@see CompensationPlanSettingsService::MONTHLY_CAP_TYPES}
     * type must carry it: the monthly ceilings are windowed on it rather than on
     * created_at, because the monthly engines run on the 1st for the month that
     * just closed. Null for entries with no earned month (order-time repurchase
     * restorations, admin corrections).
     *
     * `$earnedOn` is the DAY the income was earned — the GSB cut-off date, the
     * mentorship cut-off day. It is MANDATORY for every
     * {@see CompensationPlanSettingsService::GROUP_A_TYPES} credit, because the
     * weekly batch pays a Wednesday→Tuesday earning week one Tuesday later
     * ({@see PayoutBatch::weeklyEarningWindow()}) and a Group A credit with no
     * earning day is paid by the first batch that sees it — up to a week early,
     * with real money. The monthly streams are earned for a month rather than a
     * day and leave it null.
     *
     * @throws InvalidArgumentException when a sale-derived credit has no reference,
     *                                  or a Group A credit has no earning day
     */
    public function credit(
        int $distributorId,
        int $amountPaise,
        string $type,
        ?int $referenceId = null,
        ?string $referenceType = null,
        ?string $memo = null,
        ?Carbon $bonusMonth = null,
        ?Carbon $earnedOn = null,
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

        if (in_array($type, CompensationPlanSettingsService::GROUP_A_TYPES, true) && $earnedOn === null) {
            throw new InvalidArgumentException(
                "Wallet credit of type '{$type}' requires earnedOn: the weekly payout pays a "
                .'Wednesday-to-Tuesday earning week one Tuesday after it closes, so a Group A '
                .'credit with no earning day would be paid by the first batch that sees it.'
            );
        }

        return WalletLedgerEntry::create([
            'distributor_id' => $distributorId,
            'type' => $type,
            'amount_paise' => abs($amountPaise),  // always positive for credits
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'bonus_month' => $bonusMonth?->copy()->startOfMonth()->toDateString(),
            'earned_on' => $earnedOn?->toDateString(),
            'memo' => $memo,
            'engine_run_id' => $this->activeEngineRunId(),
        ]);
    }

    /** @see credit() for what `$bonusMonth` and `$earnedOn` mean. */
    public function debit(
        int $distributorId,
        int $amountPaise,
        string $type,
        ?int $referenceId = null,
        ?string $referenceType = null,
        ?string $memo = null,
        ?Carbon $bonusMonth = null,
        ?Carbon $earnedOn = null,
    ): WalletLedgerEntry {
        return WalletLedgerEntry::create([
            'distributor_id' => $distributorId,
            'type' => $type,
            'amount_paise' => -abs($amountPaise),  // always negative for debits
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'bonus_month' => $bonusMonth?->copy()->startOfMonth()->toDateString(),
            'earned_on' => $earnedOn?->toDateString(),
            'memo' => $memo,
            'engine_run_id' => $this->activeEngineRunId(),
        ]);
    }

    /**
     * Credit a bonus and take the repurchase deduction out of it in the same
     * breath: the gross lands in the main wallet, a `repurchase_transfer` debit
     * takes the deduction back out of it, and a matching `repurchase_deduction`
     * credit puts that amount in the repurchase wallet. Three entries, so the
     * distributor's statement shows what was earned, what was withheld and
     * where it went, rather than a single netted figure nobody can reconcile.
     *
     * The deduction is `comp.repurchase.rate_bp` of the gross, floored, and is
     * capped at whatever is left of `comp.repurchase.cap_paise` for the month
     * the income was EARNED for — `$bonusMonth`, not the month the credit is
     * written in. The monthly engines all run in the small hours of the 1st for
     * the month that just closed, so windowing on the write date made August's
     * Rank, Growth Booster, Fortune and ADC credits compete for September's
     * ceiling in cron order. A distributor who has already hit the ceiling for
     * that earned month is credited gross with no deduction at all.
     *
     * Returns the outcome (gross, deduction, the gross credit entry) so the
     * calling engine can freeze the deduction onto its result row — the pages
     * read that row, never the ledger, so this is the only place the figure is
     * ever computed.
     *
     * All three rows carry `$earnedOn` ({@see credit()}): the weekly batch
     * sweeps a credit and its `repurchase_transfer` debit together, so a debit
     * outside the window its credit is in would leave the main wallet holding a
     * deduction for money that has already gone to the bank.
     */
    public function creditWithRepurchaseDeduction(
        int $distributorId,
        int $grossPaise,
        string $bonusType,
        int $referenceId,
        string $referenceType,
        Carbon $bonusMonth,
        ?string $memo = null,
        ?Carbon $earnedOn = null,
    ): BonusCreditOutcome {
        $bonusMonth = $bonusMonth->copy()->startOfMonth();

        return DB::transaction(function () use (
            $distributorId, $grossPaise, $bonusType, $referenceId, $referenceType, $bonusMonth, $memo, $earnedOn,
        ): BonusCreditOutcome {
            $deductionPaise = (int) floor(abs($grossPaise) * $this->planSettings->repurchaseRateBp() / 10_000);

            $alreadyDeducted = $this->repurchaseDeductionForMonthPaise($distributorId, $bonusMonth);

            $deductionPaise = min(
                $deductionPaise,
                max(0, $this->planSettings->repurchaseCapPaise() - $alreadyDeducted),
            );

            $grossEntry = $this->credit(
                distributorId: $distributorId,
                amountPaise: $grossPaise,
                type: $bonusType,
                referenceId: $referenceId,
                referenceType: $referenceType,
                memo: $memo,
                bonusMonth: $bonusMonth,
                earnedOn: $earnedOn,
            );

            if ($deductionPaise > 0) {
                $deductionMemo = 'Repurchase deduction from '.$bonusType;

                $this->debit(
                    distributorId: $distributorId,
                    amountPaise: $deductionPaise,
                    type: 'repurchase_transfer',
                    referenceId: $referenceId,
                    referenceType: $referenceType,
                    memo: $deductionMemo,
                    bonusMonth: $bonusMonth,
                    earnedOn: $earnedOn,
                );

                $this->credit(
                    distributorId: $distributorId,
                    amountPaise: $deductionPaise,
                    type: 'repurchase_deduction',
                    referenceId: $referenceId,
                    referenceType: $referenceType,
                    memo: $deductionMemo,
                    bonusMonth: $bonusMonth,
                    earnedOn: $earnedOn,
                );
            }

            return new BonusCreditOutcome($grossPaise, $deductionPaise, $grossEntry);
        });
    }

    /**
     * Unwind a bonus credit: the exact mirror of creditWithRepurchaseDeduction().
     *
     * A reversal that only debits the net from the main wallet leaves the
     * repurchase wallet holding its share of a bonus that no longer exists. That
     * phantom balance is not cosmetic — Fortune, Growth Booster and the Rank
     * requalification all gate on the repurchase wallet being zero, so a bonus
     * an admin reversed goes on excluding the distributor from later income.
     * Both sides therefore have to come back in one transaction.
     *
     * The repurchase side is written as a NEGATIVE `repurchase_deduction` entry
     * rather than a generic `reversal` row, because both the wallet balance and
     * the monthly deduction ceiling are sums over that one type: a `reversal`
     * row would be invisible to them. It carries the SAME `$bonusMonth` as the
     * original credit so the ceiling gives the room back to the month the income
     * was earned for, not the month the reversal was keyed in.
     *
     * `uniq_wallet_ledger_source` covers (type, reference_type, reference_id),
     * so the unwind cannot reuse the credit's own reference tuple. It hangs off
     * `<referenceType>_reversal` instead — distinct per bonus table and per row,
     * and deliberately not `order`, which repurchaseDeductionForMonthPaise()
     * excludes as a refund restoration.
     *
     * **Clamping.** The distributor may already have spent the repurchase credit
     * at checkout. Only what is actually in the repurchase wallet is taken back
     * — the balance can never be driven negative — and the remainder is returned
     * as `repurchaseShortfallPaise` for the caller to record. Same shape as
     * {@see restoreRepurchaseCreditForOrder()}, which caps its restore at what
     * was actually spent.
     *
     * **The original credit stays.** Both wallets net to where they were, but
     * the `+gross` credit and its `repurchase_transfer` debit are deliberately
     * left in the ledger so the statement still shows what was earned and what
     * was withheld before the reversal. They must never be paid: the `reversal`
     * row this writes is what {@see WalletLedgerEntry::scopeNotReversed()} keys
     * off to keep a payout batch from sweeping them to the bank and to keep them
     * out of the month's income ceiling.
     *
     * Idempotent: a row that already carries a `reversal` entry returns null and
     * writes nothing, so a double-submitted admin form cannot debit twice.
     *
     * Bonus-type agnostic on purpose — Rank, Growth Booster, Fortune and ADC
     * reversals get the same behaviour by passing their own result row.
     */
    public function reverseBonusCredit(
        int $distributorId,
        int $netPaise,
        int $repurchaseDeductionPaise,
        int $referenceId,
        string $referenceType,
        Carbon $bonusMonth,
        ?string $memo = null,
    ): ?BonusReversalOutcome {
        $bonusMonth = $bonusMonth->copy()->startOfMonth();

        return DB::transaction(function () use (
            $distributorId, $netPaise, $repurchaseDeductionPaise, $referenceId, $referenceType, $bonusMonth, $memo,
        ): ?BonusReversalOutcome {
            $alreadyReversed = WalletLedgerEntry::where('type', 'reversal')
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->exists();

            if ($alreadyReversed) {
                return null;
            }

            $entry = $this->debit(
                distributorId: $distributorId,
                amountPaise: $netPaise,
                type: 'reversal',
                referenceId: $referenceId,
                referenceType: $referenceType,
                memo: $memo,
                bonusMonth: $bonusMonth,
            );

            // Locked: the figure is spent against, exactly as a checkout would.
            $available = $this->repurchaseWalletBalancePaise($distributorId, lockForUpdate: true);
            $reversedPaise = max(0, min(abs($repurchaseDeductionPaise), $available));
            $shortfallPaise = max(0, abs($repurchaseDeductionPaise) - $reversedPaise);

            if ($reversedPaise > 0) {
                $this->debit(
                    distributorId: $distributorId,
                    amountPaise: $reversedPaise,
                    type: 'repurchase_deduction',
                    referenceId: $referenceId,
                    referenceType: $referenceType.self::REVERSAL_REFERENCE_SUFFIX,
                    memo: $memo,
                    bonusMonth: $bonusMonth,
                );
            }

            return new BonusReversalOutcome($netPaise, $reversedPaise, $shortfallPaise, $entry);
        });
    }

    /**
     * Repurchase deduction already taken from this distributor's bonuses for a
     * given EARNED month — what the monthly cap has to be measured against.
     *
     * `reference_type = 'order'` rows are excluded: those are refund
     * restorations put back by {@see restoreRepurchaseCreditForOrder()}, not
     * money withheld from a bonus, and counting them would let a refund eat
     * into the month's deduction ceiling.
     *
     * Rows written before `bonus_month` existed have nothing to window on, so
     * they fall back to the month they were created in — the answer they have
     * always given. The fallback window is the IST month expressed in UTC,
     * because created_at is stored in UTC; whereMonth() would cut the month at
     * the wrong instant and mis-bill 5½ hours at each boundary.
     */
    public function repurchaseDeductionForMonthPaise(int $distributorId, Carbon $bonusMonth): int
    {
        // The month is an IST calendar month by definition; anchor it in IST
        // rather than converting the caller's instant, so a value that arrives
        // as a bare date can never slide into the neighbouring month.
        $monthIst = Carbon::createFromFormat('Y-m-d H:i:s', $bonusMonth->format('Y-m-01').' 00:00:00', 'Asia/Kolkata');

        return (int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->where('type', 'repurchase_deduction')
            ->where(function ($q): void {
                $q->whereNull('reference_type')->orWhere('reference_type', '!=', 'order');
            })
            ->where(function ($q) use ($monthIst): void {
                $q->whereDate('bonus_month', $monthIst->toDateString())
                    ->orWhere(function ($legacy) use ($monthIst): void {
                        $legacy->whereNull('bonus_month')
                            ->whereBetween('created_at', [
                                $monthIst->copy()->startOfMonth()->setTimezone('UTC'),
                                $monthIst->copy()->endOfMonth()->setTimezone('UTC'),
                            ]);
                    });
            })
            ->sum('amount_paise');
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
     * Used by the Fortune enrolment gate, which runs on the 1st but has to
     * judge the wallet as of the last day of the month being enrolled.
     *
     * Only the two repurchase entry types are counted: a repurchase deduction
     * must be undone by a negative `repurchase_deduction` entry, never by a
     * generic `reversal` row, or the balance here overstates and the gate
     * excludes wrongly. {@see reverseBonusCredit()} is the only writer of that
     * negative entry.
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
     * Reversed bonuses are excluded — a held line item must not report income
     * an admin has already unwound. {@see WalletLedgerEntry::scopeNotReversed()}
     *
     * `$earnedOnOrBefore` narrows the sum to one earning week, so a held weekly
     * line reports the income this batch was actually due to pay rather than
     * everything that has been credited since. Null means no day filter — how
     * the monthly batch, which has no earning week, always reads it.
     *
     * @param  string[]  $types
     */
    public function sumUnsweptByTypes(int $distributorId, array $types, ?Carbon $earnedOnOrBefore = null): int
    {
        return (int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->whereIn('type', $types)
            ->whereNull('swept_by_payout_batch_id')
            ->where('amount_paise', '>', 0)
            ->notReversed()
            ->when($earnedOnOrBefore !== null, fn ($q) => $q->earnedOnOrBefore($earnedOnOrBefore))
            ->sum('amount_paise');
    }

    /**
     * Running balance ledger with cumulative sum, ordered by created_at.
     * Capped at the most recent 500 entries to prevent unbounded memory use.
     */
    public function ledgerWithRunningBalance(int $distributorId, int $limit = 500): Collection
    {
        $entries = WalletLedgerEntry::where('distributor_id', $distributorId)
            ->whereNotIn('type', self::REPURCHASE_TYPES)
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

    /** Repurchase wallet ledger entries (credits deducted from payouts, debits applied at checkout). */
    public function repurchaseLedger(int $distributorId, int $limit = 200): Collection
    {
        return WalletLedgerEntry::where('distributor_id', $distributorId)
            ->whereIn('type', self::REPURCHASE_TYPES)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
