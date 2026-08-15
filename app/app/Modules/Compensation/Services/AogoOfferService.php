<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\RankAogoGrant;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Services\DTOs\AogoCondition;
use App\Modules\Compensation\Services\DTOs\AogoRuleCheck;
use App\Modules\Compensation\Services\DTOs\AogoStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * AO-GO ("Achieve Once – Get Once") offer — KP 2026-08-05, replaces the
 * retired "1+2 rule" carry-forward.
 *
 * A distributor who genuinely achieved a rank at least once but holds no rank
 * this month earns `comp.rank.aogo_points` (default 5) in the Rank-1 pool,
 * subject to (all confirmed 2026-08-05):
 *  1. lifetime uses < `comp.rank.aogo_lifetime_max` (default 3);
 *  2. never in consecutive months;
 *  3. grants #2/#3 require a rank re-achieved in a month after the previous
 *     grant;
 *  4. the month's requalification conditions (Rank-1 repurchase BV + wallet
 *     cleared) — a failed month consumes NOTHING; the offer stays available
 *     for a later eligible month.
 *
 * Rules 1–3 are decided in exactly one place, {@see checkRules()}; the source
 * rows are selected through the `achieved()` / `rankedInMonth()` / `live()`
 * model scopes. The monthly run and the read-only checklists therefore share
 * both the predicates and the decision — what is paid and what is displayed
 * cannot drift.
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
        $prevMonthStart = $month->copy()->startOfMonth()->subMonth()->toDateString();

        // Everyone who genuinely achieved a rank before this month, with their
        // latest achievement month and highest rank ever held (audit column).
        $history = RankQualification::query()
            ->achieved()
            ->where('month_start', '<', $monthStart)
            ->toBase()
            ->groupBy('distributor_id')
            ->selectRaw('distributor_id, MAX(month_start) as last_qual_month, MAX(rank_number) as highest_rank')
            ->get();

        if ($history->isNotEmpty()) {
            $candidateIds = $history->pluck('distributor_id')->map(fn ($id) => (int) $id)->all();

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

            $eligible = [];
            foreach ($history as $row) {
                $distributorId = (int) $row->distributor_id;
                $agg = $grantAgg->get($distributorId);
                $used = $agg !== null ? (int) $agg->used : 0;

                $check = $this->checkRules(
                    lastQualMonth: (string) $row->last_qual_month,
                    rankedThisMonth: $rankedNow->has($distributorId),
                    used: $used,
                    lastGrantMonth: $agg?->last_grant_month !== null ? (string) $agg->last_grant_month : null,
                    monthStart: $monthStart,
                    prevMonthStart: $prevMonthStart,
                );

                if ($check->alreadyGrantedThisMonth || ! $check->structurallyEligible()) {
                    continue;
                }

                $eligible[$distributorId] = [
                    'grant_number' => $used + 1,
                    'previous_rank_number' => (int) $row->highest_rank,
                ];
            }

            // Requalification conditions (Rank-1 repurchase BV + wallet
            // cleared). Failing here creates no grant and consumes no use.
            if ($eligible !== []) {
                $passMap = $this->gate->passMap(array_keys($eligible), $month, rank: 1);

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
        $prevMonthStart = $month->copy()->startOfMonth()->subMonth()->toDateString();

        $lastQual = RankQualification::query()
            ->achieved()
            ->where('distributor_id', $distributorId)
            ->where('month_start', '<', $monthStart)
            ->max('month_start');
        $lastQualMonth = $lastQual !== null ? (string) $lastQual : null;

        // An achievement in this month or later still counts as "ever ranked"
        // for the purpose of showing the offer at all.
        $everAchieved = $lastQualMonth !== null || RankQualification::query()
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
            lastQualMonth: $lastQualMonth,
            rankedThisMonth: $rankedNow,
            used: $used,
            lastGrantMonth: $grants?->last_grant_month !== null ? (string) $grants->last_grant_month : null,
            monthStart: $monthStart,
            prevMonthStart: $prevMonthStart,
        );

        $thisMonthGrant = RankAogoGrant::query()
            ->live()
            ->where('distributor_id', $distributorId)
            ->where('month_start', $monthStart)
            ->first();

        $conditions = [
            new AogoCondition(
                label: 'A rank achieved in an earlier month',
                met: $check->achievedBefore,
                note: 'The offer is only for distributors who have genuinely achieved a rank at least once before this month.',
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

        // Rules 2 and 3 only bite once the offer has been used at least once.
        if ($used > 0) {
            $conditions[] = new AogoCondition(
                label: 'Not used last month',
                met: $check->notUsedLastMonth,
                note: 'The offer can never be used in two consecutive months.',
            );
            $conditions[] = new AogoCondition(
                label: 'A rank re-achieved since your last use',
                met: $check->reAchievedSinceLastUse,
                note: 'After using the offer you must achieve a rank again in a later month before it can be used the next time.',
            );
        }

        $conditions[] = new AogoCondition(
            label: "This month's requalification conditions",
            met: $this->gate->passes($distributorId, $month, rank: 1),
            note: 'The Rank-1 repurchase BV for the month completed and your repurchase wallet cleared. A month that misses this uses nothing — the offer stays available for a later month.',
        );

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
     * The standing AO-GO rules, evaluated from the four facts they depend on.
     * The only place these comparisons exist — grantForMonth() pays on them and
     * eligibilityFor() labels them.
     *
     * @param  string|null  $lastQualMonth  latest genuine achievement BEFORE this month
     * @param  string|null  $lastGrantMonth  most recent live grant, this month included
     */
    private function checkRules(
        ?string $lastQualMonth,
        bool $rankedThisMonth,
        int $used,
        ?string $lastGrantMonth,
        string $monthStart,
        string $prevMonthStart,
    ): AogoRuleCheck {
        return new AogoRuleCheck(
            achievedBefore: $lastQualMonth !== null,
            notRankedThisMonth: ! $rankedThisMonth,
            usesRemaining: $used < $this->plan->aogoLifetimeMax(),
            notUsedLastMonth: $lastGrantMonth !== $prevMonthStart,
            // First use has nothing to re-achieve against; later uses need an
            // achievement in a month strictly after the previous grant.
            reAchievedSinceLastUse: $lastGrantMonth === null
                || ($lastQualMonth !== null && $lastQualMonth > $lastGrantMonth),
            alreadyGrantedThisMonth: $lastGrantMonth === $monthStart,
        );
    }
}
