<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\RankAogoGrant;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Services\DTOs\AogoCondition;
use App\Modules\Compensation\Services\DTOs\AogoRuleCheck;
use App\Modules\Compensation\Services\DTOs\AogoStatus;
use App\Modules\Shared\Support\IndianNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * AO-GO ("Achieve Once – Get Once") offer — KP 2026-08-05, replaces the
 * retired "1+2 rule" carry-forward; window rule rewritten by the user's
 * decision of 2026-10-17.
 *
 * AO-GO can only be granted in the month IMMEDIATELY after a month in which
 * the distributor genuinely achieved a rank (call that month M). In M+1 the
 * distributor earns `comp.rank.aogo_points` in pass 1 of the Rank Bonus pool
 * when all of these hold:
 *  1. a rank achieved in M (a carry-forward row is not an achievement);
 *  2. no rank held in M+1;
 *  3. lifetime uses < `comp.rank.aogo_lifetime_max` (default 3);
 *  4. own purchase BV in M+1 ≥ the repurchase BV of the rank achieved in M
 *     (rank_tiers.repurchase_bv_paise — Rank 3 → 1,200 BV, not always Rank 1's
 *     1,000).
 *
 * A miss is final: if the BV falls short in M+1 there is no grant, and M+2 is
 * never considered — the offer comes back only after a new rank achievement.
 * There is no repurchase-wallet condition. The old "never in consecutive
 * months" and "rank re-achieved between uses" rules now hold by construction
 * (the month before a grant always carries a rank achievement), so they are no
 * longer checked.
 *
 * Rules 1–3 are decided in exactly one place, {@see checkRules()}, and rule 4
 * by {@see RankRequalificationGateService::passMapByRank()}. The monthly run
 * and the read-only checklist share both, so what is paid and what is
 * displayed cannot drift.
 */
