<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\RankAogoGrant;
use App\Modules\Compensation\Models\RankQualification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

        // Ever genuinely achieved a rank before this month (qualified,
        // non-carry-forward), with their latest achievement month and highest
        // rank ever held (audit column).
        $history = DB::table('rank_qualifications')
            ->where('status', RankQualification::STATUS_QUALIFIED)
            ->where('is_carry_forward', false)
            ->where('month_start', '<', $monthStart)
            ->groupBy('distributor_id')
            ->selectRaw('distributor_id, MAX(month_start) as last_qual_month, MAX(rank_number) as highest_rank')
            ->get();

        if ($history->isNotEmpty()) {
            $candidateIds = $history->pluck('distributor_id')->map(fn ($id) => (int) $id)->all();

            // Degraded = no qualified rank this month (carry-forward included —
            // a paid carry row still means "ranked").
            $rankedNow = RankQualification::query()
                ->where('month_start', $monthStart)
                ->where('status', RankQualification::STATUS_QUALIFIED)
                ->whereIn('distributor_id', $candidateIds)
                ->distinct()
                ->pluck('distributor_id')
                ->map(fn ($id) => (int) $id)
                ->flip();

            // Prior live grants: lifetime count + most recent month, plus any
            // grant already recorded for this month (idempotent rerun).
            $grantAgg = DB::table('rank_aogo_grants')
                ->whereIn('distributor_id', $candidateIds)
                ->where('status', '!=', RankAogoGrant::STATUS_VOIDED)
                ->groupBy('distributor_id')
                ->selectRaw('distributor_id, COUNT(*) as used, MAX(month_start) as last_grant_month')
                ->get()
                ->keyBy(fn ($row) => (int) $row->distributor_id);

            $lifetimeMax = $this->plan->aogoLifetimeMax();
            $points = $this->plan->aogoPointsPerGrant();

            $eligible = [];
            foreach ($history as $row) {
                $distributorId = (int) $row->distributor_id;
                if ($rankedNow->has($distributorId)) {
                    continue;
                }

                $agg = $grantAgg->get($distributorId);
                $used = $agg !== null ? (int) $agg->used : 0;
                $lastGrantMonth = $agg !== null ? (string) $agg->last_grant_month : null;

                if ($used >= $lifetimeMax) {
                    continue;
                }
                if ($lastGrantMonth === $monthStart) {
                    continue; // already granted this month (rerun)
                }
                if ($lastGrantMonth === $prevMonthStart) {
                    continue; // never two months running
                }
                if ($lastGrantMonth !== null && (string) $row->last_qual_month <= $lastGrantMonth) {
                    continue; // must re-achieve a rank after the previous grant
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
            ->where('month_start', $monthStart)
            ->where('status', '!=', RankAogoGrant::STATUS_VOIDED)
            ->get();
    }
}
