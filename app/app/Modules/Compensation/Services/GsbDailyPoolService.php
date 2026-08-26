<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\GsbDailyPool;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Freezes the per-day economics of the GSB pro-rated pool (KP 2026-07-29).
 *
 * Pool = comp.gsb.pool_rate_bp (default 45%) of the day's company-wide BV.
 * Company BV is DEFINED as the signed sum of bv_ledger_entries (accruals minus
 * reversals) whose effective_at falls on the cut-off date — the same instant
 * BV enters the Genos legs (accrual is stamped at paid-time, and
 * PropagateGroupBvJob keys group_bv_daily on the paid date). A refund therefore
 * reduces the refund day's pool, while leg debt is consumed forward by
 * GroupBvReversalService — a deliberate, auditable definition rather than an
 * identity with leg BV.
 *
 * Slabs 1–2 are paid fixed out of the pool first. The remainder ÷ the day's
 * total slab 3–7 scores, floored to whole rupees and capped at the fixed
 * per-slab score value, is the variable score value for every slab 3–7
 * achiever. Zero slab 3–7 achievers freeze the cap itself (KP decision: a
 * later admin retry that lands on slab 3–7 pays full value — the pool had
 * room, nobody used it). The value is never negative and never above the cap;
 * ₹0 is possible on a starved day.
 *
 * The row is written once per date and NEVER recomputed (frozen economics):
 * re-runs and single-distributor retries price against the stored
 * variable_score_value_paise. leftover_paise may be negative on a starved day
 * because slabs 1–2 are always paid in full (KP-approved 45% overshoot).
 */
final class GsbDailyPoolService
{
    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    /** First GSB slab priced from the daily pool; slabs below it are fixed. */
    public const FIRST_VARIABLE_SLAB = 3;

    public static function isVariableSlab(int $slab): bool
    {
        return $slab >= self::FIRST_VARIABLE_SLAB;
    }

    /**
     * Company-wide BV generated on the given date: signed sum of
     * bv_ledger_entries (accruals +, reversals −) by effective_at day.
     */
    public function companyBvPaiseForDate(Carbon $date): int
    {
        return (int) DB::table('bv_ledger_entries')
            ->where('effective_at', '>=', $date->copy()->startOfDay())
            ->where('effective_at', '<=', $date->copy()->endOfDay())
            ->sum('bv_paise');
    }

    /**
     * Company-wide BV generated across an inclusive date range: the same signed
     * bv_ledger_entries sum as {@see companyBvPaiseForDate()}, widened to a
     * period. This is the canonical "company turnover" for the MONTHLY pools
     * (Rank Bonus envelope, Growth Booster) — user-confirmed 2026-08-05 that
     * turnover means BV, not order sales value, so every pool in the plan
     * measures the same thing and they can never disagree.
     */
    public function companyBvPaiseBetween(Carbon $from, Carbon $to): int
    {
        return (int) DB::table('bv_ledger_entries')
            ->where('effective_at', '>=', $from->copy()->startOfDay())
            ->where('effective_at', '<=', $to->copy()->endOfDay())
            ->sum('bv_paise');
    }

    /** The frozen pool snapshot for a date, or null for pre-feature dates. */
    public function poolForDate(Carbon $date): ?GsbDailyPool
    {
        return GsbDailyPool::whereDate('cutoff_date', $date->toDateString())->first();
    }

    /**
     * Freeze the day's pool economics. Idempotent: an existing row is returned
     * unchanged — the day's economics never move once written, no matter how
     * often the cut-off is re-run.
     *
     * @param  int  $fixedPayoutPaise  Σ score × fixed score value over the day's matched slab 1–2 computations
     * @param  int  $variableTotalScore  Σ score over the day's matched slab 3–7 computations
     */
    public function freezePoolForDate(Carbon $date, int $fixedPayoutPaise, int $variableTotalScore): GsbDailyPool
    {
        $existing = $this->poolForDate($date);
        if ($existing !== null && ! $this->replacePrematureFreeze($existing)) {
            return $existing;
        }

        $companyBvPaise = $this->companyBvPaiseForDate($date);
        $rateBp = $this->plan->gsbPoolRateBp();
        $poolPaise = intdiv($companyBvPaise * $rateBp, 10_000);
        $capPaise = $this->variableScoreValueCapPaise();

        $remainderPaise = max(0, $poolPaise - $fixedPayoutPaise);

        if ($variableTotalScore === 0) {
            $valuePaise = $capPaise;
        } else {
            // Floor to whole rupees (KP: 220.79 → ₹220): truncate the per-score
            // paise value to a multiple of 100.
            $valuePaise = min($capPaise, intdiv(intdiv($remainderPaise, $variableTotalScore), 100) * 100);
        }

        $variablePayoutPaise = $valuePaise * $variableTotalScore;

        $pool = GsbDailyPool::create([
            'cutoff_date' => $date->toDateString(),
            'company_bv_paise' => $companyBvPaise,
            'pool_rate_bp' => $rateBp,
            'pool_paise' => $poolPaise,
            'fixed_payout_paise' => $fixedPayoutPaise,
            'variable_total_score' => $variableTotalScore,
            'variable_score_value_cap_paise' => $capPaise,
            'variable_score_value_paise' => $valuePaise,
            'variable_payout_paise' => $variablePayoutPaise,
            'leftover_paise' => $poolPaise - $fixedPayoutPaise - $variablePayoutPaise,
        ]);

        $details = [
            'cutoff_date' => $date->toDateString(),
            'company_bv_paise' => $companyBvPaise,
            'pool_rate_bp' => $rateBp,
            'pool_paise' => $poolPaise,
            'fixed_payout_paise' => $fixedPayoutPaise,
            'variable_total_score' => $variableTotalScore,
            'variable_score_value_paise' => $valuePaise,
            'leftover_paise' => $pool->leftover_paise,
        ];

        Log::info('gsb.pool.frozen', $details);

        // The freeze determines every slab 3–7 payout for the day — a
        // retention-guaranteed audit_log row, not just a log line (R-33).
        AuditLog::create([
            'action' => 'gsb.pool.frozen',
            'subject_type' => 'gsb_daily_pool',
            'subject_id' => $pool->id,
            'details' => $details,
        ]);

        return $pool;
    }

