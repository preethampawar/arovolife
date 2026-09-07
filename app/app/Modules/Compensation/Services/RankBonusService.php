<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Listeners\ReleaseHeldRankBonusOnReactivation;
use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\RankAogoGrant;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Models\RankMonthlyPool;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Services\DTOs\RankMonthRoster;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Monthly Rank Bonus engine (KP 2026-08-05 spec).
 *
 * Pool per rank = company BV × envelope × pool_pct[rank].
 *
 * "Turnover" here means COMPANY BV — the signed bv_ledger_entries sum for the
 * month (GsbDailyPoolService::companyBvPaiseBetween), not order sales value.
 * The Rank Bonus envelope (comp.rank.envelope_bp, default 2000 bp = 20%) is
 * carved out of that BV first; each rank's `rank_tiers.pool_pct` is then a
 * share OF THE ENVELOPE, not of turnover directly — the nine seeded
 * percentages sum to exactly 20.
 *
 * Worked example (product owner 2026-08-05): 10,00,000 BV in the month →
 * 20% envelope = 2,00,000 → Rank 1's 7% share = ₹14,000.
 *
 * Company BV is a SIGNED sum, so a refund-heavy month can be negative; every
 * pool is floored at 0 so a negative amount can never reach the wallet.
 *
 * Distribution:
 *  - Ranks with rap_points set (seeded: Rank 1 = 10) divide their pool by
 *    points, like the MSB daily pool: point value = floor-to-whole-rupee(
 *    pool ÷ (payable achievers × RAP + Σ AO-GO points)); each participant is
 *    paid own points × point value. AO-GO grantees always share the RANK-1
 *    pool, whatever rank they previously held.
 *  - Ranks with null rap_points (2–9) split their pool equally among payable
 *    achievers: floor(pool / count).
 *
 * THREE PHASES (the GsbCutoffService shape):
 *  1. Pass 1 — {@see resolveRoster()} decides the month's population: the
 *     qualifiers per rank, the §8 requalification gate, the repurchase-wallet
 *     gate, and the AO-GO grants.
 *  2. Freeze — {@see freezeMonth()} writes, in ONE transaction, the nine
 *     rank_monthly_pools rows AND a rank_bonus_results row for every roster
 *     member carrying its decided status. A crash can therefore never leave a
 *     qualifier outside a roster that is about to close.
 *  3. Pass 2 — {@see creditFromFrozenPools()} credits roster members from the
 *     FROZEN gross, never a recomputed one.
 *
 * WHY THE FREEZE EXISTS — this engine used to recompute the pool AND the
 * roster on every run and skip only rows already `credited`. Pool ₹6,000, two
 * qualifiers paid ₹3,000 each; a held third clears their repurchase later and
 * the re-run divides ₹6,000 by 3 and pays the third ₹2,000 — ₹8,000 out of a
 * ₹6,000 pool, with credited and non-credited rows of the same month
 * disagreeing on pool_paise / qualifier_count / point_value_paise.
 *
 * Months the OLD engine already paid have no pool row and can never get an
 * honest one, so they are refused rather than re-priced — see
 * {@see refuseUnfrozenPaidMonth()}.
 *
 * A distributor who qualifies AFTER the freeze has no roster row: they are
 * refused, logged as `rank.result.qualified_after_freeze`, and surfaced on the
 * admin Rank Bonus report by {@see qualifiedAfterFreeze()}. Deliberate product
 * decision — late qualifiers are shown to an admin, never auto-paid.
 *
 * Payment follows achievement — the old occurrence >= pyp_required payment
 * filter is retired (pyp_required is the Q-Period promotion gate in
 * RankQualificationService), as is the "1+2 rule" carry-forward (replaced by
 * the AO-GO offer).
 *
 * §8 requalification gate: a 2nd-or-later lifetime qualification of the same
 * rank is credited only if the month's requalification conditions hold
 * (rank's repurchase BV + wallet cleared — RankRequalificationGateService);
 * otherwise the result is recorded as `requalification_held`, excluded from
 * the pool denominator (MSB precedent: only paid participants dilute the
 * pool) and never back-paid.
 *
 * The repurchase deduction is taken at credit time and frozen on the result
 * row; admin charge and TDS are applied at payout time, not credit time.
 * All rates, caps and pool figures are read from
 * CompensationPlanSettingsService (admin-editable), not hardcoded.
 */
final class RankBonusService
{
    /** The ranks the engine pays, in order. */
    private const RANKS = [1, 2, 3, 4, 5, 6, 7, 8, 9];

    public function __construct(
        private readonly WalletService $wallet,
        private readonly CompensationPlanSettingsService $plan,
        private readonly AogoOfferService $aogo,
        private readonly RankRequalificationGateService $gate,
        private readonly GsbDailyPoolService $gsbPool,
        private readonly IncomeEligibilityService $eligibility,
    ) {}

    /**
     * Run the Rank Bonus for the given calendar month.
     *
     * Idempotent in both directions: the month's economics are frozen on the
     * first run and every later run credits only the roster members not yet
     * credited, at the frozen value.
     *
     * @return array{
     *     turnover_paise: int,
     *     credited: int,
     *     qualified_after_freeze: int,
     *     by_rank: array<int, array{qualifiers: int, held: int, aogo_grants: int, pool_paise: int, total_points: int|null, point_value_paise: int|null, gross_total: int, qualified_after_freeze: int}>
     * }
     */
    public function runForMonth(Carbon $month): array
    {
        $monthStartCarbon = $month->copy()->startOfMonth();
        $monthStart = $monthStartCarbon->toDateString();
        $monthEnd = $month->copy()->endOfMonth();

        $pools = $this->frozenPools($monthStart);

        if ($pools->isNotEmpty() && $this->replacePrematureFreeze($pools, $monthStart, $monthEnd)) {
            $pools = collect();
        }

        if ($pools->isEmpty()) {
            $this->refuseUnfrozenPaidMonth($monthStart);

            $pools = $this->freezeMonth($monthStartCarbon, $monthStart, $monthEnd);
        }

        return $this->creditFromFrozenPools($monthStartCarbon, $monthStart, $pools);
    }

    /**
     * Distributors who qualified for a rank (or hold a live AO-GO grant) in a
     * frozen month but carry no roster row — they arrived after the pool was
     * divided and are refused rather than paid out of someone else's share.
     *
     * Read-only, and empty for a month that has not been frozen. The monthly
     * run logs these; the admin Rank Bonus report displays them.
     *
     * @return array<int, list<int>> rank → distributor ids
     */
    public function qualifiedAfterFreeze(Carbon $month): array
    {
        $monthStart = $month->copy()->startOfMonth()->toDateString();

        if (! RankMonthlyPool::where('month_start', $monthStart)->exists()) {
            return [];
        }

        $qualifiers = $this->qualifierIdsByRank($monthStart);

        $rosterIds = RankBonusResult::query()
            ->where('month_start', $monthStart)
            ->get(['distributor_id', 'rank_number'])
            ->groupBy('rank_number')
            ->map(fn (Collection $rows): array => $rows->pluck('distributor_id')->map(fn ($id): int => (int) $id)->all());

        $late = [];

        foreach (self::RANKS as $rank) {
            $arrivals = $qualifiers[$rank] ?? [];

            if ($rank === 1) {
                $arrivals = array_values(array_unique(array_merge(
                    $arrivals,
                    $this->liveAogoGrants($monthStart)->keys()->map(fn ($id): int => (int) $id)->all(),
                )));
            }

            $missing = array_values(array_diff($arrivals, $rosterIds[$rank] ?? []));

            if ($missing !== []) {
                $late[$rank] = $missing;
            }
        }

        return $late;
    }

    // ---------------------------------------------------------------- pass 1

    /**
     * Pass 1 — resolve the month's population. Pure reads apart from the AO-GO
     * grants, which must exist before the denominator can be counted; the
     * caller runs this inside the freeze transaction so a crash cannot leave a
     * grant without its roster row.
     */
    private function resolveRoster(Carbon $monthStartCarbon, string $monthStart): RankMonthRoster
    {
        $qualifiers = $this->qualifierIdsByRank($monthStart);

        $payable = [];
        $held = [];
        $repurchaseHeld = [];

        $monthEnd = $monthStartCarbon->copy()->endOfMonth();

        foreach (self::RANKS as $rank) {
            $qualifierIds = $qualifiers[$rank] ?? [];

            $heldIds = $this->requalificationHeldIds($qualifierIds, $monthStartCarbon, $rank);
            $payableIds = array_values(array_diff($qualifierIds, $heldIds));

            // Repurchase gate (client 2026-09-06 rule 7 — Rank Bonus joined the
            // withheld four). One verdict as at month end covers both of the
            // rule-4 conditions: the cycle's self-purchase BV and the wallet
            // standing at ₹0 on the cycle's last day. A held achiever keeps
            // their place in the denominator and is priced at the month's full
            // rate, because rule 8 pays it to them the day they fulfil.
            $this->eligibility->warmCycleCache($payableIds);

            $repurchaseHeldIds = array_values(array_filter(
                $payableIds,
                fn (int $id): bool => ! $this->eligibility
                    ->verdictAsOf($id, BonusType::Rank, $monthEnd)
                    ->isEligible(),
            ));

            $payable[$rank] = $payableIds;
            $held[$rank] = $heldIds;
            $repurchaseHeld[$rank] = $repurchaseHeldIds;
        }

        return new RankMonthRoster(
            payableIds: $payable,
            heldIds: $held,
            repurchaseHeldIds: $repurchaseHeld,
            aogoGrants: $this->aogo->grantForMonth($monthStartCarbon),
        );
    }

    /**
     * Exclusive pools (the default): a distributor is paid ONLY their highest
     * qualified rank for the month. The qualification service deliberately
     * records every cleared rank (a Rank-2 achiever also clears Rank 1's bar,
     * and structural counts / the GBB prior-month gate query those rows), but
     * the plan text says reaching Rank 2 cancels the Rank-1 benefit — without
     * this filter every dual qualifier is paid from both pools, as the first
     * real July 2026 run did. `comp.rank.pay_highest_rank_only` = false
     * switches to cumulative pools (pay every cleared rank).
     *
     * @return Collection<int, int> distributor_id → highest rank, empty when cumulative
     */
    private function highestRankByDistributor(string $monthStart): Collection
    {
        if (! $this->plan->rankPayHighestOnly()) {
            return collect();
        }

        return RankQualification::query()->achieved()
            ->where('month_start', $monthStart)
            ->toBase()
            ->groupBy('distributor_id')
            ->selectRaw('distributor_id, MAX(rank_number) as highest_rank')
            ->pluck('highest_rank', 'distributor_id')
            ->map(fn ($rank): int => (int) $rank);
    }

    /**
     * This month's achievers per rank, after the highest-rank-only filter.
     * One query for all nine ranks — pass 1 and the late-qualifier diff both
     * need the whole month, and the diff runs on every admin report load.
     *
     * @return array<int, list<int>> rank → distributor ids
     */
    private function qualifierIdsByRank(string $monthStart): array
    {
        $highestRank = $this->highestRankByDistributor($monthStart);

        $rows = RankQualification::query()->achieved()
            ->where('month_start', $monthStart)
            ->distinct()
            ->get(['distributor_id', 'rank_number']);

        $byRank = [];

        foreach ($rows as $row) {
            $rank = (int) $row->rank_number;
            $distributorId = (int) $row->distributor_id;

            if (($highestRank[$distributorId] ?? $rank) !== $rank) {
                continue;
            }

            $byRank[$rank][$distributorId] = $distributorId;
        }

        return array_map(array_values(...), $byRank);
    }

    /**
     * §8: among this month's qualifiers, the 2nd-or-later lifetime achievers of
     * this rank that fail the requalification conditions. First-time achievers
     * are exempt.
     *
     * @param  list<int>  $qualifierIds
     * @return list<int>
     */
    private function requalificationHeldIds(array $qualifierIds, Carbon $month, int $rank): array
    {
        if ($qualifierIds === []) {
            return [];
        }

        $repeatIds = RankQualification::query()->achieved()
            ->whereIn('distributor_id', $qualifierIds)
            ->where('rank_number', $rank)
            ->where('month_start', '<', $month->toDateString())
            ->distinct()
            ->pluck('distributor_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($repeatIds === []) {
            return [];
        }

        $passMap = $this->gate->passMap($repeatIds, $month, $rank);

        return array_values(array_filter(
            $repeatIds,
            fn (int $id): bool => ! ($passMap[$id] ?? false),
        ));
    }

    // ----------------------------------------------------------------- freeze

    /**
     * Freeze the month: pass 1, then the nine pool rows and every roster row,
     * in one transaction. Nothing is credited here — pass 2 does that from what
     * this wrote.
     *
     * @return Collection<int, RankMonthlyPool> keyed by rank number
     */
    private function freezeMonth(Carbon $monthStartCarbon, string $monthStart, Carbon $monthEnd): Collection
    {
        return DB::transaction(function () use ($monthStartCarbon, $monthStart, $monthEnd): Collection {
            $roster = $this->resolveRoster($monthStartCarbon, $monthStart);

            $turnoverPaise = $this->gsbPool->companyBvPaiseBetween($monthStartCarbon, $monthEnd);
            $envelopeBp = $this->plan->rankEnvelopeBp();

            /** @var Collection<int, RankMonthlyPool> $pools */
            $pools = collect();

            foreach (self::RANKS as $rank) {
                $poolPct = $this->plan->rankPoolPct($rank);
                $poolPaise = max(0, (int) round($turnoverPaise * $envelopeBp / 10_000 * $poolPct / 100));
                $rapPoints = $this->plan->rankRapPoints($rank);

                $payableIds = $roster->payableFor($rank);
                $heldIds = $roster->heldFor($rank);
                $repurchaseHeldIds = $roster->repurchaseHeldFor($rank);

                /** @var Collection<int, RankAogoGrant> $grants */
                $grants = $rank === 1 ? $roster->aogoGrants : collect();
                $aogoPoints = (int) $grants->sum('points');

                // A repurchase-held achiever is in the DENOMINATOR and priced
                // at the month's full rate: the money is committed to them, it
                // is simply not credited until they fulfil (rule 8). So the
                // month's payout covers the whole payable population and
                // leftover_paise still reconciles against the frozen roster.
                $paidCount = count($payableIds);

                $totalPoints = null;
                $pointValuePaise = null;

                if ($rapPoints !== null) {
                    $totalPoints = count($payableIds) * $rapPoints + $aogoPoints;
                    // Point value floored to the whole rupee; the remainder
                    // stays unspent (KP confirmed 2026-08-05, same as MSB).
                    $pointValuePaise = Money::floorRupee($poolPaise, $totalPoints);
                    $grossPerQualifier = $rapPoints * $pointValuePaise;
                    $payoutPaise = ($paidCount * $rapPoints + $aogoPoints) * $pointValuePaise;
                } else {
                    $grossPerQualifier = $payableIds !== []
                        ? intdiv($poolPaise, count($payableIds))
                        : 0;
                    $payoutPaise = $grossPerQualifier * $paidCount;
                }

                $pool = RankMonthlyPool::create([
                    'month_start' => $monthStart,
                    'rank_number' => $rank,
                    'company_turnover_paise' => $turnoverPaise,
                    'envelope_bp' => $envelopeBp,
                    'pool_pct' => $poolPct,
                    'pool_paise' => $poolPaise,
                    'rap_points' => $rapPoints,
                    'payable_count' => count($payableIds),
                    'aogo_points' => $aogoPoints,
                    'total_points' => $totalPoints,
                    'point_value_paise' => $pointValuePaise,
                    'gross_per_qualifier_paise' => $grossPerQualifier,
                    'payout_paise' => $payoutPaise,
                    'leftover_paise' => $poolPaise - $payoutPaise,
                ]);

                $pools[$rank] = $pool;

                // Requalification-held: recorded, never credited, never
                // back-paid. Snapshot columns stay null — they were not in the
                // denominator and no point value applies to them.
                foreach ($heldIds as $distributorId) {
                    $this->writeRosterRow($distributorId, $pool, RankBonusResult::STATUS_REQUALIFICATION_HELD, 0);
                }

                foreach ($payableIds as $distributorId) {
                    // A repurchase-held achiever carries the SAME gross and the
                    // same snapshot columns as a paid one — only the status
                    // differs — so ReleaseHeldRankBonusOnReactivation can pay it
                    // at the rate the month was priced at.
                    $this->writeRosterRow(
                        $distributorId,
                        $pool,
                        in_array($distributorId, $repurchaseHeldIds, true)
                            ? RankBonusResult::STATUS_REPURCHASE_HELD
                            : RankBonusResult::STATUS_PENDING,
                        $grossPerQualifier,
                        rapPoints: $rapPoints,
                        totalPoints: $totalPoints,
                        pointValuePaise: $pointValuePaise,
                    );
                }

                foreach ($grants as $grant) {
                    $this->writeRosterRow(
                        (int) $grant->distributor_id,
                        $pool,
                        RankBonusResult::STATUS_PENDING,
                        $grant->points * (int) $pointValuePaise,
                        aogoPoints: $grant->points,
                        totalPoints: $totalPoints,
                        pointValuePaise: $pointValuePaise,
                    );
                }
            }

            $this->recordFreeze($monthStart, $pools);

            return $pools;
        });
    }

    /**
     * Refuse to freeze a month the PRE-FREEZE engine already paid.
     *
     * `rank_monthly_pools` did not exist before this change, so every month run
     * by the old engine has credited `rank_bonus_results` rows and no pool row —
     * which is indistinguishable, to {@see frozenPools()}, from a month that was
     * never run. Freezing such a month now prices a fresh pool against TODAY's
     * roster; {@see writeRosterRow()} skips the rows already `credited`, so any
     * distributor the new roster deems payable but the old run did not gets a
     * `pending` row at the full pool ÷ payable_count and is paid on top of what
     * the month already spent. Pool ₹6,000, two paid ₹3,000 each, a third
     * arriving → ₹8,000 out of a ₹6,000 pool.
     *
     * The divergence is likely, not rare: the now month-aware
     * RankRequalificationGateService::walletClearedMap() can legitimately place
     * a distributor on the recomputed roster that the old code held back.
     *
     * Reconstructing the frozen pool from the credited rows is rejected: the
     * rows carry pool_paise / qualifier_count / point_value_paise but NOT
     * envelope_bp or pool_pct (both admin-editable and possibly since changed),
     * the ranks the old run never touched have no row to reconstruct from at
     * all, and — decisively — reconstructing the pool would not stop
     * freezeMonth() handing today's roster a fresh `pending` row against it.
     * A month priced by the old engine is closed; it is not re-openable.
     */
    private function refuseUnfrozenPaidMonth(string $monthStart): void
    {
        $paidRows = RankBonusResult::query()
            ->where('month_start', $monthStart)
            ->whereIn('status', [
                RankBonusResult::STATUS_CREDITED,
                RankBonusResult::STATUS_REVERSED,
            ])
            ->count();

        if ($paidRows === 0) {
            return;
        }

        Log::warning('rank.pool.unfrozen_paid_month_refused', [
            'month_start' => $monthStart,
            'paid_rows' => $paidRows,
        ]);

        throw new \RuntimeException(
            "Refusing to run the Rank Bonus for {$monthStart}: the month has {$paidRows} already-paid "
            .'rank_bonus_results row(s) but no rank_monthly_pools row, so it was priced and credited by the '
            .'pre-freeze engine. Freezing it now would divide a fresh pool against today\'s roster and pay a '
            ."second time out of a pool the month has already spent.\n"
            .'The month cannot be reconstructed — its envelope and per-rank pool percentages were never '
            .'snapshotted. Leave it closed; if it genuinely must be replayed, its rank_bonus_results rows have '
            .'to be cleared deliberately first (compensation:recompute-all does that for every month).'
        );
    }

    /**
     * Write one roster row. The status is decided ONCE, here — which is what
     * stops a repurchase-wallet block from later overwriting a
     * requalification-held row (and the reverse).
     *
     * Returns null when the distributor is already credited for the month and
     * rank: legacy rows written before the freeze existed must never be
     * rewritten by it.
     */
    private function writeRosterRow(
        int $distributorId,
        RankMonthlyPool $pool,
        string $status,
        int $grossPaise,
        ?int $rapPoints = null,
        ?int $aogoPoints = null,
        ?int $totalPoints = null,
        ?int $pointValuePaise = null,
    ): ?RankBonusResult {
        $alreadyCredited = RankBonusResult::query()
            ->where('distributor_id', $distributorId)
            ->where('month_start', $pool->month_start)
            ->where('rank_number', $pool->rank_number)
            ->where('status', RankBonusResult::STATUS_CREDITED)
            ->exists();

        if ($alreadyCredited) {
            return null;
        }

        return RankBonusResult::updateOrCreate(
            [
                'distributor_id' => $distributorId,
                'month_start' => $pool->month_start,
                'rank_number' => $pool->rank_number,
            ],
            [
                // Both columns are unsigned on rank_bonus_results; the signed
                // truth for a refund-heavy month lives on rank_monthly_pools.
                'company_turnover_paise' => max(0, $pool->company_turnover_paise),
                'pool_paise' => max(0, $pool->pool_paise),
                'qualifier_count' => $pool->payable_count,
                'rap_points' => $rapPoints,
                'aogo_points' => $aogoPoints,
                'total_points' => $totalPoints,
                'point_value_paise' => $pointValuePaise,
                'gross_paise' => $grossPaise,
                'admin_charge_paise' => 0,
                'tds_paise' => 0,
                'net_paise' => $grossPaise,
                'status' => $status,
            ],
        );
    }

    /**
     * The freeze decides every Rank Bonus payout for the month — a
     * retention-guaranteed audit_log row, not just a log line (R-35).
     *
     * @param  Collection<int, RankMonthlyPool>  $pools
     */
    private function recordFreeze(string $monthStart, Collection $pools): void
    {
        $details = [
            'month_start' => $monthStart,
            'company_turnover_paise' => (int) $pools[1]->company_turnover_paise,
            'envelope_bp' => (int) $pools[1]->envelope_bp,
            'by_rank' => $pools->map(fn (RankMonthlyPool $pool): array => [
                'pool_paise' => (int) $pool->pool_paise,
                'payable_count' => (int) $pool->payable_count,
                'aogo_points' => (int) $pool->aogo_points,
                'total_points' => $pool->total_points,
                'point_value_paise' => $pool->point_value_paise,
                'payout_paise' => (int) $pool->payout_paise,
                'leftover_paise' => (int) $pool->leftover_paise,
            ])->all(),
        ];

        Log::info('rank.pool.frozen', $details);

        AuditLog::create([
            'action' => 'rank.pool.frozen',
            'subject_type' => 'rank_monthly_pool',
            'subject_id' => $pools[1]->id,
            'details' => $details,
        ]);
    }

    /**
     * Delete a month's pool rows that were frozen before the month had closed,
     * so the caller can freeze it afresh. Returns true when they were removed.
     * The Rank twin of {@see GrowthBoosterBonusService::replacePrematureFreeze()}.
     *
     * "Frozen economics" assumes the freeze happened once the month's BV and
     * its qualifier roster were final — the scheduler guarantees that by
     * running on the 1st. A freeze whose created_at falls BEFORE the month
     * ended broke that assumption (a mid-month manual run from the Engine Runs
     * page, or the recompute tool catching up the period in flight): it
     * snapshotted partial company BV and a partial roster, and every later run
     * for the month would silently price against it.
     *
     * Replacement is only safe while NOTHING the pool funded was actually
     * credited: once a wallet has moved on a pool-priced gross, re-freezing
     * would change economics money moved on, so the rows are kept and the
     * inconsistency surfaced loudly instead. Un-credited rows moved no money,
     * but they DO block the re-run (a `credited` row is the idempotency guard
     * in {@see writeRosterRow()}), so they are cleared along with the pools and
     * recomputed from the fresh snapshot. AO-GO grants are deliberately kept:
     * they are idempotent per month, so a refreeze reuses them and consumes no
     * second lifetime use.
     *
     * @param  Collection<int, RankMonthlyPool>  $pools
     */
    private function replacePrematureFreeze(Collection $pools, string $monthStart, Carbon $monthEnd): bool
    {
        $frozenAt = $pools->min(fn (RankMonthlyPool $pool): ?Carbon => $pool->created_at);
        $monthClosedAt = $monthEnd->copy()->addDay()->startOfDay();

        if (! $frozenAt instanceof Carbon || $frozenAt->gte($monthClosedAt)) {
            return false; // Frozen after the month closed — the normal, final row.
        }

        $details = [
            'month_start' => $monthStart,
            'frozen_at' => $frozenAt->toDateTimeString(),
            'company_turnover_paise' => (int) $pools[1]->company_turnover_paise,
            'pool_paise' => $pools->map(fn (RankMonthlyPool $pool): int => (int) $pool->pool_paise)->all(),
            'payable_count' => $pools->map(fn (RankMonthlyPool $pool): int => (int) $pool->payable_count)->all(),
        ];

        $results = RankBonusResult::query()->where('month_start', $monthStart);

        if ($results->clone()->whereIn('status', [
            RankBonusResult::STATUS_CREDITED,
            RankBonusResult::STATUS_REVERSED,
        ])->exists()) {
            Log::warning('rank.pool.premature_freeze_kept', $details + [
                'reason' => 'results were already credited against these pools; re-freezing would change economics money moved on',
            ]);

            return false;
        }

        // Snapshot what the hard delete is about to destroy — id, distributor,
        // rank, status and gross — so the deletion stays reconstructable from
        // `audit_log` alone. A bare count is not.
        $discarded = $results->clone()
            ->get(['id', 'distributor_id', 'rank_number', 'status', 'gross_paise'])
            ->map(fn (RankBonusResult $row): array => [
                'id' => (int) $row->id,
                'distributor_id' => (int) $row->distributor_id,
                'rank_number' => (int) $row->rank_number,
                'status' => $row->status,
                'gross_paise' => (int) $row->gross_paise,
            ])
            ->all();

        $discardedResults = $results->clone()->delete();

        Log::warning('rank.pool.premature_freeze_replaced', $details + [
            'discarded_results' => $discardedResults,
        ]);

        AuditLog::create([
            'action' => 'rank.pool.refrozen',
            'subject_type' => 'rank_monthly_pool',
            'subject_id' => $pools[1]->id,
            'details' => $details + [
                'discarded_results' => $discardedResults,
                'discarded_rows' => $discarded,
                'reason' => 'pools were frozen before the month ended and nothing they funded was credited',
            ],
        ]);

        RankMonthlyPool::where('month_start', $monthStart)->delete();

        return true;
    }

    // ---------------------------------------------------------------- pass 2

    /**
     * Pass 2 — credit the frozen roster. Only rows still `pending` are paid,
     * at the gross frozen on them; the pool, the denominator and the point
     * value are never touched again.
     *
     * @param  Collection<int, RankMonthlyPool>  $pools
     * @return array{
     *     turnover_paise: int,
     *     credited: int,
     *     qualified_after_freeze: int,
     *     by_rank: array<int, array{qualifiers: int, held: int, aogo_grants: int, pool_paise: int, total_points: int|null, point_value_paise: int|null, gross_total: int, qualified_after_freeze: int}>
     * }
     */
    private function creditFromFrozenPools(Carbon $monthStartCarbon, string $monthStart, Collection $pools): array
    {
        $late = $this->qualifiedAfterFreeze($monthStartCarbon);

        foreach ($late as $rank => $distributorIds) {
            foreach ($distributorIds as $distributorId) {
                // Refusing a late qualifier permanently withholds a month's
                // Rank Bonus from someone who did reach the rank, and the month
                // is never reopened. That decision gets a retention-guaranteed
                // audit_log row a grievance officer can still query years later,
                // not only a log line that rotates away (R-35) — the same
                // reasoning as `fortune.enroll.matrix_full`.
                $details = [
                    'month_start' => $monthStart,
                    'rank_number' => $rank,
                    'distributor_id' => $distributorId,
                    'reason' => 'qualified after the month\'s pool was frozen — refused, never paid from a divided pool',
                ];

                Log::warning('rank.result.qualified_after_freeze', $details);

                AuditLog::create([
                    'action' => 'rank.result.qualified_after_freeze',
                    'subject_type' => 'distributor',
                    'subject_id' => $distributorId,
                    'details' => $details,
                ]);
            }
        }

        $grants = $this->liveAogoGrants($monthStart);

        $credited = 0;
        $byRank = [];

        DB::transaction(function () use ($monthStartCarbon, $monthStart, $pools, $grants, $late, &$credited, &$byRank): void {
            foreach (self::RANKS as $rank) {
                $pool = $pools[$rank] ?? null;

                if ($pool === null) {
                    continue;
                }

                /** @var Collection<int, RankBonusResult> $rows */
                $rows = RankBonusResult::query()
                    ->where('month_start', $monthStart)
                    ->where('rank_number', $rank)
                    ->get();

                $byRank[$rank] = [
                    'qualifiers' => (int) $pool->payable_count,
                    'held' => $rows->where('status', RankBonusResult::STATUS_REQUALIFICATION_HELD)->count(),
                    'repurchase_held' => $rows->where('status', RankBonusResult::STATUS_REPURCHASE_HELD)->count(),
                    'aogo_grants' => $rows->whereNotNull('aogo_points')->count(),
                    'pool_paise' => (int) $pool->pool_paise,
                    'total_points' => $pool->total_points,
                    'point_value_paise' => $pool->point_value_paise,
                    'gross_total' => 0,
                    'qualified_after_freeze' => count($late[$rank] ?? []),
                ];

                foreach ($rows as $row) {
                    $isHeld = $row->status === RankBonusResult::STATUS_REPURCHASE_HELD;

                    if ($row->status !== RankBonusResult::STATUS_PENDING && ! $isHeld) {
                        continue;
                    }

                    if (! $isHeld && $row->gross_paise > 0) {
                        $this->creditRosterRow($row, $monthStartCarbon, $monthStart, $grants);

                        $byRank[$rank]['gross_total'] += (int) $row->gross_paise;
                        $credited++;
                    }

                    // AO-GO grantees hold no rank this month — the lifetime
                    // award belongs to the achievers only. A repurchase-held
                    // achiever still qualified (rule 6), so their milestone
                    // tracks even though the money waits.
                    if ($row->aogo_points === null) {
                        $this->syncLifetimeAward((int) $row->distributor_id, $rank, $monthStart);
                    }
                }
            }
        });

        return [
            'turnover_paise' => (int) $pools[1]->company_turnover_paise,
            'credited' => $credited,
            'qualified_after_freeze' => array_sum(array_map(count(...), $late)),
            'by_rank' => $byRank,
        ];
    }

    /**
     * Release one repurchase-held roster row: credit the gross the month was
     * frozen at and settle the AO-GO grant behind it. Called by
     * {@see ReleaseHeldRankBonusOnReactivation}
     * the day the distributor fulfils their repurchase obligation (rule 8),
     * never by the monthly run.
     */
    public function releaseHeldRow(RankBonusResult $row): void
    {
        $monthStart = Carbon::parse((string) $row->month_start)->startOfMonth();

        $this->creditRosterRow(
            $row,
            $monthStart,
            $monthStart->toDateString(),
            $this->aogo->grantForMonth($monthStart)->keyBy('distributor_id'),
        );
    }

    /**
     * Credit one frozen roster row to the wallet and settle its AO-GO grant,
     * when the row is one.
     *
     * @param  Collection<int, RankAogoGrant>  $grants  live grants keyed by distributor id
     */
    private function creditRosterRow(
        RankBonusResult $row,
        Carbon $monthStartCarbon,
        string $monthStart,
        Collection $grants,
    ): void {
        $isAogo = $row->aogo_points !== null;

        $outcome = $this->wallet->creditWithRepurchaseDeduction(
            distributorId: (int) $row->distributor_id,
            grossPaise: (int) $row->gross_paise,
            bonusType: 'rank_credit',
            referenceId: $row->id,
            referenceType: 'rank_bonus_result',
            bonusMonth: $monthStartCarbon,
            memo: ($isAogo ? 'AO-GO Offer ' : $this->plan->rankName((int) $row->rank_number).' Bonus ').$monthStart,
        );

        $row->update([
            'status' => RankBonusResult::STATUS_CREDITED,
            'credited_at' => now(),
            'repurchase_deduction_paise' => $outcome->repurchaseDeductionPaise,
            'net_paise' => $outcome->creditedPaise(),
        ]);

        if (! $isAogo) {
            return;
        }

        $grants->get((int) $row->distributor_id)?->update([
            'point_value_paise' => $row->point_value_paise,
            'income_paise' => (int) $row->gross_paise,
            'status' => RankAogoGrant::STATUS_CREDITED,
            'credited_at' => now(),
        ]);
    }

    /**
     * This month's live AO-GO grants, keyed by distributor id. Read-only —
     * grants are created once, in pass 1, so a re-run can never mint a new one
     * against a pool that has already been divided.
     *
     * @return Collection<int, RankAogoGrant>
     */
    private function liveAogoGrants(string $monthStart): Collection
    {
        return RankAogoGrant::query()
            ->live()
            ->where('month_start', $monthStart)
            ->get()
            ->keyBy(fn (RankAogoGrant $grant): int => (int) $grant->distributor_id);
    }

    /**
     * Keep the distributor's LifetimeAwardMilestone in step with the months
     * they actually made the rank's payable roster for.
     *
     * The count is RECOMPUTED from the roster rows, never incremented: the old
     * increment fired on every run for any distributor whose row never reached
     * `credited` — which is every payable distributor in a month where
     * Money::floorRupee truncated the gross to 0 — so three re-runs of one
     * month counted three qualifications. Recomputing also survives
     * {@see replacePrematureFreeze()} discarding and rewriting a month's rows.
     *
     * Already delivered/cancelled milestones are left untouched.
     */
    private function syncLifetimeAward(int $distributorId, int $rank, string $monthStart): void
    {
        $qualificationCount = RankBonusResult::query()
            ->where('distributor_id', $distributorId)
            ->where('rank_number', $rank)
            ->whereIn('status', [
                RankBonusResult::STATUS_PENDING,
                RankBonusResult::STATUS_CREDITED,
                RankBonusResult::STATUS_REVERSED,
                // Rule 6: Awards & Rewards are outside the repurchase
                // condition, so a held month still counts as a qualification.
                RankBonusResult::STATUS_REPURCHASE_HELD,
            ])
            ->distinct()
            ->count('month_start');

        $existing = LifetimeAwardMilestone::where('distributor_id', $distributorId)
            ->where('rank_number', $rank)
            ->first();

        if ($existing === null) {
            LifetimeAwardMilestone::create([
                'distributor_id' => $distributorId,
                'rank_number' => $rank,
                'triggered_month' => $monthStart,
                'qualification_count' => max(1, $qualificationCount),
                'award_description' => $this->plan->rankName($rank).' — non-cash reward per plan',
                'status' => LifetimeAwardMilestone::STATUS_PENDING,
            ]);

            return;
        }

        // Only a pending milestone tracks toward the release threshold (D4).
        if ($existing->status === LifetimeAwardMilestone::STATUS_PENDING) {
            $existing->update(['qualification_count' => max(1, $qualificationCount)]);
        }
    }

    /**
     * The month's frozen pools, keyed by rank number. Empty when the month has
     * never been frozen.
     *
     * @return Collection<int, RankMonthlyPool>
     */
    private function frozenPools(string $monthStart): Collection
    {
        return RankMonthlyPool::where('month_start', $monthStart)
            ->get()
            ->keyBy(fn (RankMonthlyPool $pool): int => (int) $pool->rank_number);
    }
}
