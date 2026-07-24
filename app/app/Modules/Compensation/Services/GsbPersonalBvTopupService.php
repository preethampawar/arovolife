<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\GsbPersonalBvTopup;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a distributor's own accumulated personal-purchase BV to their weaker
 * Genos leg during the nightly GSB cut-off, and reverses that credit if the
 * originating order is later cancelled (within the cooling-off period).
 *
 * Conditional top-up (KP 2026-07-21): personal BV is NOT credited every day.
 * It accumulates (pending) and is credited only when GsbCutoffService decides a
 * leg has touched a slab threshold — the service calls
 * {@see applyPendingForDistributor()} with the weaker side it has already
 * determined (CF-aware, with the equal-sides tie-break). The distributor's real
 * lifetime personal BV (bv_ledger_entries) is never mutated here — "pending" is
 * derived as accruals in the go-live window not yet recorded in this ledger.
 *
 * Design rules (retained from 2026-07-08):
 * - Weaker side is fixed by the caller for the whole apply — never flips per order.
 * - Every write to group_bv_daily is guarded by SELECT FOR UPDATE so concurrent
 *   propagation jobs don't race with the topup.
 * - Idempotent via UNIQUE(order_id) on gsb_personal_bv_topups — a retry never
 *   double-credits.
 * - Reversal mirrors GroupBvReversalService: if the cut-off date is unsettled,
 *   deduct from that date's accumulator; if already settled with a payout,
 *   deduct from today's accumulator (no clawback of paid bonuses).
 */
final class GsbPersonalBvTopupService
{
    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    /**
     * Total pending (uncredited) personal-purchase BV for a distributor as of the
     * given cut-off date — accruals in the go-live window that have neither been
     * topped up nor reversed. Read-only; used by the cut-off trigger preview and
     * the distributor slab-progress UI.
     */
    public function pendingBvPaise(int $distributorId, Carbon $cutoffDate): int
    {
        return (int) $this->pendingAccruals($distributorId, $cutoffDate)->sum('bv_paise');
    }

    /**
     * Credit all pending personal-purchase BV to the given weaker leg for this
     * cut-off date. Returns the paise credited (0 if nothing was pending).
     *
     * Called by GsbCutoffService only when a leg has touched a slab threshold.
     */
    public function applyPendingForDistributor(int $distributorId, Carbon $cutoffDate, string $weakerSide): int
    {
        $accruals = $this->pendingAccruals($distributorId, $cutoffDate);

        if ($accruals->isEmpty()) {
            return 0;
        }

        $dateStr = $cutoffDate->toDateString();
        $sideColumn = $weakerSide === 'L' ? 'left_bv_paise' : 'right_bv_paise';
        $creditedPaise = 0;

        DB::transaction(function () use ($distributorId, $dateStr, $accruals, $weakerSide, $sideColumn, &$creditedPaise): void {
            // Lock the accumulator row (or note absence) to prevent races with
            // concurrent BV propagation.
            $daily = DB::table('group_bv_daily')
                ->where('distributor_id', $distributorId)
                ->whereDate('date', $dateStr)
                ->lockForUpdate()
                ->first();

            foreach ($accruals as $accrual) {
                $bvPaise = (int) $accrual->bv_paise;
                if ($bvPaise <= 0) {
                    continue;
                }

                // Insert topup ledger row — skip silently on unique violation
                // (idempotent: the order was already topped up by a concurrent run).
                try {
                    GsbPersonalBvTopup::create([
                        'distributor_id' => $distributorId,
                        'order_id' => (int) $accrual->order_id,
                        'bv_paise' => $bvPaise,
                        'side' => $weakerSide,
                        'date' => $dateStr,
                        'created_at' => Carbon::now(),
                    ]);
                } catch (QueryException $e) {
                    if (! str_contains($e->getMessage(), 'Duplicate entry')) {
                        throw $e;
                    }

                    continue; // already applied in a concurrent run
                }

                // Credit the weaker-side accumulator.
                if ($daily !== null) {
                    DB::table('group_bv_daily')
                        ->where('distributor_id', $distributorId)
                        ->whereDate('date', $dateStr)
                        ->increment($sideColumn, $bvPaise);
                } else {
                    // No group BV yet for this date — create the row.
                    try {
                        DB::table('group_bv_daily')->insert([
                            'distributor_id' => $distributorId,
                            'date' => $dateStr,
                            'left_bv_paise' => $weakerSide === 'L' ? $bvPaise : 0,
                            'right_bv_paise' => $weakerSide === 'R' ? $bvPaise : 0,
                            'updated_at' => Carbon::now(),
                        ]);
                        // Mark $daily as non-null so subsequent orders in this loop
                        // use INCREMENT rather than trying to INSERT again.
                        $daily = (object) ['left_bv_paise' => 0, 'right_bv_paise' => 0];
                    } catch (QueryException $e) {
                        if (! str_contains($e->getMessage(), 'Duplicate entry')) {
                            throw $e;
                        }
                        // Concurrent insert won — fall back to increment.
                        DB::table('group_bv_daily')
                            ->where('distributor_id', $distributorId)
                            ->where('date', $dateStr)
                            ->increment($sideColumn, $bvPaise);
                        $daily = (object) ['left_bv_paise' => 0, 'right_bv_paise' => 0];
                    }
                }

                $creditedPaise += $bvPaise;
            }
        });

        return $creditedPaise;
    }

