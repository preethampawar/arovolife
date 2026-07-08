<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\GsbPersonalBvTopup;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies each distributor's own day-of personal order BV to their weaker
 * Genos leg before the nightly GSB cut-off, and reverses that credit if the
 * originating order is later cancelled (within the cooling-off period).
 *
 * Design rules (plan: purring-exploring-wand, 2026-07-08):
 * - Weaker side is determined ONCE using the full day's sum — never re-derived
 *   per order (prevents the side flipping mid-apply).
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
    /**
     * Apply today's personal BV orders as a weaker-leg topup for the given
     * distributor before their GSB cut-off runs.
     *
     * Called by GsbDailyCutoffCommand immediately before GsbCutoffService for
     * each distributor.
     */
    public function applyForDistributor(int $distributorId, Carbon $date): void
    {
        $dateStr = $date->toDateString();

        // Collect today's accruals not yet topped up.
        $alreadyApplied = GsbPersonalBvTopup::where('distributor_id', $distributorId)
            ->whereDate('date', $dateStr)
            ->pluck('order_id')
            ->all();

        $accruals = BvLedgerEntry::where('distributor_id', $distributorId)
            ->where('type', BvLedgerEntry::TYPE_ACCRUAL)
            ->whereDate('effective_at', $dateStr)
            ->when($alreadyApplied !== [], fn ($q) => $q->whereNotIn('order_id', $alreadyApplied))
            ->get(['order_id', 'bv_paise']);

        if ($accruals->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($distributorId, $dateStr, $accruals): void {
            // Lock the accumulator row (or note absence) to determine weaker side
            // and prevent races with concurrent BV propagation.
            $daily = DB::table('group_bv_daily')
                ->where('distributor_id', $distributorId)
                ->whereDate('date', $dateStr)
                ->lockForUpdate()
                ->first();

            $leftBv = (int) ($daily->left_bv_paise ?? 0);
            $rightBv = (int) ($daily->right_bv_paise ?? 0);

            // Weaker side determined once for the full day's sum.
            $weakerSide = $leftBv <= $rightBv ? 'L' : 'R';
            $sideColumn = $weakerSide === 'L' ? 'left_bv_paise' : 'right_bv_paise';

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
                    // No group BV yet for today — create the row.
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
            }
        });
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
