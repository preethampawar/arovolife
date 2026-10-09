<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Console\Commands\RankBonusRunCommand;
use App\Modules\Compensation\Exceptions\RepurchaseVerdictsPending;
use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\RankAogoGrant;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Models\RankMonthlyPass;
use App\Modules\Compensation\Models\RankMonthlyPool;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Services\DTOs\RankMonthRoster;
use App\Modules\Compensation\Support\PrematureFreezeAlert;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Shared\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Monthly Rank Bonus engine (client 2026-10-05 Rank Income Point System).
 *
 * "Turnover" here means COMPANY BV — the signed bv_ledger_entries sum for the
 * month (GsbDailyPoolService::companyBvPaiseBetween), not order sales value.
 * The Rank Bonus envelope (comp.rank.envelope_bp, default 2000 bp = 20%) is
 * carved out of that BV with integer arithmetic and floored at 0, so a
 * refund-heavy month can never send a negative amount to a wallet.
 *
 * The envelope is ONE pool, divided in two pricing passes. Every rank carries
 * Rank Achievement Points (rank_tiers.rap_points: 72 / 189 / 468 / 1,125 /
 * 2,583 / 5,688 / 11,934 / 23,877 / 39,501) and every AO-GO grant carries
 * comp.rank.aogo_points (36), counted on the Rank-1 row.
 *  - Pass 1: the AGO offer and Ranks 1..N (comp.rank.first_pass_max_rank,
 *    default 3) divide the WHOLE envelope.
 *  - Pass 2: Ranks N+1..9 divide what pass 1 left.
 * Each pass: point value = floor-to-whole-rupee(pool ÷ points), then capped at
 * comp.rank.point_value_cap_paise (₹200). Each participant is paid own points ×
 * the point value of their pass. What pass 2 leaves stays with the company.
 *
 * Worked example D2 (client 2026-10-05): 16 Cr BV → 3.2 Cr envelope. Pass 1:
 * 5,796 points (9 × 72 + 8 × 189 + 7 × 468 + 10 AGO × 36) → raw ₹5,521 → capped
 * at ₹200 → ₹11,59,200 paid. Pass 2: ₹3,08,40,800 ÷ 1,65,474 points → ₹186 →
 * ₹3,07,78,164 paid, ₹62,636 left with the company.
 *
 * Fail-safe: a cap under ₹1, a rank with payable achievers but no RAP, or an
 * achiever whose repurchase cycle due inside the month has no verdict yet
 * stops the freeze before any write; the freeze reconciles roster gross,
 * pool payout and pass payout against the envelope before it commits.
 *
 * THREE PHASES (the GsbCutoffService shape):
 *  1. Roster — {@see resolveRoster()} decides the month's population: the
 *     qualifiers per rank, the §8 requalification gate (which carries its own
 *     repurchase-wallet condition), and the AO-GO grants.
 *  2. Freeze — {@see freezeMonth()} writes, in ONE transaction, the two
 *     rank_monthly_passes rows, the nine rank_monthly_pools rows AND a
 *     rank_bonus_results row for every roster member carrying its decided
 *     status. A crash can therefore never leave a qualifier outside a roster
 *     that is about to close.
 *  3. Credit — {@see creditFromFrozenPools()} credits roster members from the
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
 * The repurchase cycle never withholds Rank Bonus (client 2026-09-07, §2.2).
 * It reaches this engine only upstream, at qualification: RankQualification-
 * Service counts Genos BV over the days the distributor was not failed, and a
 * failed day's group BV is forfeited permanently. Once the rank is achieved on
 * the surviving BV the money follows — credited on the 1st and paid on the 8th
 * even if the distributor is still failed at month end.
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

    /** Roster rows whose achiever's Lifetime Award tranches are kept in step on every run. */
    private const AWARD_SYNC_STATUSES = [RankBonusResult::STATUS_PENDING, RankBonusResult::STATUS_CREDITED];

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
     *     by_rank: array<int, array{qualifiers: int, held: int, aogo_grants: int, pool_paise: int, total_points: int|null, point_value_paise: int|null, gross_total: int, qualified_after_freeze: int}>,
     *     passes: array<int, array{pool_paise: int, total_points: int, raw_point_value_paise: int, point_value_cap_paise: int, point_value_paise: int, payout_paise: int, leftover_paise: int}>
     * }
     *
     * @throws RepurchaseVerdictsPending when a payable achiever's cycle due on or before the month end has no verdict yet
     * @throws \RuntimeException when the plan configuration would pay ₹0 or divide by a missing number
     */
    public function runForMonth(Carbon $month): array
    {
        $monthStartCarbon = $month->copy()->startOfMonth();
        $monthStart = $monthStartCarbon->toDateString();
        $monthEnd = $month->copy()->endOfMonth();

        $pools = $this->frozenPools($monthStart);

        // Only a run that will WRITE a freeze is checked: none exists yet, or a
        // premature one will be replaced. A premature freeze that money already
        // moved on is kept ({@see replacePrematureFreeze()}), and a run over it
        // only credits figures that freeze decided — refusing it over a setting
        // changed since, or a verdict the frozen roster never reads again,
        // would hold back payouts and protect nothing.
        if ($pools->isEmpty()
            || ($this->frozenBeforeMonthClosed($pools, $monthEnd) && ! $this->monthHasCreditedResults($monthStart))) {
            // A bad setting or a pending repurchase verdict stops the run here,
            // before a premature freeze is replaced, not after — a refusal must
            // never delete what it then cannot rebuild (the GBB twin).
            [$payable] = $this->resolvePayableAndHeld($monthStartCarbon, $monthStart);
            $this->assertFreezable($monthStart, $monthEnd, $payable);
        }

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
        [$payable, $held] = $this->resolvePayableAndHeld($monthStartCarbon, $monthStart);

        return new RankMonthRoster(
            payableIds: $payable,
            heldIds: $held,
            aogoGrants: $this->aogo->grantForMonth($monthStartCarbon),
        );
    }

    /**
     * The month's achievers per rank, split into payable and §8-held. Pure
     * reads — unlike {@see resolveRoster()} it mints no AO-GO grant, so the
     * pre-freeze checks in {@see runForMonth()} can use it before anything is
     * written or replaced.
     *
     * @return array{0: array<int, list<int>>, 1: array<int, list<int>>} [payable, held], each rank → distributor ids
     */
    private function resolvePayableAndHeld(Carbon $monthStartCarbon, string $monthStart): array
    {
        $qualifiers = $this->qualifierIdsByRank($monthStart);

        $payable = [];
        $held = [];

        foreach (self::RANKS as $rank) {
            $qualifierIds = $qualifiers[$rank] ?? [];

            $heldIds = $this->requalificationHeldIds($qualifierIds, $monthStartCarbon, $rank);

            $payable[$rank] = array_values(array_diff($qualifierIds, $heldIds));
            $held[$rank] = $heldIds;
        }

        return [$payable, $held];
    }

    /**
     * Refuse to freeze before any write when the month cannot be priced
     * honestly. Returns the point value cap in force.
     *
     * Fail-safe principle 1: a cap under ₹1 would price every point at ₹0 and
     * look like a quiet month, a sub-rupee cap would pay a value every display
     * rounds away, a pass-1 ceiling outside 1–9 would silently re-split the
     * passes, and a rank with payable achievers but no RAP would pay them
     * nothing — all stop the freeze; none is clamped or rounded. The two
     * settings are validated by their accessors, read here so the refusal
     * lands before a premature freeze is replaced, not inside the freeze.
     *
     * Fail-safe principle 2: an unresolved repurchase cycle reads as eligible
     * in IncomeEligibilityService::verdictAsOf(), and the frozen roster is
     * never re-judged, so the freeze waits for `repurchase:evaluate` to judge
     * every payable achiever's cycle due on or before the month end. The
     * monthly command ({@see RankBonusRunCommand}) records the refusal as a
     * failed run naming the remedy.
     *
     * @param  array<int, list<int>>  $payable  rank → payable distributor ids
     *
     * @throws RepurchaseVerdictsPending
     * @throws \RuntimeException
     */
    private function assertFreezable(string $monthStart, Carbon $monthEnd, array $payable): int
    {
        $capPaise = $this->plan->rankPointValueCapPaise();
        $this->plan->rankFirstPassMaxRank();

        foreach (self::RANKS as $rank) {
            if (($payable[$rank] ?? []) !== [] && $this->plan->rankRapPoints($rank) <= 0) {
                throw new \RuntimeException("rank_tiers.rap_points is not set for rank {$rank} but it has payable achievers; refusing to freeze the Rank Bonus for {$monthStart}");
            }
        }

        $this->assertAwardTranchesSeeded(array_keys(array_filter($payable)));

        $allPayable = array_values(array_unique(array_merge(...array_values($payable))));
        $pending = $this->eligibility->unresolvedDueOnOrBefore($monthEnd, $allPayable);

        if ($pending !== []) {
            throw RepurchaseVerdictsPending::forMonthEnd('Rank Bonus', $monthEnd, $pending);
        }

        return $capPaise;
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
     * Freeze the month: the roster, then the two pass rows, the nine pool rows
     * and every roster row, in one transaction, reconciled before it commits.
     * Nothing is credited here — {@see creditFromFrozenPools()} does that from
     * what this wrote.
     *
     * @return Collection<int, RankMonthlyPool> keyed by rank number
     */
    private function freezeMonth(Carbon $monthStartCarbon, string $monthStart, Carbon $monthEnd): Collection
    {
        return DB::transaction(function () use ($monthStartCarbon, $monthStart, $monthEnd): Collection {
            $roster = $this->resolveRoster($monthStartCarbon, $monthStart);

            // Fail-safe principles 1 and 2, asked again against the roster this
            // transaction froze (runForMonth() asked before anything moved).
            $capPaise = $this->assertFreezable(
                $monthStart,
                $monthEnd,
                array_combine(self::RANKS, array_map(fn (int $rank): array => $roster->payableFor($rank), self::RANKS)),
            );

            $turnoverPaise = $this->gsbPool->companyBvPaiseBetween($monthStartCarbon, $monthEnd);
            $envelopeBp = $this->plan->rankEnvelopeBp();
            // F-9: integer arithmetic only. 16 Cr BV × 2,000 bp = 3.2 × 10¹⁴,
            // far inside 64-bit.
            $envelopePaise = max(0, intdiv($turnoverPaise * $envelopeBp, 10_000));
            $firstPassMax = $this->plan->rankFirstPassMaxRank();

            // Points per rank: payable achievers × RAP, plus the AGO offer's
            // points on the Rank-1 row (client 2026-10-05: AGO is a pass-1
            // participant with its own points).
            $aogoPoints = (int) $roster->aogoGrants->sum('points');
            $rankPoints = [];
            foreach (self::RANKS as $rank) {
                $rankPoints[$rank] = count($roster->payableFor($rank)) * $this->plan->rankRapPoints($rank)
                    + ($rank === 1 ? $aogoPoints : 0);
            }

            // Pass 1 divides the whole envelope; pass 2 divides what pass 1
            // left. Each pass: floor to the whole rupee, then cap.
            /** @var array<int, RankMonthlyPass> $passes */
            $passes = [];
            $remaining = $envelopePaise;
            foreach ([1, 2] as $pass) {
                $points = 0;
                foreach (self::RANKS as $rank) {
                    if ($this->passFor($rank, $firstPassMax) === $pass) {
                        $points += $rankPoints[$rank];
                    }
                }

                $raw = Money::floorRupee($remaining, $points);
                $value = min($raw, $capPaise);
                $payout = $value * $points;

                $passes[$pass] = RankMonthlyPass::create([
                    'month_start' => $monthStart,
                    'pass' => $pass,
                    'company_turnover_paise' => $turnoverPaise,
                    'envelope_bp' => $envelopeBp,
                    'envelope_paise' => $envelopePaise,
                    'pool_paise' => $remaining,
                    'total_points' => $points,
                    'raw_point_value_paise' => $raw,
                    'point_value_cap_paise' => $capPaise,
                    'point_value_paise' => $value,
                    'payout_paise' => $payout,
                    'leftover_paise' => $remaining - $payout,
                ]);

                $remaining -= $payout;
            }

            /** @var Collection<int, RankMonthlyPool> $pools */
            $pools = collect();

            // The ids of the roster rows this freeze writes — the local
            // accumulator reconcileFreeze() checks against, so a legacy
            // `pending` row it did not write cannot block the freeze.
            /** @var array<int, true> $writtenIds */
            $writtenIds = [];

            foreach (self::RANKS as $rank) {
                $pass = $this->passFor($rank, $firstPassMax);
                $pointValuePaise = (int) $passes[$pass]->point_value_paise;
                $rapPoints = $this->plan->rankRapPoints($rank);
                $payableIds = $roster->payableFor($rank);
                $heldIds = $roster->heldFor($rank);
                /** @var Collection<int, RankAogoGrant> $grants */
                $grants = $rank === 1 ? $roster->aogoGrants : collect();
                $totalPoints = $rankPoints[$rank];
                $grossPerQualifier = $rapPoints * $pointValuePaise;
                $payoutPaise = $totalPoints * $pointValuePaise;

                $pool = RankMonthlyPool::create([
                    'month_start' => $monthStart,
                    'rank_number' => $rank,
                    'pass' => $pass,
                    'company_turnover_paise' => $turnoverPaise,
                    'envelope_bp' => $envelopeBp,
                    'pool_paise' => $payoutPaise,          // this rank's allotment
                    'rap_points' => $rapPoints,
                    'payable_count' => count($payableIds),
                    'aogo_points' => $rank === 1 ? $aogoPoints : 0,
                    'total_points' => $totalPoints,
                    'point_value_paise' => $pointValuePaise,
                    'gross_per_qualifier_paise' => $grossPerQualifier,
                    'payout_paise' => $payoutPaise,
                    'leftover_paise' => 0,                 // the pass row carries the leftover
                ]);

                $pools[$rank] = $pool;

                // Requalification-held: recorded, never credited, never
                // back-paid. Snapshot columns stay null — they were not in the
                // denominator and no point value applies to them.
                $written = [];

                foreach ($heldIds as $distributorId) {
                    $written[] = $this->writeRosterRow($distributorId, $pool, RankBonusResult::STATUS_REQUALIFICATION_HELD, 0);
                }

                foreach ($payableIds as $distributorId) {
                    $written[] = $this->writeRosterRow(
                        $distributorId,
                        $pool,
                        RankBonusResult::STATUS_PENDING,
                        $grossPerQualifier,
                        rapPoints: $rapPoints,
                        totalPoints: $totalPoints,
                        pointValuePaise: $pointValuePaise,
                    );
                }

                foreach ($grants as $grant) {
                    $written[] = $this->writeRosterRow(
                        (int) $grant->distributor_id,
                        $pool,
                        RankBonusResult::STATUS_PENDING,
                        $grant->points * $pointValuePaise,
                        aogoPoints: $grant->points,
                        totalPoints: $totalPoints,
                        pointValuePaise: $pointValuePaise,
                    );
                }

                foreach ($written as $row) {
                    if ($row !== null) {
                        $writtenIds[(int) $row->id] = true;
                    }
                }
            }

            $this->reconcileFreeze($monthStart, $envelopePaise, $passes, $pools, $writtenIds);

            $this->recordFreeze($monthStart, $pools, collect($passes));

            return $pools;
        });
    }

    /** The pricing pass a rank belongs to: 1 for Ranks 1..N, 2 for the rest. */
    private function passFor(int $rank, int $firstPassMax): int
    {
        return $rank <= $firstPassMax ? 1 : 2;
    }

    /**
     * Fail-safe principle 3: reconcile before commit. Σ pending roster gross,
     * Σ pool payout and Σ pass payout must agree, never exceed the envelope,
     * and pass 2 must have divided exactly what pass 1 left. Any mismatch is a
     * bug, and a bug rolls the whole freeze back rather than paying out.
     *
     * The roster gross is summed over the rows THIS freeze wrote. A `pending`
     * row of the month it did not write — a legacy row from before the freeze
     * existed, for someone not on today's roster — is not part of the pools
     * and would otherwise block every freeze of the month. It is reported
     * ({@see reportStrayPendingRows()}) and never credited
     * ({@see pricedByFreeze()}).
     *
     * @param  array<int, RankMonthlyPass>  $passes
     * @param  Collection<int, RankMonthlyPool>  $pools
     * @param  array<int, true>  $writtenIds  ids of the roster rows this freeze wrote
     */
    private function reconcileFreeze(string $monthStart, int $envelopePaise, array $passes, Collection $pools, array $writtenIds): void
    {
        $pending = RankBonusResult::query()
            ->where('month_start', $monthStart)
            ->where('status', RankBonusResult::STATUS_PENDING);

        $strays = $pending->clone()
            ->get(['id', 'distributor_id', 'rank_number', 'gross_paise'])
            ->reject(fn (RankBonusResult $row): bool => isset($writtenIds[(int) $row->id]))
            ->values();

        if ($strays->isNotEmpty()) {
            $this->reportStrayPendingRows($monthStart, $strays);
        }

        $rosterGross = (int) $pending->clone()
            ->whereNotIn('id', $strays->pluck('id')->all())
            ->sum('gross_paise');
        $passPayout = (int) $passes[1]->payout_paise + (int) $passes[2]->payout_paise;
        $poolPayout = (int) $pools->sum('payout_paise');

        if ($rosterGross !== $passPayout
            || $poolPayout !== $passPayout
            || $passPayout > $envelopePaise
            || (int) $passes[2]->pool_paise !== $envelopePaise - (int) $passes[1]->payout_paise) {
            throw new \RuntimeException(sprintf(
                'Rank Bonus %s freeze does not reconcile: roster gross %d, pool payout %d, pass payout %d, envelope %d. Rolled back.',
                $monthStart,
                $rosterGross,
                $poolPayout,
                $passPayout,
                $envelopePaise,
            ));
        }
    }

    /**
     * A `pending` row the freeze did not write is left exactly as it is —
     * neither credited, re-priced nor deleted — and recorded in a
     * retention-guaranteed audit_log row naming each one, beside the
     * `rank.pool.frozen` row the same transaction writes (R-35). Only legacy
     * or hand-made data produces one; `compensation:rebuild-month` (whose wipe
     * clears the month's rows) is the way to resolve it.
     *
     * @param  Collection<int, RankBonusResult>  $strays
     */
    private function reportStrayPendingRows(string $monthStart, Collection $strays): void
    {
        $details = [
            'month_start' => $monthStart,
            'rows' => $strays->map(fn (RankBonusResult $row): array => [
                'id' => (int) $row->id,
                'distributor_id' => (int) $row->distributor_id,
                'rank_number' => (int) $row->rank_number,
                'gross_paise' => (int) $row->gross_paise,
            ])->all(),
            'reason' => 'pending rows this freeze did not write: not on the frozen roster, outside the pools, never credited',
        ];

        Log::warning('rank.freeze.stray_pending_rows', $details);

        AuditLog::create([
            'action' => 'rank.freeze.stray_pending_rows',
            'subject_type' => 'rank_bonus_result',
            'subject_id' => (int) $strays->first()?->id,
            'details' => $details,
        ]);
    }

    /**
     * Whether a roster row carries the economics its rank's frozen pool row
     * decided — the marker of a row a freeze wrote. {@see writeRosterRow()}
     * copies the pool's turnover, allotment, payable count, total points and
     * point value onto every row it writes; a legacy `pending` row priced by
     * anything else (an older engine, a hand edit) does not carry all five, so
     * the credit loop never pays it a share of a pool it was never in the
     * denominator of. No column links a result to its pool, and adding one
     * would leave every month frozen before it unreadable to this rule.
     */
    private function pricedByFreeze(RankBonusResult $row, RankMonthlyPool $pool): bool
    {
        return (int) $row->company_turnover_paise === max(0, (int) $pool->company_turnover_paise)
            && (int) $row->pool_paise === max(0, (int) $pool->pool_paise)
            && (int) $row->qualifier_count === (int) $pool->payable_count
            && $row->total_points === $pool->total_points
            && $row->point_value_paise === $pool->point_value_paise;
    }

    /**
     * A `pending` row the credit loop never pays nor syncs an award for: one
     * not priced by its rank's frozen pool, or whose rank has no pool at all.
     */
    private function isStrayPendingRow(RankBonusResult $row, ?RankMonthlyPool $pool): bool
    {
        return $row->status === RankBonusResult::STATUS_PENDING
            && ($pool === null || ! $this->pricedByFreeze($row, $pool));
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
     * envelope_bp or the per-rank pool percentage then in force (both
     * admin-editable and possibly since changed),
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
     * @param  Collection<int, RankMonthlyPass>  $passes
     */
    private function recordFreeze(string $monthStart, Collection $pools, Collection $passes): void
    {
        $details = [
            'month_start' => $monthStart,
            'company_turnover_paise' => (int) $pools[1]->company_turnover_paise,
            'envelope_bp' => (int) $pools[1]->envelope_bp,
            'passes' => $passes->map(fn (RankMonthlyPass $pass): array => $this->passSummary($pass))->all(),
            'by_rank' => $pools->map(fn (RankMonthlyPool $pool): array => [
                'pass' => (int) $pool->pass,
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

        if (! $frozenAt instanceof Carbon || ! $this->frozenBeforeMonthClosed($pools, $monthEnd)) {
            return false; // Frozen after the month closed — the normal, final row.
        }

        $details = [
            'month_start' => $monthStart,
            'frozen_at' => $frozenAt->toDateTimeString(),
            'company_turnover_paise' => (int) $pools[1]->company_turnover_paise,
            'pool_paise' => $pools->map(fn (RankMonthlyPool $pool): int => (int) $pool->pool_paise)->all(),
            'payable_count' => $pools->map(fn (RankMonthlyPool $pool): int => (int) $pool->payable_count)->all(),
            'passes' => $this->frozenPassSummaries($monthStart),
        ];

        $results = RankBonusResult::query()->where('month_start', $monthStart);

        if ($this->monthHasCreditedResults($monthStart)) {
            $reason = 'results were already credited against these pools; re-freezing would change economics money moved on';

            Log::warning('rank.pool.premature_freeze_kept', $details + ['reason' => $reason]);

            PrematureFreezeAlert::kept(
                engineKey: 'rank.bonus',
                subjectType: 'rank_monthly_pool',
                subjectId: $pools[1]->id,
                period: Carbon::parse($monthStart)->format('Y-m'),
                reason: $reason,
                details: $details,
            );

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

        // The pass rows are part of the frozen month (F-10): they go with the
        // pools, never one without the other.
        RankMonthlyPass::where('month_start', $monthStart)->delete();
        RankMonthlyPool::where('month_start', $monthStart)->delete();

        return true;
    }

    /**
     * True once a wallet has moved on the month's frozen figures — the line
     * between a premature freeze {@see replacePrematureFreeze()} may replace
     * and one it must keep.
     */
    private function monthHasCreditedResults(string $monthStart): bool
    {
        return RankBonusResult::query()
            ->where('month_start', $monthStart)
            ->whereIn('status', [
                RankBonusResult::STATUS_CREDITED,
                RankBonusResult::STATUS_REVERSED,
            ])
            ->exists();
    }

    /**
     * True when the month's pools were frozen before the month had closed —
     * the candidates {@see replacePrematureFreeze()} may replace.
     *
     * @param  Collection<int, RankMonthlyPool>  $pools
     */
    private function frozenBeforeMonthClosed(Collection $pools, Carbon $monthEnd): bool
    {
        $frozenAt = $pools->min(fn (RankMonthlyPool $pool): ?Carbon => $pool->created_at);

        return $frozenAt instanceof Carbon
            && $frozenAt->lt($monthEnd->copy()->addDay()->startOfDay());
    }

    /**
     * The month's frozen passes, keyed by pass number. Empty for a month
     * priced under the per-rank pool rule in force before the client's
     * 2026-10-05 two-pass rule.
     *
     * @return array<int, array{pool_paise: int, total_points: int, raw_point_value_paise: int, point_value_cap_paise: int, point_value_paise: int, payout_paise: int, leftover_paise: int}>
     */
    private function frozenPassSummaries(string $monthStart): array
    {
        return RankMonthlyPass::where('month_start', $monthStart)
            ->orderBy('pass')
            ->get()
            ->keyBy(fn (RankMonthlyPass $pass): int => (int) $pass->pass)
            ->map(fn (RankMonthlyPass $pass): array => $this->passSummary($pass))
            ->all();
    }

    /**
     * A frozen pass as the run summary and the freeze audit row report it.
     *
     * @return array{pool_paise: int, total_points: int, raw_point_value_paise: int, point_value_cap_paise: int, point_value_paise: int, payout_paise: int, leftover_paise: int}
     */
    private function passSummary(RankMonthlyPass $pass): array
    {
        return [
            'pool_paise' => (int) $pass->pool_paise,
            'total_points' => (int) $pass->total_points,
            'raw_point_value_paise' => (int) $pass->raw_point_value_paise,
            'point_value_cap_paise' => (int) $pass->point_value_cap_paise,
            'point_value_paise' => (int) $pass->point_value_paise,
            'payout_paise' => (int) $pass->payout_paise,
            'leftover_paise' => (int) $pass->leftover_paise,
        ];
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
     *     by_rank: array<int, array{qualifiers: int, held: int, aogo_grants: int, pool_paise: int, total_points: int|null, point_value_paise: int|null, gross_total: int, qualified_after_freeze: int}>,
     *     passes: array<int, array{pool_paise: int, total_points: int, raw_point_value_paise: int, point_value_cap_paise: int, point_value_paise: int, payout_paise: int, leftover_paise: int}>
     * }
     */
    private function creditFromFrozenPools(Carbon $monthStartCarbon, string $monthStart, Collection $pools): array
    {
        /** @var Collection<int, Collection<int, RankBonusResult>> $rowsByRank */
        $rowsByRank = RankBonusResult::query()
            ->where('month_start', $monthStart)
            ->get()
            ->groupBy(fn (RankBonusResult $row): int => (int) $row->rank_number);

        // Every achiever row below syncs its Lifetime Award tranches; a rank
        // without tranche rows stops the run here, before any write. A stray
        // pending row is skipped by the loop, so its rank is not asked for.
        $this->assertAwardTranchesSeeded(
            $rowsByRank
                ->filter(fn (Collection $rows, int $rank): bool => $rows->contains(
                    fn (RankBonusResult $row): bool => $row->aogo_points === null
                        && in_array($row->status, self::AWARD_SYNC_STATUSES, true)
                        && ! $this->isStrayPendingRow($row, $pools[$rank] ?? null),
                ))
                ->keys()
                ->map(fn ($rank): int => (int) $rank)
                ->all(),
        );

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

        DB::transaction(function () use ($monthStartCarbon, $monthStart, $pools, $rowsByRank, $grants, $late, &$credited, &$byRank): void {
            foreach (self::RANKS as $rank) {
                $pool = $pools[$rank] ?? null;

                if ($pool === null) {
                    continue;
                }

                /** @var Collection<int, RankBonusResult> $rows */
                $rows = $rowsByRank->get($rank, collect());

                $byRank[$rank] = [
                    'qualifiers' => (int) $pool->payable_count,
                    'held' => $rows->where('status', RankBonusResult::STATUS_REQUALIFICATION_HELD)->count(),
                    'aogo_grants' => $rows->whereNotNull('aogo_points')->count(),
                    'pool_paise' => (int) $pool->pool_paise,
                    'total_points' => $pool->total_points,
                    'point_value_paise' => $pool->point_value_paise,
                    'gross_total' => 0,
                    'qualified_after_freeze' => count($late[$rank] ?? []),
                ];

                foreach ($rows as $row) {
                    if (! in_array($row->status, self::AWARD_SYNC_STATUSES, true)) {
                        continue;
                    }

                    // A stray legacy row (reportStrayPendingRows) is never paid,
                    // and neither syncs an award: it is not on the frozen roster.
                    // The freeze reports the strays it saw once; one written
                    // after it (a hand edit, or a kept premature freeze that
                    // never re-freezes) leaves this trace on every run.
                    if ($this->isStrayPendingRow($row, $pool)) {
                        Log::warning('rank.credit.stray_pending_row_skipped', [
                            'month_start' => $monthStart,
                            'result_id' => (int) $row->id,
                            'distributor_id' => (int) $row->distributor_id,
                            'rank_number' => (int) $row->rank_number,
                            'gross_paise' => (int) $row->gross_paise,
                        ]);

                        continue;
                    }

                    if ($row->status === RankBonusResult::STATUS_PENDING && $row->gross_paise > 0) {
                        $this->creditRosterRow($row, $monthStartCarbon, $monthStart, $grants);

                        $byRank[$rank]['gross_total'] += (int) $row->gross_paise;
                        $credited++;
                    }

                    // AO-GO grantees hold no rank this month — the lifetime
                    // award belongs to the achievers only. Credited rows sync
                    // too, so a re-run prunes a tranche a rebuild unearned.
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
            'passes' => $this->frozenPassSummaries($monthStart),
        ];
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
     * Fail-safe principle 1: a rank whose achievers would sync a Lifetime Award
     * but which has no lifetime_award_tranches rows stops the engine — a
     * missing tranche must never silently award nothing.
     *
     * @param  array<int>  $ranks
     *
     * @throws \RuntimeException
     */
    private function assertAwardTranchesSeeded(array $ranks): void
    {
        foreach ($ranks as $rank) {
            if ($this->plan->lifetimeAwardTranches($rank) === []) {
                throw new \RuntimeException("lifetime_award_tranches has no rows for rank {$rank}");
            }
        }
    }

    /**
     * Keep the distributor's Lifetime Award tranches in step with the months
     * they actually made the rank's payable roster for (client 2026-10-09):
     * one milestone per earned tranche — A on the 1st qualification, B on the
     * 2nd, C on the 3rd — each carrying its tranche amount. A tranche's
     * triggered month is the run in which the count first reached it.
     *
     * The count is RECOMPUTED from the roster rows, never incremented: the old
     * increment fired on every run for any distributor whose row never reached
     * `credited` — which is every payable distributor in a month where
     * Money::floorRupee truncated the gross to 0 — so three re-runs of one
     * month counted three qualifications. Recomputing also survives
     * {@see replacePrematureFreeze()} discarding and rewriting a month's rows.
     *
     * A pending tranche the count no longer reaches (a rebuild removed the
     * qualification that earned it) is deleted with an audit row (fail-safe
     * principle 7). Delivered and cancelled milestones are never touched.
     *
     * @throws \RuntimeException when the rank has no tranche rows
     */
    private function syncLifetimeAward(int $distributorId, int $rank, string $monthStart): void
    {
        $tranches = $this->plan->lifetimeAwardTranches($rank);

        if ($tranches === []) {
            throw new \RuntimeException("lifetime_award_tranches has no rows for rank {$rank}");
        }

        $qualificationCount = max(1, RankBonusResult::query()
            ->where('distributor_id', $distributorId)
            ->where('rank_number', $rank)
            ->whereIn('status', [
                RankBonusResult::STATUS_PENDING,
                RankBonusResult::STATUS_CREDITED,
                RankBonusResult::STATUS_REVERSED,
            ])
            ->distinct()
            ->count('month_start'));

        foreach ($tranches as $t) {
            if ($qualificationCount < $t['tranche']) {
                break;
            }

            $milestone = LifetimeAwardMilestone::firstOrCreate(
                ['distributor_id' => $distributorId, 'rank_number' => $rank, 'tranche' => $t['tranche']],
                [
                    'triggered_month' => $monthStart,
                    'qualification_count' => $qualificationCount,
                    'amount_paise' => $t['amount_paise'],
                    'award_description' => sprintf('%s — tranche %s, merchandise per plan', $this->plan->rankName($rank), chr(64 + $t['tranche'])),
                    'status' => LifetimeAwardMilestone::STATUS_PENDING,
                ],
            );

            // Only a pending tranche tracks the count (D4).
            if ($milestone->status === LifetimeAwardMilestone::STATUS_PENDING) {
                $milestone->update(['qualification_count' => $qualificationCount]);
            }
        }

        $orphans = LifetimeAwardMilestone::query()
            ->where('distributor_id', $distributorId)
            ->where('rank_number', $rank)
            ->where('status', LifetimeAwardMilestone::STATUS_PENDING)
            ->where('tranche', '>', $qualificationCount)
            ->get(['id', 'tranche', 'amount_paise', 'triggered_month']);

        if ($orphans->isNotEmpty()) {
            AuditLog::create([
                'action' => 'awards.tranche.unearned_pending_removed',
                'subject_type' => 'distributor',
                'subject_id' => $distributorId,
                'details' => [
                    'rank' => $rank,
                    'qualification_count' => $qualificationCount,
                    'removed' => $orphans->map(fn (LifetimeAwardMilestone $m): array => [
                        'id' => $m->id,
                        'tranche' => $m->tranche,
                        'amount_paise' => $m->amount_paise,
                        'triggered_month' => $m->triggered_month->toDateString(),
                    ])->all(),
                ],
            ]);

            LifetimeAwardMilestone::whereIn('id', $orphans->pluck('id'))->delete();
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
