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

            $todayStr = Carbon::now()->toDateString();
            $totalReversed = 0;
            $totalAbsorbed = 0;
            $totalDebt = 0;

            foreach ($credits as $credit) {
                $amount = (int) $credit->bv_paise;
                if ($amount <= 0) {
                    continue;
                }

                $ancestorId = (int) $credit->ancestor_id;

                // Absorb from the day the BV was credited while that day is
                // still unsettled (cut-off not yet run, failed, or reversed)
                // so a late settlement can never match cancelled BV; once the
                // day is settled, absorb from today's open accumulator.
                $creditDate = Carbon::parse((string) $credit->date)->toDateString();
                $settledStatus = $creditDate !== $todayStr
                    ? $this->settledStatus($ancestorId, $creditDate)
                    : null;

                if ($settledStatus === GsbCutoffResult::STATUS_BELOW_600BV) {
                    // The cut-off discarded this ancestor's whole day (below
                    // the personal-BV gate): the credited BV was never kept,
                    // never carried forward — so there is nothing to recover.
                    // Creating a debt here would deduct BV they never had.
                    $absorbed = 0;
                    $debt = 0;
                    $absorbDate = $todayStr;
                } else {
                    $absorbDate = $settledStatus === null ? $creditDate : $todayStr;

                    // Lock order matches GroupBvAccumulatorService (debts →
                    // daily) so a concurrent propagation and reversal on the
                    // same ancestor+side cannot deadlock on crossed locks.
                    $lockedDebt = $this->lockDebt($ancestorId, $credit->side);
                    $absorbed = $this->absorbFromAccumulator($ancestorId, $credit->side, $amount, $absorbDate);
                    $debt = $amount - $absorbed;

                    if ($debt > 0) {
                        $this->addDebt($ancestorId, $credit->side, $debt, $lockedDebt);
                    }
                }

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
                ],
            ]);
        }, attempts: 3);
    }

    /**
     * The standing settlement status of this distributor's cut-off for the
     * given day, or null when the day is still unsettled. A missing row, a
     * FAILED run, or a REVERSED (awaiting recalculate) result all mean the
     * day will still be (re)processed, so its accumulator is safe — and
     * necessary — to debit directly.
     */
    private function settledStatus(int $distributorId, string $dateStr): ?string
    {
        $status = DB::table('gsb_cutoff_results')
            ->where('distributor_id', $distributorId)
            ->where('cutoff_date', $dateStr)
            ->value('status');

        if ($status === null
            || $status === GsbCutoffResult::STATUS_FAILED
            || $status === GsbCutoffResult::STATUS_REVERSED) {
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