    /**
     * The distributor's uncredited personal-purchase accruals as of a cut-off
     * date: type=accrual, effective_at within [go-live, end of cut-off date],
     * whose order has neither been topped up already (UNIQUE order_id) nor
     * reversed by a cancellation. One accrual row per personal order.
     *
     * @return Collection<int, BvLedgerEntry>
     */
    private function pendingAccruals(int $distributorId, Carbon $cutoffDate): Collection
    {
        $goLive = $this->plan->gsbTopupGoliveDate();
        $endOfDay = $cutoffDate->copy()->endOfDay();

        $toppedOrderIds = GsbPersonalBvTopup::where('distributor_id', $distributorId)
            ->pluck('order_id')
            ->all();

        // Orders reversed (cancelled/refunded) never enter the pending pool —
        // their BV is void even if they were never topped up.
        $reversedOrderIds = BvLedgerEntry::where('distributor_id', $distributorId)
            ->where('type', BvLedgerEntry::TYPE_REVERSAL)
            ->pluck('order_id')
            ->all();

        $excluded = array_values(array_unique([...$toppedOrderIds, ...$reversedOrderIds]));

        return BvLedgerEntry::where('distributor_id', $distributorId)
            ->where('type', BvLedgerEntry::TYPE_ACCRUAL)
            ->where('effective_at', '>=', $goLive)
            ->where('effective_at', '<=', $endOfDay)
            ->when($excluded !== [], fn ($q) => $q->whereNotIn('order_id', $excluded))
            ->get(['order_id', 'bv_paise']);
    }

    /**
     * Reverse any topups recorded for the given order when it is cancelled or
     * refunded. Called from ReverseGroupBvJob alongside GroupBvReversalService.
     *
     * Already-paid GSB is never clawed back: if the cut-off date is settled,
     * the deduction targets today's accumulator on the same side (forward debt
     * pattern). If the cut-off date is unsettled the deduction targets that
     * date's accumulator directly (no BV ever paid out for it).
     */
    public function reverseForOrder(int $orderId): void
    {
        $topups = GsbPersonalBvTopup::where('order_id', $orderId)
            ->whereNull('reversed_at')
            ->get();

        if ($topups->isEmpty()) {
            return;
        }

        foreach ($topups as $topup) {
            DB::transaction(function () use ($topup): void {
                $settled = $this->cutoffIsSettled($topup->distributor_id, $topup->date);

                if ($settled) {
                    // Settled (GSB paid) — reduce today's accumulator so future
                    // cut-offs reflect the cancelled BV.
                    $today = Carbon::today('Asia/Kolkata')->toDateString();
                    $sideColumn = $topup->side === 'L' ? 'left_bv_paise' : 'right_bv_paise';

                    $existing = DB::table('group_bv_daily')
                        ->where('distributor_id', $topup->distributor_id)
                        ->whereDate('date', $today)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        DB::table('group_bv_daily')
                            ->where('distributor_id', $topup->distributor_id)
                            ->whereDate('date', $today)
                            ->decrement($sideColumn, $topup->bv_paise);
                    }
                    // If today's row doesn't exist yet the topup simply won't
                    // inflate it — no action needed.
                } else {
                    // Unsettled — directly reduce the topup date's accumulator.
                    $sideColumn = $topup->side === 'L' ? 'left_bv_paise' : 'right_bv_paise';

                    DB::table('group_bv_daily')
                        ->where('distributor_id', $topup->distributor_id)
                        ->whereDate('date', $topup->date->toDateString())
                        ->lockForUpdate()
                        ->first(); // acquire lock

                    DB::table('group_bv_daily')
                        ->where('distributor_id', $topup->distributor_id)
                        ->whereDate('date', $topup->date->toDateString())
                        ->decrement($sideColumn, $topup->bv_paise);
                }

                $topup->reversed_at = Carbon::now();
                $topup->save();

                AuditLog::create([
                    'action' => 'gsb.personal_bv_topup.reversed',
                    'subject_type' => 'gsb_personal_bv_topup',
                    'subject_id' => $topup->id,
                    'details' => [
                        'order_id' => $topup->order_id,
                        'distributor_id' => $topup->distributor_id,
                        'bv_paise' => $topup->bv_paise,
                        'side' => $topup->side,
                        'date' => $topup->date->toDateString(),
                        'settled' => $settled,
                    ],
                ]);
            });
        }
    }

    /**
     * Returns whether the GSB cut-off for the given distributor on the given
     * date resulted in a payout that must not be clawed back.
     */
    private function cutoffIsSettled(int $distributorId, Carbon $date): bool
    {
        $result = GsbCutoffResult::where('distributor_id', $distributorId)
            ->whereDate('cutoff_date', $date->toDateString())
            ->value('status');

        return in_array($result, [
            GsbCutoffResult::STATUS_CREDITED,
            GsbCutoffResult::STATUS_FROZEN,
            GsbCutoffResult::STATUS_REPURCHASE_HELD,
            GsbCutoffResult::STATUS_REPURCHASE_SUSPENDED,
        ], true);
    }
}
