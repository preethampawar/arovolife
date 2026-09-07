<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Models\GbbMonthlyPool;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Services\DTOs\GbbMonthRoster;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Shared\Support\Money;
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
 * REPURCHASE — client 2026-09-06 rules 7–8: GSB, Rank, Growth Booster and
 * Fortune are withheld together on repurchase non-compliance (never Mentorship).
 * A cycle fails when EITHER the window's self-purchase BV fell short OR the
 * repurchase wallet was not ₹0 on the window's last day — one verdict, read as
 * at month end from {@see IncomeEligibilityService::verdictAsOf()}.
 *   • failed cycle (HOLD) → the bonus IS calculated at the frozen point value
 *     and persisted as {@see GbbMonthlyResult::STATUS_REPURCHASE_HELD} with no
 *     wallet credit; the AGP STAYS in the denominator, because the row is
 *     released and paid in full by ReleaseHeldGbbOnReactivation the day the
 *     distributor fulfils. Withholding it from the denominator would price the
 *     release at a rate nobody else was paid.
 *
 * The STATUS_REPURCHASE_SUSPENDED and STATUS_REPURCHASE_WALLET_BLOCKED rows —
 * gross 0, excluded from the denominator, never released — are no longer
 * written. They predate rule 8's decision that withheld income is paid back,
 * and the recovery path below still has to recognise them.
 *
 * THREE PHASES (the RankBonusService shape):
 *  1. Pass 1 — {@see resolveRoster()} decides the month's population and each
 *     member's AGP: the cut-off earners, the prior-month rank gate, the
 *     repurchase cycle gate and the repurchase-wallet gate.
 *  2. Freeze — {@see freezeMonth()} writes, in ONE transaction, the
 *     gbb_monthly_pools row AND a gbb_monthly_results row for every roster
 *     member carrying its decided status and its frozen AGP. `total_agp` is the
 *     Σ AGP of exactly the members counted in the denominator, so the pool and
 *     the roster are consistent by construction and a crash can never leave an
 *     earner outside a roster that is about to close.
 *  3. Pass 2 — {@see creditFromFrozenPool()} credits roster members from the
 *     FROZEN point value and their FROZEN AGP, never a recomputed one.
 *
 * WHY THE FREEZE COVERS THE ROSTER — freezing only the pool was not enough.
 * The month's credited GSB set can still move after the freeze (a
 * `gsb:daily-cutoff` re-run for a date in the closed month, or a `rank:check`
 * re-run for M−1 changing the rank gate), and the engine then recomputed AGP
 * from live data on every run: a distributor with no row at freeze was created
 * fresh and paid at the frozen point value although their AGP was never in the
 * frozen denominator, and a held row was re-priced to the larger live AGP that
 * ReleaseHeldGbbOnReactivation would then pay. Pool ₹10,000 over 100 AGP pays
 * ₹100 an AGP; one newcomer with 12 AGP takes ₹1,200 out of a pool that has
 * already been fully divided, and leftover_paise goes negative.
 *
 * A distributor whose AGP appears AFTER the freeze has no roster row: they are
 * refused, logged as `gbb.result.qualified_after_freeze`, written to
 * `audit_log`, and surfaced on the admin GBB Input & Output report by
 * {@see qualifiedAfterFreeze()}. Deliberate product decision — late earners are
 * shown to an admin, never auto-paid.
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
     *
     * Idempotent in both directions: the month's pool AND its roster are frozen
     * on the first run, and every later run credits only the roster members not
     * yet credited, at the frozen point value and their frozen AGP.
     *
     * @return array{pool_paise: int, total_agp: int, point_value_paise: int, credited: int, held: int, suspended: int, skipped_no_agp: int, skipped_wallet_nonzero: int, wallet_blocked: int, qualified_after_freeze: int}
     */
    public function runForMonth(Carbon $month): array
    {
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
        $yearMonth = $monthStart->toDateString();

        $pool = GbbMonthlyPool::where('month_start', $yearMonth)->first();

        if ($pool !== null && $this->replacePrematureFreeze($pool, $monthEnd)) {
            $pool = null;
        }

        if ($pool === null) {
            $pool = $this->freezeMonth($monthStart, $monthEnd, $yearMonth);
        }

        return $this->creditFromFrozenPool($monthStart, $monthEnd, $yearMonth, $pool);
    }

    /**
     * Distributors who earned payable AGP in a frozen month but carry no roster
     * row — their AGP arrived after the pool was divided, so they are refused
     * rather than paid out of someone else's share.
     *
     * Read-only, and empty for a month that has not been frozen. The monthly run
     * logs and audits these; the admin GBB Input & Output report displays them.
     *
     * @return list<int> distributor ids
     */
    public function qualifiedAfterFreeze(Carbon $month): array
    {
        $monthStart = $month->copy()->startOfMonth();
        $yearMonth = $monthStart->toDateString();

        if (! GbbMonthlyPool::where('month_start', $yearMonth)->exists()) {
            return [];
        }

        $agpMap = $this->buildAgpMap($monthStart, $month->copy()->endOfMonth());

        return array_keys($this->lateEarners($this->eligibleEarners($agpMap, $monthStart), $yearMonth));
    }

    // ---------------------------------------------------------------- pass 1

    /**
     * Pass 1 — resolve the month's population and each member's AGP. Pure
     * reads; the caller runs it inside the freeze transaction so the roster it
     * decides and the pool priced from it are written together or not at all.
     */
    private function resolveRoster(Carbon $monthStart, Carbon $monthEnd): GbbMonthRoster
    {
        $agpMap = $this->buildAgpMap($monthStart, $monthEnd);

        $skippedNoAgp = $agpMap->filter(fn (int $agp): bool => $agp === 0)->count();

        // ONE repurchase gate, as at month end. The wallet = ₹0 condition used
        // to be a second pass over the live balances; the client's 2026-09-06
        // rule 4 folds it into the distributor's own cycle verdict, so a wallet
        // failure now lands in $held like any other missed repurchase — in the
        // denominator, and released in full on fulfilment (rule 8).
        [$payable, $held, $suspended] = $this->partitionByRepurchase(
            $this->eligibleEarners($agpMap, $monthStart),
            $monthEnd,
        );

        return new GbbMonthRoster(
            payable: $payable,
            held: $held,
            suspended: $suspended,
            walletBlocked: collect(),
            skippedNoAgp: $skippedNoAgp,
        );
    }

    // ----------------------------------------------------------------- freeze

    /**
     * Freeze the month: pass 1, then the pool row and every roster row, in one
     * transaction. Nothing is credited here — pass 2 does that from what this
     * wrote.
     */
    private function freezeMonth(Carbon $monthStart, Carbon $monthEnd, string $yearMonth): GbbMonthlyPool
    {
        return DB::transaction(function () use ($monthStart, $monthEnd, $yearMonth): GbbMonthlyPool {
            $roster = $this->resolveRoster($monthStart, $monthEnd);
            $totalAgp = $roster->totalAgp();

            $companyBvPaise = $this->gsbPool->companyBvPaiseBetween($monthStart, $monthEnd);
            $rateBp = $this->plan->gbbPoolRateBp();
            $poolPaise = max(0, intdiv($companyBvPaise * $rateBp, 10_000));

            // Floor the per-AGP value to whole rupees: truncate to a multiple of
            // 100 paise. max() guards a refund-heavy (negative-BV) month, where
            // intdiv() truncates toward zero.
            $valuePaise = Money::floorRupee($poolPaise, $totalAgp);

            $payoutPaise = $valuePaise * $totalAgp;

            $pool = GbbMonthlyPool::create([
                'month_start' => $yearMonth,
                'company_bv_paise' => $companyBvPaise,
                'pool_rate_bp' => $rateBp,
                'pool_paise' => $poolPaise,
                'total_agp' => $totalAgp,
                'point_value_paise' => $valuePaise,
                'payout_paise' => $payoutPaise,
                'leftover_paise' => $poolPaise - $payoutPaise,
            ]);

            foreach ($roster->payable as $distributorId => $agp) {
                $this->writeRosterRow((int) $distributorId, $yearMonth, $agp, $pool, $valuePaise * $agp, GbbMonthlyResult::STATUS_PENDING);
            }

            foreach ($roster->held as $distributorId => $agp) {
                $this->writeRosterRow((int) $distributorId, $yearMonth, $agp, $pool, $valuePaise * $agp, GbbMonthlyResult::STATUS_REPURCHASE_HELD);
            }

            foreach ($roster->suspended as $distributorId => $agp) {
                $this->writeRosterRow((int) $distributorId, $yearMonth, $agp, $pool, 0, GbbMonthlyResult::STATUS_REPURCHASE_SUSPENDED);
            }

            foreach ($roster->walletBlocked as $distributorId => $agp) {
                $this->writeRosterRow((int) $distributorId, $yearMonth, $agp, $pool, 0, GbbMonthlyResult::STATUS_REPURCHASE_WALLET_BLOCKED);
            }

            $this->recordFreeze($pool);

            return $pool;
        });
    }

    /**
     * Write one roster row. The status and the AGP are decided ONCE, here, and
     * pass 2 never re-prices them.
     *
     * Returns null when the distributor is already credited for the month,
     * which protects a row already released by ReleaseHeldGbbOnReactivation
     * from being pushed back to `repurchase_held`.
     *
     * Returns null too when the month already holds a pool-EXCLUDED row for the
     * distributor ({@see GbbMonthlyResult::POOL_EXCLUDED_STATUSES}) and this
     * write would move it back onto the funded path. Their AGP was never in the
     * frozen denominator, so the frozen point value was priced without them: a
     * distributor suspended (or wallet-blocked) under one pool must NOT be paid
     * against it — it would overspend the pool and drive leftover_paise
     * negative. `pending` and `repurchase_held` rows are untouched by this
     * guard: their AGP WAS in the denominator, which is exactly why held rows
     * are released later.
     */
    private function writeRosterRow(
        int $distributorId,
        string $yearMonth,
        int $agp,
        GbbMonthlyPool $pool,
        int $grossPaise,
        string $status,
    ): ?GbbMonthlyResult {
        $existing = GbbMonthlyResult::query()
            ->where('distributor_id', $distributorId)
            ->where('year_month', $yearMonth)
            ->first();

        if ($existing?->status === GbbMonthlyResult::STATUS_CREDITED) {
            return null;
        }

        if ($existing !== null
            && in_array($existing->status, GbbMonthlyResult::POOL_EXCLUDED_STATUSES, true)
            && ! in_array($status, GbbMonthlyResult::POOL_EXCLUDED_STATUSES, true)) {
            $this->recordExcludedFromFrozenDenominator($distributorId, $yearMonth, $existing->status, $status, $agp, $pool);

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
     * The freeze determines every GBB payout for the month — a
     * retention-guaranteed audit_log row, not just a log line (R-35).
     */
    private function recordFreeze(GbbMonthlyPool $pool): void
    {
        $details = [
            'month_start' => $pool->month_start,
            'company_bv_paise' => (int) $pool->company_bv_paise,
            'pool_rate_bp' => (int) $pool->pool_rate_bp,
            'pool_paise' => (int) $pool->pool_paise,
            'total_agp' => (int) $pool->total_agp,
            'point_value_paise' => (int) $pool->point_value_paise,
            'payout_paise' => (int) $pool->payout_paise,
            'leftover_paise' => (int) $pool->leftover_paise,
        ];

        Log::info('gbb.pool.frozen', $details);

        AuditLog::create([
            'action' => 'gbb.pool.frozen',
            'subject_type' => 'gbb_monthly_pool',
            'subject_id' => $pool->id,
            'details' => $details,
        ]);
    }

    /**
     * Delete a pool row that was frozen before its month had closed so the
     * caller can freeze the month afresh. Returns true when the row was
     * removed. The monthly twin of
     * {@see GsbDailyPoolService::replacePrematureFreeze()}.
     *
     * "Frozen economics" assumes the freeze happened once the month's BV and
     * its AGP earners were final — the scheduler guarantees that by running on
     * the 1st. A freeze whose created_at falls BEFORE the month ended broke
     * that assumption (a mid-month manual run from the Engine Runs page, or the
     * recompute tool catching up the period in flight): it snapshotted partial
     * company BV and a partial roster, and every later run for the month would
     * silently price against it. Local, Aug 2026: a run made while every earner
     * still failed the wallet gate froze total_agp = 0, and the next run wrote
     * 22 result rows carrying real AGP against a zero denominator — the report
     * showed "Total AGP 0" over 545 AGP of rows.
     *
     * Replacement is only safe while NOTHING the pool funded was actually
     * credited: once a wallet has moved on a pool-priced gross, re-freezing
     * would change economics money moved on, so the rows are kept and the
     * inconsistency surfaced loudly instead. The test is the STATUS, not the
     * gross: a credited row whose gross floored to ₹0 is still the record of a
     * real participation in that pool (and a released held row can turn a ₹0
     * row into a paid one), so it keeps the pool exactly like a paid row does.
     * Un-credited, un-held rows moved no money, but they DO block the re-run (a
     * `credited` row is the idempotency guard in {@see writeRosterRow()}), so
     * they are cleared along with the pool and recomputed from the fresh
     * snapshot.
     */
    private function replacePrematureFreeze(GbbMonthlyPool $existing, Carbon $monthEnd): bool
    {
        $monthClosedAt = $monthEnd->copy()->addDay()->startOfDay();
        if ($existing->created_at === null || $existing->created_at->gte($monthClosedAt)) {
            return false; // Frozen after the month closed — the normal, final row.
        }

        $details = [
            'month_start' => $existing->month_start,
            'frozen_at' => $existing->created_at->toDateTimeString(),
            'company_bv_paise' => $existing->company_bv_paise,
            'pool_paise' => $existing->pool_paise,
            'total_agp' => $existing->total_agp,
            'point_value_paise' => $existing->point_value_paise,
        ];

        $results = GbbMonthlyResult::where('year_month', $existing->month_start);

        if ($results->clone()
            ->whereIn('status', GbbMonthlyResult::POOL_FUNDED_STATUSES)
            ->exists()) {
            Log::warning('gbb.pool.premature_freeze_kept', $details + [
                'reason' => 'results were already priced against this pool; re-freezing would change economics money moved on',
            ]);

            return false;
        }

        // Snapshot what the hard delete is about to destroy — id, distributor,
        // status, AGP and gross — so the deletion stays reconstructable from
        // `audit_log` alone. A bare count is not.
        $discarded = $results->clone()
            ->get(['id', 'distributor_id', 'agp_earned', 'status', 'gbb_gross_paise'])
            ->map(fn (GbbMonthlyResult $row): array => [
                'id' => (int) $row->id,
                'distributor_id' => (int) $row->distributor_id,
                'agp_earned' => (int) $row->agp_earned,
                'status' => $row->status,
                'gbb_gross_paise' => (int) $row->gbb_gross_paise,
            ])
            ->all();

        $discardedResults = $results->clone()->delete();

        Log::warning('gbb.pool.premature_freeze_replaced', $details + [
            'discarded_results' => $discardedResults,
        ]);

        AuditLog::create([
            'action' => 'gbb.pool.refrozen',
            'subject_type' => 'gbb_monthly_pool',
            'subject_id' => $existing->id,
            'details' => $details + [
                'discarded_results' => $discardedResults,
                'discarded_rows' => $discarded,
                'reason' => 'pool was frozen before the month ended and nothing it funded was credited',
            ],
        ]);

        $existing->delete();

        return true;
    }

    // ---------------------------------------------------------------- pass 2

    /**
     * Pass 2 — credit the frozen roster. Only rows still `pending` are paid, at
     * the gross frozen on them; the pool, the denominator, the point value and
     * every row's AGP are never touched again.
     *
     * @return array{pool_paise: int, total_agp: int, point_value_paise: int, credited: int, held: int, suspended: int, skipped_no_agp: int, skipped_wallet_nonzero: int, wallet_blocked: int, qualified_after_freeze: int}
     */
    private function creditFromFrozenPool(Carbon $monthStart, Carbon $monthEnd, string $yearMonth, GbbMonthlyPool $pool): array
    {
        /** @var Collection<int, GbbMonthlyResult> $rows */
        $rows = GbbMonthlyResult::where('year_month', $yearMonth)->get();

        $agpMap = $this->buildAgpMap($monthStart, $monthEnd);
        $earners = $this->eligibleEarners($agpMap, $monthStart);

        $late = $this->lateEarners($earners, $yearMonth);

        foreach ($late as $distributorId => $agp) {
            $this->recordQualifiedAfterFreeze($distributorId, $yearMonth, $agp, $pool);
        }

        $this->recordRevivedExclusions($rows, $earners, $monthEnd, $yearMonth, $pool);

        $credited = 0;

        DB::transaction(function () use ($rows, $monthStart, $yearMonth, &$credited): void {
            foreach ($rows as $row) {
                if ($row->status !== GbbMonthlyResult::STATUS_PENDING) {
                    continue;
                }

                $gross = (int) $row->gbb_gross_paise;
                $repurchaseDeduction = 0;

                if ($gross > 0) {
                    $repurchaseDeduction = $this->wallet->creditWithRepurchaseDeduction(
                        distributorId: (int) $row->distributor_id,
                        grossPaise: $gross,
                        bonusType: 'gbb_credit',
                        referenceId: $row->id,
                        referenceType: 'gbb_monthly_result',
                        bonusMonth: $monthStart,
                        memo: 'Growth Booster Bonus '.$yearMonth,
                    )->repurchaseDeductionPaise;
                }

                $row->update([
                    'status' => GbbMonthlyResult::STATUS_CREDITED,
                    'credited_at' => now(),
                    'repurchase_deduction_paise' => $repurchaseDeduction,
                    'gbb_net_paise' => $gross - $repurchaseDeduction,
                ]);

                $credited++;
            }
        });

        $walletBlockedCount = $rows->where('status', GbbMonthlyResult::STATUS_REPURCHASE_WALLET_BLOCKED)->count();

        // Reported straight off the frozen snapshot, never off the live
        // recomputation — pool ÷ total_agp must always reconcile to the point
        // value that was actually paid.
        return [
            'pool_paise' => (int) $pool->pool_paise,
            'total_agp' => (int) $pool->total_agp,
            'point_value_paise' => (int) $pool->point_value_paise,
            'credited' => $credited,
            'held' => $rows->where('status', GbbMonthlyResult::STATUS_REPURCHASE_HELD)->count(),
            'suspended' => $rows->where('status', GbbMonthlyResult::STATUS_REPURCHASE_SUSPENDED)->count(),
            'skipped_no_agp' => $agpMap->filter(fn (int $agp): bool => $agp === 0)->count(),
            'skipped_wallet_nonzero' => $walletBlockedCount,
            'wallet_blocked' => $walletBlockedCount,
            'qualified_after_freeze' => count($late),
        ];
    }

    /**
     * The month's eligible AGP earners that hold no roster row — their AGP
     * landed after the freeze divided the pool.
     *
     * @param  Collection<int, int>  $earners  distributor id → live AGP
     * @return array<int, int> distributor id → live AGP
     */
    private function lateEarners(Collection $earners, string $yearMonth): array
    {
        if ($earners->isEmpty()) {
            return [];
        }

        $rosterIds = GbbMonthlyResult::query()
            ->where('year_month', $yearMonth)
            ->pluck('distributor_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();

        $late = [];

        foreach ($earners as $distributorId => $agp) {
            if (! $rosterIds->has((int) $distributorId)) {
                $late[(int) $distributorId] = (int) $agp;
            }
        }

        return $late;
    }

    /**
     * Refusing a late earner permanently withholds a month's Growth Booster
     * Bonus from someone who did earn AGP, and the month is never reopened.
     * That decision gets a retention-guaranteed audit_log row a grievance
     * officer can still query years later, not only a log line that rotates
     * away (R-35) — the same reasoning as `fortune.enroll.matrix_full`.
     */
    private function recordQualifiedAfterFreeze(int $distributorId, string $yearMonth, int $agp, GbbMonthlyPool $pool): void
    {
        $details = [
            'distributor_id' => $distributorId,
            'year_month' => $yearMonth,
            'agp' => $agp,
            'frozen_total_agp' => (int) $pool->total_agp,
            'frozen_point_value_paise' => (int) $pool->point_value_paise,
            'refused_gross_paise' => (int) $pool->point_value_paise * $agp,
            'reason' => 'earned AGP after the month\'s pool was frozen — refused, never paid from a divided pool',
        ];

        Log::warning('gbb.result.qualified_after_freeze', $details);

        AuditLog::create([
            'action' => 'gbb.result.qualified_after_freeze',
            'subject_type' => 'distributor',
            'subject_id' => $distributorId,
            'details' => $details,
        ]);
    }

    /**
     * Roster members frozen into a pool-EXCLUDED status whose gates have since
     * come good. Their AGP was never in the frozen denominator, so the month can
     * still never pay them; pass 2 simply skips their row, and this records why
     * — the same refusal the freeze-time guard in {@see writeRosterRow()}
     * records, for the far more common case where the roster already exists.
     *
     * @param  Collection<int, GbbMonthlyResult>  $rows
     * @param  Collection<int, int>  $earners  distributor id → live AGP
     */
    private function recordRevivedExclusions(
        Collection $rows,
        Collection $earners,
        Carbon $monthEnd,
        string $yearMonth,
        GbbMonthlyPool $pool,
    ): void {
        /** @var Collection<int, GbbMonthlyResult> $excluded */
        $excluded = $rows
            ->filter(fn (GbbMonthlyResult $row): bool => in_array($row->status, GbbMonthlyResult::POOL_EXCLUDED_STATUSES, true))
            ->filter(fn (GbbMonthlyResult $row): bool => $earners->has((int) $row->distributor_id));

        if ($excluded->isEmpty()) {
            return;
        }

        /** @var Collection<int, int> $candidates */
        $candidates = $excluded->mapWithKeys(fn (GbbMonthlyResult $row): array => [
            (int) $row->distributor_id => (int) $earners[(int) $row->distributor_id],
        ]);

        [$payable] = $this->partitionByRepurchase($candidates, $monthEnd);

        foreach ($excluded as $row) {
            $distributorId = (int) $row->distributor_id;

            if (! $payable->has($distributorId)) {
                continue;
            }

            $this->recordExcludedFromFrozenDenominator(
                $distributorId,
                $yearMonth,
                $row->status,
                GbbMonthlyResult::STATUS_PENDING,
                (int) $payable[$distributorId],
                $pool,
            );
        }
    }

    /**
     * The refusal permanently withholds this month's Growth Booster Bonus from a
     * distributor who earned AGP, and the frozen denominator is never
     * recomputed. It therefore gets a retention-guaranteed audit_log row a
     * grievance officer can still query years later, not only a log line that
     * rotates away (R-35).
     */
    private function recordExcludedFromFrozenDenominator(
        int $distributorId,
        string $yearMonth,
        string $existingStatus,
        string $attemptedStatus,
        int $agp,
        GbbMonthlyPool $pool,
    ): void {
        $details = [
            'distributor_id' => $distributorId,
            'year_month' => $yearMonth,
            'existing_status' => $existingStatus,
            'attempted_status' => $attemptedStatus,
            'agp' => $agp,
            'frozen_total_agp' => (int) $pool->total_agp,
            'frozen_point_value_paise' => (int) $pool->point_value_paise,
            'refused_gross_paise' => (int) $pool->point_value_paise * $agp,
        ];

        Log::warning('gbb.result.excluded_from_frozen_denominator', $details);

        AuditLog::create([
            'action' => 'gbb.result.excluded_from_frozen_denominator',
            'subject_type' => 'distributor',
            'subject_id' => $distributorId,
            'details' => $details,
        ]);
    }

    // ------------------------------------------------------------- population

    /**
     * The month's AGP earners after the prior-month rank gate — the population
     * the freeze partitions, and the population a later run diffs against the
     * roster to find late arrivals. Both callers already hold the raw AGP map
     * (they report `skipped_no_agp` off it), so it is passed in rather than
     * rebuilt.
     *
     * @param  Collection<int, int>  $agpMap
     * @return Collection<int, int> distributor id → AGP
     */
    private function eligibleEarners(Collection $agpMap, Carbon $monthStart): Collection
    {
        return $this->rejectRankedLastMonth($this->agpEarners($agpMap), $monthStart);
    }

    /**
     * The entries of an AGP map that actually earned points. A distributor with
     * cut-offs only in slabs that award no AGP is not a participant.
     *
     * @param  Collection<int, int>  $agpMap
     * @return Collection<int, int>
     */
    private function agpEarners(Collection $agpMap): Collection
    {
        /** @var Collection<int, int> $earners */
        $earners = $agpMap->filter(fn (int $agp): bool => $agp > 0);

        return $earners;
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
     * repurchase standing AS AT $asOf, before the denominator is computed.
     *
     * The suspended bucket is no longer filled: the client's 2026-09-06 rule 8
     * releases withheld income on fulfilment, so every repurchase failure is a
     * hold. It stays in the signature because rows written before that decision
     * are still recovered through this path.
     *
     * @param  Collection<int, int>  $agpMap
     * @return array{0: Collection<int, int>, 1: Collection<int, int>, 2: Collection<int, int>}
     */
    private function partitionByRepurchase(Collection $agpMap, Carbon $asOf): array
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
            $status = $this->eligibility
                ->verdictAsOf((int) $distributorId, BonusType::GrowthBooster, $asOf)
                ->status;

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
