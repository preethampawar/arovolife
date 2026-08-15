<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Models\GbbMonthlyPool;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Growth Booster Bonus engine. Runs once per calendar month.
 *
 * POOL BASE — comp.gbb.pool_rate_bp (default 5%) of the month's company-wide
 * BV, read through GsbDailyPoolService::companyBvPaiseBetween() so GBB, GSB and
 * MSB can never disagree on what a period's BV was. (This replaced the old
 * "5% of order sales value" base: every bonus pool is a BV pool.)
 *
 * ENTITLEMENT — Arovolife Growth Points (AGP), earned from credited GSB
 * cut-offs: 1st slab → 12 AGP, 2nd → 5, 3rd → 2, 4th–7th → 0, capped per
 * distributor at comp.gbb.agp_cap (120). Per-slab AGP lives in the
 * admin-editable gsb_slabs table.
 *
 * RANK GATE — GBB rewards distributors who are still building. Anyone who held
 * a QUALIFIED rank in the PREVIOUS month is excluded outright: no AGP counted,
 * no row, no credit. Carry-forward qualifications count as "ranked" (a paid
 * carry row still means ranked — same precedent as AogoOfferService). Read
 * literally, this means a first-time ranker keeps the current month's GBB (they
 * had no prior-month row), and someone who ranked two months ago but not last
 * month becomes eligible again.
 *
 * REPURCHASE — KP 2026-06-28: GSB, Fortune and Growth Booster are suspended
 * together on repurchase non-compliance (never Mentorship/Rank).
 *   • grace window (HOLD)  → the bonus IS calculated at the frozen point value
 *     and persisted as {@see GbbMonthlyResult::STATUS_REPURCHASE_HELD} with no
 *     wallet credit; the AGP STAYS in the denominator because the row can still
 *     be released and paid by ReleaseHeldGbbOnReactivation.
 *   • post-grace (BLOCKED) → an audit-only
 *     {@see GbbMonthlyResult::STATUS_REPURCHASE_SUSPENDED} row records the AGP
 *     earned with gross 0, and the AGP is EXCLUDED from the denominator: the
 *     month can never pay it, so it must not dilute everyone else's point value
 *     (the MSB rule — only payable participants dilute the pool).
 *
 * FROZEN ECONOMICS — the month's pool, denominator and point value are written
 * once to gbb_monthly_pools BEFORE any credit and never recomputed. A re-run
 * after more BV or more cut-offs have landed prices against that snapshot, so
 * the month's economics never move under a distributor who was already paid. A
 * month in which nobody earned AGP still freezes a ₹0-value row and the pool
 * simply goes unspent (same product decision as the MSB pool).
 *
 * Deductions (admin charge, TDS) are applied at payout time, not at credit time.
 */