    /**
     * Delete a pool row that was PROVABLY frozen too early, so the caller can
     * freeze the day afresh. Returns true when the row was removed.
     *
     * "Frozen economics" assumes the freeze happened once the day's BV was
     * final — the scheduler guarantees that by running at 00:10 the next day.
     * A freeze whose created_at falls BEFORE the day ended broke that
     * assumption (a mid-day manual run, or the recompute tool's catch-up of
     * the period in flight): it snapshotted partial company BV and a partial
     * achiever count, and every later run for the date would silently price
     * against it. Staging, 24 Aug 2026: a 23:27 manual cut-off froze the day
     * at ₹0 / zero achievers, and the scheduled run then paid 7 achievers the
     * capped value out of an empty pool.
     *
     * Replacement is only safe while NOTHING was funded by the row: once any
     * result for the date carries pool-priced gross, re-freezing would change
     * economics that money already moved on, so the row is kept and the
     * inconsistency surfaced loudly instead.
     */
    private function replacePrematureFreeze(GsbDailyPool $existing): bool
    {
        $dayEnd = $existing->cutoff_date->copy()->addDay()->startOfDay();
        if ($existing->created_at === null || $existing->created_at->gte($dayEnd)) {
            return false; // Frozen after the day closed — the normal, final row.
        }

        $details = [
            'cutoff_date' => $existing->cutoff_date->toDateString(),
            'frozen_at' => $existing->created_at->toDateTimeString(),
            'company_bv_paise' => $existing->company_bv_paise,
            'pool_paise' => $existing->pool_paise,
            'variable_total_score' => $existing->variable_total_score,
            'variable_score_value_paise' => $existing->variable_score_value_paise,
        ];

        if (GsbCutoffResult::whereDate('cutoff_date', $existing->cutoff_date->toDateString())
            ->whereIn('status', GsbCutoffResult::POOL_FUNDED_STATUSES)
            ->exists()) {
            Log::warning('gsb.pool.premature_freeze_kept', $details + [
                'reason' => 'results were already priced against this pool; re-freezing would change economics money moved on',
            ]);

            return false;
        }

        Log::warning('gsb.pool.premature_freeze_replaced', $details);

        AuditLog::create([
            'action' => 'gsb.pool.refrozen',
            'subject_type' => 'gsb_daily_pool',
            'subject_id' => $existing->id,
            'details' => $details + [
                'reason' => 'pool was frozen before the day ended and nothing was priced against it',
            ],
        ]);

        $existing->delete();

        return true;
    }

    /**
     * Ceiling for the variable score value: the smallest fixed score value
     * across active, payable slabs 3–7 (all ₹250 by default). Pro-rating can
     * only ever reduce a slab 3–7 payout, never raise it above the fixed value.
     */
    private function variableScoreValueCapPaise(): int
    {
        $cap = null;
        foreach ($this->plan->gsbSlabs() as $slab) {
            if (! self::isVariableSlab($slab['slab']) || ! $slab['is_active'] || $slab['bonus_paise'] === null) {
                continue;
            }
            $cap = $cap === null ? $slab['score_value_paise'] : min($cap, $slab['score_value_paise']);
        }

        if ($cap === null) {
            // No active payable slab 3–7 ⇒ a ₹0 cap freezes ₹0 pricing for the
            // whole day. Legitimate only if the slabs were deliberately
            // deactivated — surface it loudly either way.
            Log::warning('gsb.pool.zero_cap', ['reason' => 'no active payable slab >= 3 in gsb_slabs']);
        }

        return $cap ?? 0;
    }
}
