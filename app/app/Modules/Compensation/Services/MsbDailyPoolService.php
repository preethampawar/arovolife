<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\MsbDailyPool;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Freezes the per-day economics of the Mentorship Bonus pool (KP 2026-07-30).
 *
 * Pool = comp.msb.pool_rate_bp (default 3%) of the day's company-wide BV, using
 * the SAME definition as the GSB pool (GsbDailyPoolService::companyBvPaiseForDate
 * is reused rather than reimplemented, so the two pools can never disagree on
 * what a day's BV was).
 *
 * Point value = pool ÷ the day's total MSB score points, floored to whole
 * rupees. There is no per-slab configured value and no cap any more: every
 * earner is paid their points × this one value, so the day's MSB spend is
 * exactly the 3% envelope minus the flooring remainder.
 *
 *   KP's worked example: 1,00,000 BV → ₹3,000 pool; two slab-1 matches (21+21)
 *   and one slab-2 match (18) = 60 points → ₹50/point → ₹1,050 + ₹1,050 + ₹900
 *   = ₹3,000 exactly.
 *
 * Both the pool and the value are clamped at 0. Company BV is a SIGNED sum, so
 * a refund-heavy day can be negative, and intdiv() truncates toward zero — a
 * negative pool would otherwise freeze a negative point value and hand a
 * negative amount to WalletService::credit().
 *
 * A day on which nobody accrued points freezes a ₹0 value (product decision
 * 2026-07-30): the pool simply goes unspent and a later admin retry on that day
 * pays ₹0 rather than handing the whole day's pool to whoever is retried first.
 * Note this is the opposite of the GSB pool's zero-achiever rule, where the cap
 * is frozen so a retry pays full value.
 *
 * The row is written once per date and NEVER recomputed (frozen economics):
 * re-runs and single-distributor retries price against the stored
 * point_value_paise. leftover_paise is normally the small positive flooring
 * remainder, but goes negative when a retry credits points that were not in the
 * frozen denominator — the company absorbs it, mirroring the GSB pool.
 */
final class MsbDailyPoolService
{
    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
        private readonly GsbDailyPoolService $gsbPool,
    ) {}

    /** The frozen pool snapshot for a date, or null for dates never run with MSB on. */
    public function poolForDate(Carbon $date): ?MsbDailyPool
    {
        return MsbDailyPool::whereDate('cutoff_date', $date->toDateString())->first();
    }

    /**
     * Freeze the day's pool economics. Idempotent: an existing row is returned
     * unchanged — the day's economics never move once written, no matter how
     * often the cut-off is re-run.
     *
     * @param  int  $totalPoints  Σ MSB score points over the day's creditable accruals
     */
    public function freezePoolForDate(Carbon $date, int $totalPoints): MsbDailyPool
    {
        $existing = $this->poolForDate($date);
        if ($existing !== null) {
            return $existing;
        }

        $companyBvPaise = $this->gsbPool->companyBvPaiseForDate($date);
        $rateBp = $this->plan->msbPoolRateBp();
        $poolPaise = max(0, intdiv($companyBvPaise * $rateBp, 10_000));

        // Floor to whole rupees (KP: 3,000 ÷ 60 = 50): truncate the per-point
        // paise value to a multiple of 100. max() guards a negative-BV day.
        $valuePaise = $totalPoints === 0
            ? 0
            : max(0, intdiv(intdiv($poolPaise, $totalPoints), 100) * 100);

        $payoutPaise = $valuePaise * $totalPoints;

        $pool = MsbDailyPool::create([
            'cutoff_date' => $date->toDateString(),
            'company_bv_paise' => $companyBvPaise,
            'pool_rate_bp' => $rateBp,
            'pool_paise' => $poolPaise,
            'total_points' => $totalPoints,
            'point_value_paise' => $valuePaise,
            'payout_paise' => $payoutPaise,
            'leftover_paise' => $poolPaise - $payoutPaise,
        ]);

        $details = [
            'cutoff_date' => $date->toDateString(),
            'company_bv_paise' => $companyBvPaise,
            'pool_rate_bp' => $rateBp,
            'pool_paise' => $poolPaise,
            'total_points' => $totalPoints,
            'point_value_paise' => $valuePaise,
            'payout_paise' => $payoutPaise,
            'leftover_paise' => $pool->leftover_paise,
        ];

        Log::info('msb.pool.frozen', $details);

        // The freeze determines every MSB payout for the day — a
        // retention-guaranteed audit_log row, not just a log line (R-35).
        AuditLog::create([
            'action' => 'msb.pool.frozen',
            'subject_type' => 'msb_daily_pool',
            'subject_id' => $pool->id,
            'details' => $details,
        ]);

        return $pool;
    }
}