final class GrowthBoosterBonusService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly CompensationPlanSettingsService $plan,
        private readonly IncomeEligibilityService $eligibility,
        private readonly GsbDailyPoolService $gsbPool,
    ) {}

    /**
     * Run the GBB calculation for the given calendar month.
     * Idempotent: a distributor already credited for the month is left alone,
     * and the month's frozen economics are reused rather than recomputed.
     *
     * @return array{pool_paise: int, total_agp: int, point_value_paise: int, credited: int, held: int, suspended: int, skipped_no_agp: int}
     */
    public function runForMonth(Carbon $month): array
    {
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
        $yearMonth = $monthStart->toDateString();

        $agpMap = $this->buildAgpMap($monthStart, $monthEnd);

        $skippedNoAgp = $agpMap->filter(fn (int $agp): bool => $agp === 0)->count();

        /** @var Collection<int, int> $earners */
        $earners = $agpMap->filter(fn (int $agp): bool => $agp > 0);

        $agpMap = $this->rejectRankedLastMonth($earners, $monthStart);

        [$payable, $held, $suspended] = $this->partitionByRepurchase($agpMap);

        // Suspended AGP can never be paid for this month, so it is kept out of
        // the denominator; held AGP stays in because a release can still pay it.
        $totalAgp = (int) $payable->sum() + (int) $held->sum();

        $pool = $this->freezePoolForMonth($monthStart, $monthEnd, $totalAgp);
        $pointValuePaise = (int) $pool->point_value_paise;

        $credited = 0;
        $heldCount = 0;
        $suspendedCount = 0;

        DB::transaction(function () use (
            $payable, $held, $suspended, $yearMonth, $pool, $pointValuePaise,
            &$credited, &$heldCount, &$suspendedCount,
        ): void {
            foreach ($payable as $distributorId => $agp) {
                $result = $this->writeResult((int) $distributorId, $yearMonth, $agp, $pool, $pointValuePaise * $agp, GbbMonthlyResult::STATUS_PENDING);

                if ($result === null) {
                    continue;
                }

                if ($result->gbb_gross_paise > 0) {
                    $this->wallet->credit(
                        distributorId: (int) $distributorId,
                        amountPaise: (int) $result->gbb_gross_paise,
                        type: 'gbb_credit',
                        referenceId: $result->id,
                        referenceType: 'gbb_monthly_result',
                        memo: 'Growth Booster Bonus '.$yearMonth,
                    );
                }

                $result->update([
                    'status' => GbbMonthlyResult::STATUS_CREDITED,
                    'credited_at' => now(),
                ]);

                $credited++;
            }

            foreach ($held as $distributorId => $agp) {
                if ($this->writeResult((int) $distributorId, $yearMonth, $agp, $pool, $pointValuePaise * $agp, GbbMonthlyResult::STATUS_REPURCHASE_HELD) !== null) {
                    $heldCount++;
                }
            }

            foreach ($suspended as $distributorId => $agp) {
                if ($this->writeResult((int) $distributorId, $yearMonth, $agp, $pool, 0, GbbMonthlyResult::STATUS_REPURCHASE_SUSPENDED) !== null) {
                    $suspendedCount++;
                }
            }
        });

        // Reported straight off the frozen snapshot, never off the live
        // recomputation — pool ÷ total_agp must always reconcile to the point
        // value that was actually paid.
        return [
            'pool_paise' => (int) $pool->pool_paise,
            'total_agp' => (int) $pool->total_agp,
            'point_value_paise' => $pointValuePaise,
            'credited' => $credited,
            'held' => $heldCount,
            'suspended' => $suspendedCount,
            'skipped_no_agp' => $skippedNoAgp,
        ];
    }

    /**
     * Write (or refresh) the month's result row for one distributor.
     *
     * Returns null when the distributor is already credited for the month,
     * which is the idempotency guard for re-runs and also protects a row
     * already released by ReleaseHeldGbbOnReactivation from being pushed back
     * to `repurchase_held`.
     */
    private function writeResult(
        int $distributorId,
        string $yearMonth,
        int $agp,
        GbbMonthlyPool $pool,
        int $grossPaise,
        string $status,
    ): ?GbbMonthlyResult {
        $alreadyCredited = GbbMonthlyResult::query()
            ->where('distributor_id', $distributorId)
            ->where('year_month', $yearMonth)
            ->where('status', GbbMonthlyResult::STATUS_CREDITED)
            ->exists();

        if ($alreadyCredited) {
            return null;
        }

        return GbbMonthlyResult::updateOrCreate(
            ['distributor_id' => $distributorId, 'year_month' => $yearMonth],
            [
                'agp_earned' => $agp,
                // The column is unsigned; the signed truth for a refund-heavy
                // month lives on gbb_monthly_pools.company_bv_paise.
                'company_turnover_paise' => max(0, (int) $pool->company_bv_paise),
                'pool_paise' => max(0, (int) $pool->pool_paise),
                'total_pool_agp' => (int) $pool->total_agp,
                'point_value_paise' => (int) $pool->point_value_paise,
                'gbb_gross_paise' => $grossPaise,
                'admin_charge_paise' => 0,
                'tds_paise' => 0,
                'gbb_net_paise' => $grossPaise,
                'status' => $status,
            ],
        );
    }

    /**
     * Freeze the month's pool economics. Idempotent: an existing row is returned
     * unchanged — the month's economics never move once written, no matter how
     * much BV or how many cut-offs land afterwards.
     *
     * @param  int  $totalAgp  Σ AGP over the month's payable + held participants
     */
    private function freezePoolForMonth(Carbon $monthStart, Carbon $monthEnd, int $totalAgp): GbbMonthlyPool
    {
        $existing = GbbMonthlyPool::where('month_start', $monthStart->toDateString())->first();
        if ($existing !== null) {
            return $existing;
        }

        $companyBvPaise = $this->gsbPool->companyBvPaiseBetween($monthStart, $monthEnd);
        $rateBp = $this->plan->gbbPoolRateBp();
        $poolPaise = max(0, intdiv($companyBvPaise * $rateBp, 10_000));

        // Floor the per-AGP value to whole rupees: truncate to a multiple of
        // 100 paise. max() guards a refund-heavy (negative-BV) month, where
        // intdiv() truncates toward zero.
        $valuePaise = $totalAgp === 0
            ? 0
            : max(0, intdiv(intdiv($poolPaise, $totalAgp), 100) * 100);

        $payoutPaise = $valuePaise * $totalAgp;

        $pool = GbbMonthlyPool::create([
            'month_start' => $monthStart->toDateString(),
            'company_bv_paise' => $companyBvPaise,
            'pool_rate_bp' => $rateBp,
            'pool_paise' => $poolPaise,
            'total_agp' => $totalAgp,
            'point_value_paise' => $valuePaise,
            'payout_paise' => $payoutPaise,
            'leftover_paise' => $poolPaise - $payoutPaise,
        ]);

        $details = [
            'month_start' => $monthStart->toDateString(),
            'company_bv_paise' => $companyBvPaise,
            'pool_rate_bp' => $rateBp,
            'pool_paise' => $poolPaise,
            'total_agp' => $totalAgp,
            'point_value_paise' => $valuePaise,
            'payout_paise' => $payoutPaise,
            'leftover_paise' => $pool->leftover_paise,
        ];

        Log::info('gbb.pool.frozen', $details);

        // The freeze determines every GBB payout for the month — a
        // retention-guaranteed audit_log row, not just a log line (R-35).
        AuditLog::create([
            'action' => 'gbb.pool.frozen',
            'subject_type' => 'gbb_monthly_pool',
            'subject_id' => $pool->id,
            'details' => $details,
        ]);

        return $pool;
    }

    /**
     * Drop every distributor who held a qualified rank in the month before
     * $monthStart. Carry-forward rows count — a paid carry row still means
     * "ranked" (same reading as AogoOfferService::grantForMonth()).
     *
     * @param  Collection<int, int>  $agpMap
     * @return Collection<int, int>
     */
    private function rejectRankedLastMonth(Collection $agpMap, Carbon $monthStart): Collection
    {
        if ($agpMap->isEmpty()) {
            return $agpMap;
        }

        $rankedIds = RankQualification::query()
            ->rankedInMonth($monthStart->copy()->subMonth()->startOfMonth()->toDateString())
            ->whereIn('distributor_id', $agpMap->keys()->all())
            ->distinct()
            ->pluck('distributor_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();

        return $agpMap->reject(fn (int $agp, int $distributorId): bool => $rankedIds->has($distributorId));
    }

    /**
     * Split the month's participants into payable / held / suspended by their
     * repurchase cycle status, before the denominator is computed.
     *
     * @param  Collection<int, int>  $agpMap
     * @return array{0: Collection<int, int>, 1: Collection<int, int>, 2: Collection<int, int>}
     */
    private function partitionByRepurchase(Collection $agpMap): array
    {
        /** @var Collection<int, int> $payable */
        $payable = collect();
        /** @var Collection<int, int> $held */
        $held = collect();
        /** @var Collection<int, int> $suspended */
        $suspended = collect();

        if ($agpMap->isEmpty()) {
            return [$payable, $held, $suspended];
        }

        $this->eligibility->warmCycleCache($agpMap->keys()->map(fn ($id): int => (int) $id)->all());

        foreach ($agpMap as $distributorId => $agp) {
            $status = $this->eligibility->statusFor((int) $distributorId, BonusType::GrowthBooster);

            match ($status) {
                IncomeEligibilityService::HOLD => $held[$distributorId] = $agp,
                IncomeEligibilityService::BLOCKED => $suspended[$distributorId] = $agp,
                default => $payable[$distributorId] = $agp,
            };
        }

        return [$payable, $held, $suspended];
    }

    /**
     * Build a map of distributor_id → capped AGP for the month, from credited
     * GSB cut-offs in slabs 1–3.
     *
     * @return Collection<int, int>
     */
    private function buildAgpMap(Carbon $monthStart, Carbon $monthEnd): Collection
    {
        $rows = GsbCutoffResult::query()
            ->where('status', GsbCutoffResult::STATUS_CREDITED)
            ->whereIn('slab', [1, 2, 3])
            ->whereBetween('cutoff_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->select('distributor_id', 'slab', DB::raw('COUNT(*) as occurrences'))
            ->groupBy('distributor_id', 'slab')
            ->get();

        /** @var Collection<int, int> $agpMap distributor_id → raw (pre-cap) AGP */
        $agpMap = collect();

        $agpBySlab = $this->plan->agpBySlab();

        foreach ($rows as $row) {
            $distributorId = (int) $row->distributor_id;
            $agpPerOccurrence = $agpBySlab[(int) $row->slab] ?? 0;
            $agpMap[$distributorId] = ($agpMap[$distributorId] ?? 0) + ($agpPerOccurrence * (int) $row->occurrences);
        }

        // Apply per-distributor cap.
        $cap = $this->plan->gbbAgpCap();

        return $agpMap->map(fn (int $agp) => min($agp, $cap));
    }
}
