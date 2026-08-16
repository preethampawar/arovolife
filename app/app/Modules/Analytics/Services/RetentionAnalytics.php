<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Services\TeamStatsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who keeps buying, and who is at the top.
 *
 * Retention is measured on **purchases**, not logins: a direct-selling business
 * lives on repeat orders, and a distributor who signs in monthly and never buys
 * is not retained in any sense that matters.
 *
 * Team sizes come from `TeamStatsService` rather than a second closure-table
 * walk. There is one place in this codebase that counts a downline, and this is
 * not it — two implementations would eventually disagree, and the one on the
 * analytics page would be the one nobody noticed was wrong.
 */
final class RetentionAnalytics
{
    public function __construct(private readonly TeamStatsService $teamStats) {}

    /**
     * Buyers per month, and how many of the previous month's buyers came back.
     *
     * @return array<int, array{month: string, buyers: int, returning: int, retention_pct: ?float}>
     */
    public function monthlyRetention(int $months, ?Carbon $endingAt = null): array
    {
        $cursor = ($endingAt ?? Carbon::now())->copy()->startOfMonth();
        $rows = [];

        // Oldest first, so each row can look back at the one before it.
        for ($back = $months - 1; $back >= 0; $back--) {
            $month = $cursor->copy()->subMonths($back);
            $buyerIds = $this->buyerIdsForMonth($month);
            $previousIds = $this->buyerIdsForMonth($month->copy()->subMonth());

            $returning = count(array_intersect($buyerIds, $previousIds));

            $rows[] = [
                'month' => $month->format('Y-m'),
                'buyers' => count($buyerIds),
                'returning' => $returning,
                // Retention is a share of the PREVIOUS month's buyers — how
                // many stayed — not of this month's, which would count new
                // buyers as retained.
                'retention_pct' => $previousIds !== []
                    ? round($returning / count($previousIds) * 100, 1)
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * Distributors who bought in a month, by id.
     *
     * @return array<int, int>
     */
    public function buyerIdsForMonth(Carbon $month): array
    {
        return DB::table('orders')
            ->whereNotNull('attributed_distributor_id')
            ->whereNotNull('paid_at')
            ->whereNotIn('status', ['cancelled', 'refunded', 'draft'])
            ->whereBetween('paid_at', [
                $month->copy()->startOfMonth()->startOfDay(),
                $month->copy()->endOfMonth()->endOfDay(),
            ])
            ->distinct()
            ->pluck('attributed_distributor_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Top distributors by the BV attributed to them in a window.
     *
     * Historical fact about work already done. Deliberately no rank, no
     * earnings and no projection — an admin league table is one screenshot
     * away from becoming a recruitment slide (hard rule 3, DSR Rule 5(1)(d)).
     *
     * @return array<int, array{distributor_id: int, adn: string, name: ?string, bv_paise: int, orders: int, team_size: int}>
     */
    public function topByVolume(Carbon $from, Carbon $to, int $limit = 10): array
    {
        $rows = DB::table('bv_ledger_entries as b')
            ->join('distributors as d', 'd.id', '=', 'b.distributor_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->whereBetween('b.effective_at', [$from, $to])
            ->groupBy('b.distributor_id', 'd.adn', 'u.full_name')
            ->orderByDesc(DB::raw('SUM(b.bv_paise)'))
            ->limit($limit)
            ->get(['b.distributor_id', 'd.adn', 'u.full_name', DB::raw('SUM(b.bv_paise) as bv_paise')]);

        // One lookup for the whole page rather than one per row: the team
        // count needs a model, the rest does not.
        $distributors = Distributor::query()
            ->whereIn('id', $rows->pluck('distributor_id')->all())
            ->get()
            ->keyBy('id');

        $out = [];

        foreach ($rows as $row) {
            $distributor = $distributors->get((int) $row->distributor_id);

            if (! $distributor instanceof Distributor) {
                continue;
            }

            $out[] = [
                'distributor_id' => (int) $row->distributor_id,
                'adn' => (string) $row->adn,
                'name' => $row->full_name === null ? null : (string) $row->full_name,
                'bv_paise' => (int) $row->bv_paise,
                'orders' => (int) DB::table('orders')
                    ->where('attributed_distributor_id', $row->distributor_id)
                    ->whereBetween('paid_at', [$from, $to])
                    ->whereNotIn('status', ['cancelled', 'refunded', 'draft'])
                    ->count(),
                // One source of truth for downline counting. 'total' is the
                // whole Genos below them, both groups, excluding themselves.
                'team_size' => $this->teamStats->scopedCount($distributor, 'total'),
            ];
        }

        return $out;
    }

    /**
     * The shape of the active base: how many distributors exist, how many
     * bought recently, and how many have gone quiet.
     *
     * @return array{total: int, active_status: int, bought_this_month: int, bought_last_90_days: int, never_bought: int}
     */
    public function baseShape(?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        $everBought = DB::table('orders')
            ->whereNotNull('attributed_distributor_id')
            ->whereNotNull('paid_at')
            ->whereNotIn('status', ['cancelled', 'refunded', 'draft'])
            ->distinct()
            ->count('attributed_distributor_id');

        return [
            'total' => (int) DB::table('distributors')->count(),
            'active_status' => (int) DB::table('distributors')->where('status', 'active')->count(),
            'bought_this_month' => count($this->buyerIdsForMonth($asOf)),
            'bought_last_90_days' => (int) DB::table('orders')
                ->whereNotNull('attributed_distributor_id')
                ->whereNotNull('paid_at')
                ->whereNotIn('status', ['cancelled', 'refunded', 'draft'])
                ->where('paid_at', '>=', $asOf->copy()->subDays(90))
                ->distinct()
                ->count('attributed_distributor_id'),
            'never_bought' => max(0, (int) DB::table('distributors')->count() - (int) $everBought),
        ];
    }
}