final class AogoOfferService
{
    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
        private readonly RankRequalificationGateService $gate,
    ) {}

    /**
     * Detect eligible distributors and create their AO-GO grants for the
     * month. Idempotent — reruns return the already-created grants.
     *
     * @return Collection<int, RankAogoGrant> all live (non-voided) grants for the month
     */
    public function grantForMonth(Carbon $month): Collection
    {
        $monthStart = $month->copy()->startOfMonth()->toDateString();

        $lastMonthRanks = $this->ranksAchievedLastMonth($month);

        if ($lastMonthRanks !== []) {
            $candidateIds = array_keys($lastMonthRanks);

            $rankedNow = RankQualification::query()
                ->rankedInMonth($monthStart)
                ->whereIn('distributor_id', $candidateIds)
                ->distinct()
                ->pluck('distributor_id')
                ->map(fn ($id) => (int) $id)
                ->flip();

            // Prior live grants: lifetime count + most recent month, which also
            // covers a grant already recorded for this month (rerun).
            $grantAgg = RankAogoGrant::query()
                ->live()
                ->toBase()
                ->whereIn('distributor_id', $candidateIds)
                ->groupBy('distributor_id')
                ->selectRaw('distributor_id, COUNT(*) as used, MAX(month_start) as last_grant_month')
                ->get()
                ->keyBy(fn ($row) => (int) $row->distributor_id);

            $points = $this->plan->aogoPointsPerGrant();

            /** @var array<int, array{grant_number: int, previous_rank_number: int}> $eligible */
            $eligible = [];
            foreach ($lastMonthRanks as $distributorId => $rank) {
                $agg = $grantAgg->get($distributorId);
                $used = $agg !== null ? (int) $agg->used : 0;

                $check = $this->checkRules(
                    rankLastMonth: $rank,
                    rankedThisMonth: $rankedNow->has($distributorId),
                    used: $used,
                    lastGrantMonth: $agg?->last_grant_month !== null ? (string) $agg->last_grant_month : null,
                    monthStart: $monthStart,
                );

                if ($check->alreadyGrantedThisMonth || ! $check->structurallyEligible()) {
                    continue;
                }

                $eligible[$distributorId] = [
                    'grant_number' => $used + 1,
                    'previous_rank_number' => $rank,
                ];
            }

            // Own purchase BV of the rank achieved last month. A miss creates no
            // grant, and next month is never considered (no achievement then).
            if ($eligible !== []) {
                $passMap = $this->gate->passMapByRank(
                    array_map(fn (array $meta): int => $meta['previous_rank_number'], $eligible),
                    $month,
                );

                foreach ($eligible as $distributorId => $meta) {
                    if (! ($passMap[$distributorId] ?? false)) {
                        continue;
                    }

                    RankAogoGrant::create([
                        'distributor_id' => $distributorId,
                        'month_start' => $monthStart,
                        'grant_number' => $meta['grant_number'],
                        'points' => $points,
                        'previous_rank_number' => $meta['previous_rank_number'],
                        'status' => RankAogoGrant::STATUS_GRANTED,
                    ]);
                }
            }
        }

        return RankAogoGrant::query()
            ->live()
            ->where('month_start', $monthStart)
            ->get();
    }

    /**
     * One distributor's own standing against the offer for a month — the same
     * rules {@see grantForMonth()} applies, read-only and per-condition so the
     * distributor (and support) can see exactly which one is outstanding.
     *
     * Measured from what is recorded today: the month's rank qualification run
     * may not have happened yet, so "no rank held this month" can still flip
     * before the monthly run decides the grant.
     */
    public function eligibilityFor(int $distributorId, Carbon $month): AogoStatus
    {
        $monthStart = $month->copy()->startOfMonth()->toDateString();

        $rankLastMonth = $this->ranksAchievedLastMonth($month, $distributorId)[$distributorId] ?? null;

        $everAchieved = RankQualification::query()
            ->achieved()
            ->where('distributor_id', $distributorId)
            ->exists();

        $rankedNow = RankQualification::query()
            ->rankedInMonth($monthStart)
            ->where('distributor_id', $distributorId)
            ->exists();

        $grants = RankAogoGrant::query()
            ->live()
            ->toBase()
            ->where('distributor_id', $distributorId)
            ->selectRaw('COUNT(*) as used, MAX(month_start) as last_grant_month')
            ->first();

        $used = (int) ($grants->used ?? 0);
        $lifetimeMax = $this->plan->aogoLifetimeMax();

        $check = $this->checkRules(
            rankLastMonth: $rankLastMonth,
            rankedThisMonth: $rankedNow,
            used: $used,
            lastGrantMonth: $grants?->last_grant_month !== null ? (string) $grants->last_grant_month : null,
            monthStart: $monthStart,
        );

        $thisMonthGrant = RankAogoGrant::query()
            ->live()
            ->where('distributor_id', $distributorId)
            ->where('month_start', $monthStart)
            ->first();

        $rankNames = $this->plan->rankNames();

        $conditions = [
            new AogoCondition(
                label: $rankLastMonth !== null
                    ? 'A rank achieved last month ('.($rankNames[$rankLastMonth] ?? 'Rank '.$rankLastMonth).')'
                    : 'A rank achieved last month',
                met: $check->achievedLastMonth,
                note: 'The offer applies only in the month right after a month in which you achieved a rank. If that month passes without the offer, it is not carried to a later month.',
            ),
            new AogoCondition(
                label: 'No rank held this month',
                met: $check->notRankedThisMonth,
                note: 'The offer applies only in a month where you hold no rank. Ranks are checked once a month, so this is decided by the monthly run.',
            ),
            new AogoCondition(
                label: 'Lifetime uses remaining',
                met: $check->usesRemaining,
                note: 'You have used the offer '.$used.' of '.$lifetimeMax.' times.',
            ),
        ];

        if ($rankLastMonth !== null) {
            $requiredBvPaise = $this->plan->rankRepurchaseBvPaise($rankLastMonth);
            $bvSoFarPaise = $this->gate->monthlyPersonalBvSoFarPaise($distributorId, $month);

            $conditions[] = new AogoCondition(
                label: 'Your own purchases this month: at least '.IndianNumber::format(intdiv($requiredBvPaise, 100)).' BV (as things stand today)',
                met: $bvSoFarPaise >= $requiredBvPaise,
                note: 'The monthly purchase BV of the rank you achieved last month. Your purchases so far this month: '
                    .IndianNumber::format(intdiv($bvSoFarPaise, 100)).' BV.',
            );
        }

        return new AogoStatus(
            everAchievedRank: $everAchieved,
            usesUsed: $used,
            usesMax: $lifetimeMax,
            pointsPerGrant: $this->plan->aogoPointsPerGrant(),
            conditions: $conditions,
            conditionsMet: array_reduce(
                $conditions,
                static fn (bool $carry, AogoCondition $c): bool => $carry && $c->met,
                true,
            ),
            grantedPoints: $thisMonthGrant?->points,
            grantedStatus: $thisMonthGrant?->status,
        );
    }

    /**
     * The highest rank each distributor genuinely achieved in the month before
     * $month (carry-forward rows excluded) — the AO-GO window and the rank whose
     * repurchase BV the offer asks for.
     *
     * @return array<int, int> distributor id → rank number
     */
    private function ranksAchievedLastMonth(Carbon $month, ?int $distributorId = null): array
    {
        $prevMonthStart = $month->copy()->startOfMonth()->subMonth()->toDateString();

        return RankQualification::query()
            ->achieved()
            ->where('month_start', $prevMonthStart)
            ->when($distributorId !== null, fn ($q) => $q->where('distributor_id', $distributorId))
            ->toBase()
            ->groupBy('distributor_id')
            ->selectRaw('distributor_id, MAX(rank_number) as rank_number')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->distributor_id => (int) $row->rank_number])
            ->all();
    }

    /**
     * The standing AO-GO rules, evaluated from the facts they depend on. The
     * only place these comparisons exist — grantForMonth() pays on them and
     * eligibilityFor() labels them.
     *
     * @param  int|null  $rankLastMonth  rank genuinely achieved in the month before, if any
     * @param  string|null  $lastGrantMonth  most recent live grant, this month included
     */
    private function checkRules(
        ?int $rankLastMonth,
        bool $rankedThisMonth,
        int $used,
        ?string $lastGrantMonth,
        string $monthStart,
    ): AogoRuleCheck {
        return new AogoRuleCheck(
            achievedLastMonth: $rankLastMonth !== null,
            notRankedThisMonth: ! $rankedThisMonth,
            usesRemaining: $used < $this->plan->aogoLifetimeMax(),
            alreadyGrantedThisMonth: $lastGrantMonth === $monthStart,
        );
    }
}
