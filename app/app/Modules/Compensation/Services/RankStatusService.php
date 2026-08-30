<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Services\DTOs\RankRequirement;
use App\Modules\Compensation\Services\DTOs\RankStatus;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Services\TeamStatsService;
use App\Modules\Shared\Support\IndianNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds a distributor's own rank standing for the distributor-facing surfaces
 * (My Income → Rank Bonus, the dashboard ID card).
 *
 * Read-only mirror of {@see RankQualificationService}'s measurement rules — the
 * conditions shown here must be measured exactly the way the monthly
 * qualification run will measure them, never re-invented:
 *  - Ranks 1-2: the calendar month's Left/Right Genos BV, with up to the
 *    rank's cap of this month's personal purchase BV supplementing the weaker
 *    leg;
 *  - Ranks 3-9: the candidate's own Q-Period count of the prior rank plus that
 *    rank's structural requirement (prior-rank qualifiers per Genos side, this
 *    month);
 *  - every rank: the lifetime personal-BV gate.
 *
 * Nothing here projects a future rank or income: each row is a published plan
 * condition next to the distributor's own current figure (DSR 2021 r.5(1)(d)).
 */
final class RankStatusService
{
    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
        private readonly BvLedgerService $bvLedger,
        private readonly TeamStatsService $teamStats,
        private readonly RankRequalificationGateService $requalificationGate,
    ) {}

    public function forDistributor(Distributor $distributor): RankStatus
    {
        $distributorId = (int) $distributor->id;
        $monthStart = Carbon::today('Asia/Kolkata')->startOfMonth();
        $previousMonthStart = $monthStart->copy()->subMonth();

        $achievementCounts = $this->achievementCounts($distributorId);
        $highestRank = $achievementCounts === [] ? null : max(array_keys($achievementCounts));

        $thisMonthRank = $this->highestRankQualifiedIn($distributorId, $monthStart);
        $previousMonthRank = $this->highestRankQualifiedIn($distributorId, $previousMonthStart);

        // "Current" rank = the rank actually held right now: this month's
        // achievement, or last month's while the current month is still being
        // accumulated. Older achievements survive only as the highest rank.
        $currentRank = $thisMonthRank ?? $previousMonthRank;

        $nextRank = $this->nextRank($highestRank);

        $requalificationConditionsMet = null;
        if ($currentRank !== null && ($achievementCounts[$currentRank] ?? 0) > 1) {
            $requalificationConditionsMet = $this->requalificationGate->passes(
                $distributorId,
                $monthStart,
                $currentRank,
            );
        }

        return new RankStatus(
            currentRank: $currentRank,
            highestRank: $highestRank,
            achievementCounts: $achievementCounts,
            rankNames: $this->plan->rankNames(),
            nextRank: $nextRank,
            nextRequirements: $nextRank === null
                ? []
                : $this->requirementsFor($distributor, $nextRank, $monthStart, $achievementCounts),
            qualifiedThisMonth: $thisMonthRank !== null,
            thisMonthRank: $thisMonthRank,
            requalificationConditionsMet: $requalificationConditionsMet,
        );
    }

    /**
     * The two rank labels the ID-card panel shows, without the next-rank
     * measurement work — three cheap reads, so surfaces that only need the
     * badges never pay for the progress ladder.
     *
     * @return array{current: ?string, highest: ?string}
     */
    public function labelsFor(int $distributorId): array
    {
        // Single source: the batch resolver owns the rule.
        return $this->labelsForMany([$distributorId])[$distributorId];
    }

    /**
     * The ONLY place the two card badges are derived: highest = the top
     * achieved rank ever; current = the top rank achieved this month, else
     * last month, else none. Two grouped queries for any number of
     * distributors — the tree canvas needs every visible card's badges and
     * must never resolve them per node. Every requested id is present in
     * the result; distributors with no achievement map to null/null.
     *
     * @param  int[]  $distributorIds
     * @return array<int, array{current: ?string, highest: ?string}>
     */
    public function labelsForMany(array $distributorIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $distributorIds)));
        $labels = array_fill_keys($ids, ['current' => null, 'highest' => null]);
        if ($ids === []) {
            return $labels;
        }

        $highest = RankQualification::query()->achieved()
            ->whereIn('distributor_id', $ids)
            ->toBase()
            ->groupBy('distributor_id')
            ->selectRaw('distributor_id, MAX(rank_number) as highest_rank')
            ->pluck('highest_rank', 'distributor_id');

        if ($highest->isEmpty()) {
            return $labels;
        }

        $monthStart = Carbon::today('Asia/Kolkata')->startOfMonth();
        $months = [$monthStart->toDateString(), $monthStart->copy()->subMonth()->toDateString()];

        // Per distributor: the top rank held this month, else last month —
        // same precedence as labelsFor(), resolved with one query.
        $current = [];
        $rows = RankQualification::query()->achieved()
            ->whereIn('distributor_id', $ids)
            ->whereIn('month_start', $months)
            ->toBase()
            ->groupBy('distributor_id', 'month_start')
            ->selectRaw('distributor_id, month_start, MAX(rank_number) as top_rank')
            ->get();
        foreach ($rows as $row) {
            $did = (int) $row->distributor_id;
            $isThisMonth = Carbon::parse((string) $row->month_start)->toDateString() === $months[0];
            if ($isThisMonth || ! isset($current[$did])) {
                $current[$did] = (int) $row->top_rank;
            }
        }

        foreach ($highest as $did => $rank) {
            $did = (int) $did;
            $labels[$did] = [
                'current' => isset($current[$did]) ? $this->plan->rankName($current[$did]) : null,
                'highest' => $this->plan->rankName((int) $rank),
            ];
        }

        return $labels;
    }

    /**
     * Lifetime qualified occurrences per rank — the same basis the engine's
     * Q-Period gate counts (Option C: every qualified, non-carry-forward
     * occurrence, whenever it happened).
     *
     * @return array<int, int> rank_number => occurrences, ascending
     */
    private function achievementCounts(int $distributorId): array
    {
        $rows = RankQualification::query()->achieved()
            ->where('distributor_id', $distributorId)
            ->toBase()
            ->groupBy('rank_number')
            ->selectRaw('rank_number, COUNT(*) as occurrences')
            ->orderBy('rank_number')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->rank_number] = (int) $row->occurrences;
        }

        return $counts;
    }

    private function highestRankQualifiedIn(int $distributorId, Carbon $monthStart): ?int
    {
        $rank = RankQualification::query()->achieved()
            ->where('distributor_id', $distributorId)
            ->where('month_start', $monthStart->toDateString())
            ->max('rank_number');

        return $rank !== null ? (int) $rank : null;
    }

    /** The next rank on the ladder, or null once the top active rank is held. */
    private function nextRank(?int $highestRank): ?int
    {
        $candidate = ($highestRank ?? 0) + 1;
        $tier = $this->plan->rankTiers()[$candidate] ?? null;

        return ($tier !== null && $tier['is_active']) ? $candidate : null;
    }

    /**
     * The next rank's published conditions measured against this distributor.
     *
     * @param  array<int, int>  $achievementCounts
     * @return list<RankRequirement>
     */
    private function requirementsFor(
        Distributor $distributor,
        int $rank,
        Carbon $monthStart,
        array $achievementCounts,
    ): array {
        $distributorId = (int) $distributor->id;
        $requirements = [];

        $personalBvRequired = $this->plan->rankPersonalBvRequired($rank);
        if ($personalBvRequired > 0) {
            $requirements[] = new RankRequirement(
                label: 'Personal BV (lifetime)',
                current: $this->bvLedger->totalPersonalBvPaise($distributorId),
                required: $personalBvRequired,
                unit: 'bv',
            );
        }

        $groupBvRequired = $this->plan->rankGroupBvRequired($rank);

        if ($groupBvRequired !== null) {
            [$leftBvPaise, $rightBvPaise] = $this->monthGenosBv($distributorId, $monthStart);

            // Same weaker-leg top-up the qualification run applies: up to the
            // rank's cap of THIS month's personal purchase BV counts toward the
            // weaker leg only. The raw group BV is never mutated.
            $topupCap = $this->plan->rankWeakerLegTopupBvPaise($rank);
            $topup = min($this->monthPersonalBv($distributorId, $monthStart), $topupCap);
            $leftIsWeaker = $leftBvPaise <= $rightBvPaise;
            $note = $topup > 0
                ? 'Includes '.$this->bvLabel($topup).' BV of this month\'s personal purchases, added to the weaker leg (up to '.$this->bvLabel($topupCap).' BV).'
                : null;

            $requirements[] = new RankRequirement(
                label: 'Left Genos BV this month',
                current: $leftBvPaise + ($leftIsWeaker ? $topup : 0),
                required: $groupBvRequired,
                unit: 'bv',
                note: $leftIsWeaker ? $note : null,
            );
            $requirements[] = new RankRequirement(
                label: 'Right Genos BV this month',
                current: $rightBvPaise + ($leftIsWeaker ? 0 : $topup),
                required: $groupBvRequired,
                unit: 'bv',
                note: $leftIsWeaker ? null : $note,
            );

            return $requirements;
        }

        // Ranks 3-9 qualify structurally, off the rank below.
        $lowerRank = $rank - 1;
        $lowerRankName = $this->plan->rankName($lowerRank);

        $requirements[] = new RankRequirement(
            label: $lowerRankName.' achieved',
            current: $achievementCounts[$lowerRank] ?? 0,
            required: $this->plan->rankPypRequired($lowerRank),
            unit: 'count',
            note: 'Times you have achieved '.$lowerRankName.' — counted over your lifetime.',
        );

        $perSide = $this->plan->rankStructuralQualifiersPerSide($rank);
        [$leftQualifiers, $rightQualifiers] = $this->legQualifierCounts($distributor, $lowerRank, $monthStart);

        $requirements[] = new RankRequirement(
            label: $lowerRankName.' partners — Left Genos',
            current: $leftQualifiers,
            required: $perSide,
            unit: 'people',
            note: 'Members of your Left group who achieved '.$lowerRankName.' this month.',
        );
        $requirements[] = new RankRequirement(
            label: $lowerRankName.' partners — Right Genos',
            current: $rightQualifiers,
            required: $perSide,
            unit: 'people',
            note: 'Members of your Right group who achieved '.$lowerRankName.' this month.',
        );

        return $requirements;
    }

    /**
     * The calendar month's Left/Right Genos BV so far (paise) — the same
     * group_bv_daily sum the qualification run reads.
     *
     * @return array{0: int, 1: int}
     */
    private function monthGenosBv(int $distributorId, Carbon $monthStart): array
    {
        $row = DB::table('group_bv_daily')
            ->where('distributor_id', $distributorId)
            ->whereBetween('date', [
                $monthStart->toDateString(),
                $monthStart->copy()->endOfMonth()->toDateString(),
            ])
            ->selectRaw('COALESCE(SUM(left_bv_paise), 0) as left_bv, COALESCE(SUM(right_bv_paise), 0) as right_bv')
            ->first();

        return [(int) ($row->left_bv ?? 0), (int) ($row->right_bv ?? 0)];
    }

    /** This month's personal purchase BV (paise) — feeds the weaker-leg top-up. */
    private function monthPersonalBv(int $distributorId, Carbon $monthStart): int
    {
        return (int) DB::table('bv_ledger_entries')
            ->where('distributor_id', $distributorId)
            ->where('type', 'accrual')
            ->whereBetween('effective_at', [
                $monthStart->copy()->startOfDay(),
                $monthStart->copy()->endOfMonth()->endOfDay(),
            ])
            ->sum('bv_paise');
    }

    /**
     * How many members of each Genos group achieved the given rank this month.
     * Leg membership comes from TeamStatsService — the single source of truth
     * for every downline question.
     *
     * @return array{0: int, 1: int}
     */
    private function legQualifierCounts(Distributor $distributor, int $rank, Carbon $monthStart): array
    {
        $counts = [];

        foreach (['left', 'right'] as $side) {
            $ids = $this->teamStats->scopedIds($distributor, $side);

            $counts[] = $ids === [] ? 0 : (int) RankQualification::query()->achieved()
                ->whereIn('distributor_id', $ids)
                ->where('rank_number', $rank)
                ->where('month_start', $monthStart->toDateString())
                ->distinct()
                ->count('distributor_id');
        }

        return [$counts[0], $counts[1]];
    }

    private function bvLabel(int $paise): string
    {
        return IndianNumber::format($paise / 100, 0);
    }
}
