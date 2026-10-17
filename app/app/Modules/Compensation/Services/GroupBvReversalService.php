<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reverses the group (Genos) BV a cancelled or refunded order propagated up
 * the placement tree — the counterpart of {@see GroupBvAccumulatorService}.
 *
 * Rules (KP Q8, 2026-06-27 + Round-2/3 follow-ups):
 * - The reversal mirrors the WHOLE upline chain, debiting exactly the
 *   distributors the BV was originally credited to (group_bv_credits
 *   snapshot), on the same side — never a re-derived upline.
 * - Already-paid GSB is NOT clawed back. What today's (unsettled)
 *   group_bv_daily accumulator can absorb is netted off immediately; the
 *   remainder becomes a side-scoped debt (group_bv_debts) consumed by future
 *   propagation on that same side (negative-carry).
 * - Titles/ranks already earned are untouched (sticky).
 */
final class GroupBvReversalService
{
    public function reverseForOrder(Order $order, ?int $actorUserId = null): void
    {
        if ($order->attributed_distributor_id === null) {
            return;
        }

        // attempts=3: an InnoDB deadlock victim (concurrent propagation on a
        // shared ancestor) is rolled back wholesale and safely replayed —
        // every branch below is idempotent under the reversed_at guard.
        DB::transaction(function () use ($order, $actorUserId): void {
            $log = DB::table('bv_propagation_log')
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            // Not propagated yet (job still queued, or it never will run).
            // Insert the unique marker pre-reversed so a late PropagateGroupBvJob
            // hits the unique constraint and no-ops — the cancelled order's BV
            // then never reaches any accumulator and nothing needs reversing.
            // Deliberately NO unique-violation catch here: a violation means
            // the propagation committed between our SELECT and this INSERT,
            // and swallowing it would skip reversing that fresh credit — the
            // exception must bubble so the job retries and reverses it.
            if ($log === null) {
                $accrued = (int) BvLedgerEntry::where('order_id', $order->id)
                    ->where('type', BvLedgerEntry::TYPE_ACCRUAL)
                    ->sum('bv_paise');

                DB::table('bv_propagation_log')->insert([
                    'order_id' => $order->id,
                    'distributor_id' => $order->attributed_distributor_id,
                    'bv_paise' => $accrued,
                    'date' => Carbon::now()->toDateString(),
                    'reversed_at' => Carbon::now(),
                ]);

                return;
            }

            if ($log->reversed_at !== null) {
                return; // idempotent — already reversed
            }

            DB::table('bv_propagation_log')
                ->where('order_id', $order->id)
                ->update(['reversed_at' => Carbon::now()]);

            $credits = DB::table('group_bv_credits')
                ->where('order_id', $order->id)
                ->get();

            // A propagated order with BV but no credit snapshot means the
            // snapshot write was skipped (e.g. a stale pre-deploy queue
            // worker) — the upline keeps the cancelled BV until ops
            // reconciles. Loud warning, never a silent no-op.
            if ($credits->isEmpty() && (int) $log->bv_paise > 0) {
                Log::warning('gsb.bv_reversal.no_credit_snapshot', [
                    'order_id' => $order->id,
                    'bv_paise' => (int) $log->bv_paise,
                ]);
            }

            $totalReversed = 0;
            $totalAbsorbed = 0;
            $totalDebt = 0;
            $totalDebtRestored = 0;

            foreach ($credits as $credit) {
                $amount = (int) $credit->bv_paise;
                $ancestorId = (int) $credit->ancestor_id;

                // This order paid down an earlier cancellation's debt before
                // anything reached the accumulator. Cancelling it voids that
                // payment too, so the debt it cleared stands again — otherwise
                // two cancelled sales leave the ancestor BV neither one earned.
                $debtConsumed = (int) ($credit->debt_consumed_paise ?? 0);
                if ($debtConsumed > 0) {
                    $this->addDebt($ancestorId, $credit->side, $debtConsumed, $this->lockDebt($ancestorId, $credit->side));
                    $totalDebtRestored += $debtConsumed;
                }

                if ($amount <= 0) {
                    if ($debtConsumed > 0) {
                        // Swallowed whole by the debt: nothing reached the
                        // accumulator, but the restored debt is on record.
                        DB::table('group_bv_reversals')->insert([
                            'order_id' => $order->id,
                            'ancestor_id' => $ancestorId,
                            'side' => $credit->side,
                            'bv_paise' => 0,
                            'absorbed_paise' => 0,
                            'debt_paise' => $debtConsumed,
                            'date' => Carbon::now()->toDateString(),
                        ]);
                    }

                    continue;
                }

                $creditDate = Carbon::parse((string) $credit->date)->toDateString();
                ['absorbed' => $absorbed, 'debt' => $debt, 'date' => $absorbDate] = $this->reverseSideCredit($ancestorId, $credit->side, $amount, $creditDate);

                DB::table('group_bv_reversals')->insert([
                    'order_id' => $order->id,
                    'ancestor_id' => $ancestorId,
                    'side' => $credit->side,
                    'bv_paise' => $amount,
                    'absorbed_paise' => $absorbed,
                    'debt_paise' => $debt,
                    'date' => $absorbDate,
                ]);

                $totalReversed += $amount;
                $totalAbsorbed += $absorbed;
                $totalDebt += $debt;
            }

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'bv.group_reversed',
                'subject_type' => 'order',
                'subject_id' => $order->id,
                'details' => [
                    'order_no' => $order->order_no,
                    'ancestors' => $credits->count(),
                    'bv_paise' => $totalReversed,
                    'absorbed_paise' => $totalAbsorbed,
                    'debt_paise' => $totalDebt,
                    'debt_restored_paise' => $totalDebtRestored,
                ],
            ]);
        }, attempts: 3);
    }

    /**
     * Take back BV once credited to one side of one distributor's Genos.
     * Callers hold a transaction.
     *
     * Absorb from the day the BV was credited while that day is still
     * unsettled (cut-off not yet run, or failed) so a late settlement can
     * never match cancelled BV; once the day is settled, absorb from today's
     * open accumulator. Whatever the accumulator cannot cover becomes a
     * same-side forward debt. A day the cut-off discarded outright — below
     * the 600 BV gate, or forfeited for a failed repurchase cycle — kept none
     * of the BV, so it never creates a debt; a forfeited day that may still be
     * re-run has the BV taken off its own row.
     *
     * Also the reversal path for a cancelled personal-BV top-up
     * ({@see GsbPersonalBvTopupService::reverseForOrder()}): the same BV, the
     * same stores, one rule.
     *
     * @return array{absorbed: int, debt: int, date: string}
     */
    public function reverseSideCredit(int $distributorId, string $side, int $amount, string $creditDate): array
    {
        $todayStr = Carbon::now()->toDateString();
        $settledStatus = $creditDate !== $todayStr
            ? $this->settledStatus($distributorId, $creditDate)
            : null;

        if ($settledStatus === GsbCutoffResult::STATUS_BELOW_600BV) {
            return ['absorbed' => 0, 'debt' => 0, 'date' => $todayStr];
        }

        if ($settledStatus === GsbCutoffResult::STATUS_REPURCHASE_FORFEITED) {
            // A forfeited day is re-run as eligible when the cycle behind it
            // is re-resolved and nothing later has run (GsbCutoffService's
            // out-of-order guard), and it then reads its own accumulator — so
            // take the cancelled BV off that day while it still could be. Once
            // a later day has run the forfeit is final and the BV was never
            // kept. Never a debt either way.
            $laterRun = DB::table('gsb_cutoff_results')
                ->where('distributor_id', $distributorId)
                ->where('cutoff_date', '>', $creditDate)
                ->exists();

            $absorbed = $laterRun ? 0 : $this->absorbFromAccumulator($distributorId, $side, $amount, $creditDate);

            return ['absorbed' => $absorbed, 'debt' => 0, 'date' => $creditDate];
        }

        $absorbDate = $settledStatus === null ? $creditDate : $todayStr;

        // Lock order matches GroupBvAccumulatorService (debts → daily) so a
        // concurrent propagation and reversal on the same ancestor+side
        // cannot deadlock on crossed locks.
        $lockedDebt = $this->lockDebt($distributorId, $side);
        $absorbed = $this->absorbFromAccumulator($distributorId, $side, $amount, $absorbDate);
        $debt = $amount - $absorbed;

        if ($debt > 0) {
            $this->addDebt($distributorId, $side, $debt, $lockedDebt);
        }

        return ['absorbed' => $absorbed, 'debt' => $debt, 'date' => $absorbDate];
    }

    /**
     * The standing settlement status of this distributor's cut-off for the
     * given day, or null when the day is still unsettled. A missing row or a
     * FAILED run means the day will still be (re)processed, so its
     * accumulator is safe — and necessary — to debit directly.
     *
     * A REVERSED row is settled: the admin reversal debited the wallet but
     * kept the day's carry-forward advance, and the cut-off treats the row as
     * terminal ({@see GsbCutoffService::computeForDistributor()}), so the
     * day's accumulator is never read again — debiting it would let the
     * cancelled BV stand in the carry-forward.
     */
    private function settledStatus(int $distributorId, string $dateStr): ?string
    {
        $status = DB::table('gsb_cutoff_results')
            ->where('distributor_id', $distributorId)
            ->where('cutoff_date', $dateStr)
            ->value('status');

        if ($status === null || $status === GsbCutoffResult::STATUS_FAILED) {
            return null;
        }

        return (string) $status;
    }

    /**
     * Net the reversal off an unsettled day's accumulator, up to the side's
     * current balance — the columns are unsigned and a settled day is never
     * reopened. Returns the amount absorbed.
     */
    private function absorbFromAccumulator(int $distributorId, string $side, int $amount, string $dateStr): int
    {
        $column = $side === 'L' ? 'left_bv_paise' : 'right_bv_paise';

        $row = DB::table('group_bv_daily')
            ->where('distributor_id', $distributorId)
            ->where('date', $dateStr)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            return 0;
        }

        $absorbed = min($amount, (int) $row->{$column});
        if ($absorbed > 0) {
            DB::table('group_bv_daily')
                ->where('id', $row->id)
                ->update([$column => (int) $row->{$column} - $absorbed]);
        }

        return $absorbed;
    }

    /**
     * Lock this ancestor's debt row for the side (null when none exists yet).
     * Taken BEFORE the group_bv_daily lock to mirror the accumulator's
     * canonical lock order.
     */
    private function lockDebt(int $distributorId, string $side): ?\stdClass
    {
        return DB::table('group_bv_debts')
            ->where('distributor_id', $distributorId)
            ->where('side', $side)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Add (or increase) the side-scoped forward debt against the row locked
     * by {@see lockDebt()}. Same optimistic insert / unique-violation
     * fallback as the accumulator upsert when no row existed.
     */
    private function addDebt(int $distributorId, string $side, int $debtPaise, ?\stdClass $existing): void
    {
        if ($existing !== null) {
            DB::table('group_bv_debts')
                ->where('id', $existing->id)
                ->update([
                    'bv_paise' => (int) $existing->bv_paise + $debtPaise,
                    'updated_at' => Carbon::now(),
                ]);

            return;
        }

        try {
            DB::table('group_bv_debts')->insert([
                'distributor_id' => $distributorId,
                'side' => $side,
                'bv_paise' => $debtPaise,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } catch (QueryException $e) {
            // Another reversal won the insert race; add our delta instead.
            if (! str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }

            DB::table('group_bv_debts')
                ->where('distributor_id', $distributorId)
                ->where('side', $side)
                ->increment('bv_paise', $debtPaise, ['updated_at' => Carbon::now()]);
        }
    }
}
