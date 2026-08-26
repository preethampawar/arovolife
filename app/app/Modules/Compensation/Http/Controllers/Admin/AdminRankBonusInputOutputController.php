<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\RankAogoGrant;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Rank Bonus "Input & Output Per Month" calculation report — the monthly
 * sibling of the per-day GSB/MSB Input & Output reports, per rank instead of
 * per slab.
 *
 * Unlike GBB there is no frozen monthly pool table: the engine snapshots the
 * month's economics (turnover, per-rank pool, qualifier count, points, point
 * value) onto every rank_bonus_results row. Each month block is therefore
 * reconstructed with MAX()/SUM() aggregates over those rows — MAX() is safe
 * because every row of a (month, rank) group carries the same snapshot, and
 * requalification-held rows leave their point columns null. AO-GO grantees get
 * a credited Rank-1 result row too (aogo_points set, rap_points null); those
 * rows are excluded from the achiever income sums so the AO-GO line — sourced
 * from rank_aogo_grants — is never counted twice.
 *
 * Ranks that had no qualifiers write no rows at all, so their pool went
 * unspent invisibly; the report renders them with the pool ESTIMATED from the
 * month's stored turnover and the CURRENT envelope/pool-% settings, clearly
 * asterisked — those two parameters are live settings, not frozen snapshots.
 * The per-rank leftover is likewise derived (pool − Σ gross), because the
 * engine never stores its flooring remainder.
 */
final class AdminRankBonusInputOutputController extends Controller
{
    private const MONTHS_PER_PAGE = 12;

    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(RankBonusFeature::class), 404);

        [$month, $from, $to] = $this->filters($request);

        $months = $this->monthQuery($month, $from, $to)
            ->paginate(self::MONTHS_PER_PAGE)
            ->withQueryString();

        $monthStarts = array_values(array_map(
            fn (\stdClass $row) => Carbon::parse($row->month_start)->toDateString(),
            $months->items(),
        ));

        return view('admin.compensation.rb-input-output.index', [
            'months' => $months,
            'blocks' => $this->blocks($monthStarts),
            'envelopeBp' => $this->plan->rankEnvelopeBp(),
            'month' => $month,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function export(Request $request): Response
    {
        abort_unless(Feature::for(null)->active(RankBonusFeature::class), 404);

        [$month, $from, $to] = $this->filters($request);

        $monthStarts = array_values($this->monthQuery($month, $from, $to)
            ->get()
            ->map(fn (\stdClass $row) => Carbon::parse($row->month_start)->toDateString())
            ->all());

        $blocks = $this->blocks($monthStarts);

        $csv = "Month,Month Turnover,Rank,Rank Name,Pool % (current),Pool (Rs),Qualifiers,Held,Total Points,Point Value / Share (Rs),Income (Rs),Leftover (Rs),Computed At\n";

        foreach ($monthStarts as $monthStart) {
            $block = $blocks[$monthStart];
            $monthLabel = Carbon::parse($monthStart)->format('Y-m');
            $computedAt = $block['computed_at']?->format('Y-m-d H:i:s') ?? '';
            $turnover = $block['turnover_paise'] !== null
                ? number_format($block['turnover_paise'] / 100, 2, '.', '')
                : '';

            foreach ($block['ranks'] as $rank) {
                $valuePaise = $rank['point_value_paise'] ?? $rank['share_paise'];

                $csv .= implode(',', [
                    $monthLabel,
                    $turnover,
                    $rank['rank'],
                    $this->csvStr($rank['name'].($rank['frozen'] ? '' : ' (estimated)')),
                    number_format($rank['pool_pct'], 2, '.', ''),
                    number_format($rank['pool_paise'] / 100, 2, '.', ''),
                    $rank['qualifiers'],
                    $rank['held'],
                    $rank['total_points'] ?? '',
                    $valuePaise !== null ? number_format($valuePaise / 100, 2, '.', '') : '',
                    number_format($rank['income_paise'] / 100, 2, '.', ''),
                    $rank['leftover_paise'] !== null ? number_format($rank['leftover_paise'] / 100, 2, '.', '') : '',
                    $computedAt,
                ])."\n";
            }

            if ($block['aogo'] !== null) {
                $csv .= implode(',', [
                    $monthLabel,
                    $turnover,
                    1,
                    $this->csvStr('AO-GO (Rank 1 pool)'),
                    '',
                    '',
                    $block['aogo']['grants'],
                    0,
                    $block['aogo']['points'],
                    $block['aogo']['point_value_paise'] !== null
                        ? number_format($block['aogo']['point_value_paise'] / 100, 2, '.', '')
                        : '',
                    number_format($block['aogo']['income_paise'] / 100, 2, '.', ''),
                    '',
                    $computedAt,
                ])."\n";
            }

            $csv .= implode(',', [
                $monthLabel,
                $turnover,
                '',
                $this->csvStr('MONTH TOTAL'),
                '', '', '', '', '', '',
                number_format($block['total_income_paise'] / 100, 2, '.', ''),
                number_format($block['total_leftover_paise'] / 100, 2, '.', ''),
                $computedAt,
            ])."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="rb-input-output-'.now()->toDateString().'.csv"',
        ]);
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} Y-m month filters
     */
    private function filters(Request $request): array
    {
        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m'],
        ]);

        return [
            $this->monthParam($request, 'month'),
            $this->monthParam($request, 'from'),
            $this->monthParam($request, 'to'),
        ];
    }

    private function monthParam(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Months with any Rank Bonus activity, newest first. The union catches the
     * corner case of a month whose only Rank-1 pool spend was AO-GO grants
     * (no qualifier rows at any rank).
     *
     * @return Builder
     */
    private function monthQuery(?string $month, ?string $from, ?string $to)
    {
        $constrain = function ($q) use ($month, $from, $to) {
            return $q
                ->when($month, fn ($b) => $b->where('month_start', $month.'-01'))
                ->when($from, fn ($b) => $b->where('month_start', '>=', $from.'-01'))
                ->when($to, fn ($b) => $b->where('month_start', '<=', $to.'-01'));
        };

        return $constrain(DB::table('rank_bonus_results')->select('month_start'))
            ->union($constrain(DB::table('rank_aogo_grants')->select('month_start')))
            ->orderByDesc('month_start');
    }

    /**
     * Assemble one report block per month: the nine rank rows (frozen where
     * result rows exist, estimated from current settings where none do), the
     * AO-GO line, and the month totals.
     *
     * @param  list<string>  $monthStarts  'Y-m-01' keys
     * @return array<string, array{
     *     computed_at: ?Carbon,
     *     turnover_paise: ?int,
     *     ranks: list<array{rank: int, name: string, pool_pct: float, pool_paise: int, frozen: bool, qualifiers: int, held: int, total_points: ?int, point_value_paise: ?int, share_paise: ?int, income_paise: int, leftover_paise: ?int}>,
     *     aogo: ?array{grants: int, points: int, point_value_paise: ?int, income_paise: int},
     *     total_income_paise: int,
     *     total_leftover_paise: int
     * }>
     */
    private function blocks(array $monthStarts): array
    {
        if ($monthStarts === []) {
            return [];
        }

        $rankAggregates = DB::table('rank_bonus_results')
            ->whereIn('month_start', $monthStarts)
            ->groupBy('month_start', 'rank_number')
            ->select('month_start', 'rank_number')
            ->selectRaw('MAX(company_turnover_paise) as turnover_paise')
            ->selectRaw('MAX(pool_paise) as pool_paise')
            ->selectRaw('MAX(qualifier_count) as qualifier_count')
            ->selectRaw('MAX(total_points) as total_points')
            ->selectRaw('MAX(point_value_paise) as point_value_paise')
            ->selectRaw("SUM(CASE WHEN status = 'credited' AND aogo_points IS NULL THEN gross_paise ELSE 0 END) as income_paise")
            ->selectRaw("MAX(CASE WHEN status = 'credited' AND aogo_points IS NULL THEN gross_paise ELSE NULL END) as share_paise")
            ->selectRaw("SUM(CASE WHEN status = 'requalification_held' THEN 1 ELSE 0 END) as held_count")
            ->selectRaw('MAX(created_at) as computed_at')
            ->get()
            ->groupBy(fn (\stdClass $row) => Carbon::parse($row->month_start)->toDateString());

        $aogoAggregates = DB::table('rank_aogo_grants')
            ->whereIn('month_start', $monthStarts)
            ->where('status', '!=', RankAogoGrant::STATUS_VOIDED)
            ->groupBy('month_start')
            ->select('month_start')
            ->selectRaw('COUNT(*) as grants')
            ->selectRaw('COALESCE(SUM(points), 0) as points')
            ->selectRaw('MAX(point_value_paise) as point_value_paise')
            ->selectRaw("SUM(CASE WHEN status = 'credited' THEN COALESCE(income_paise, 0) ELSE 0 END) as income_paise")
            ->selectRaw('MAX(created_at) as computed_at')
            ->get()
            ->keyBy(fn (\stdClass $row) => Carbon::parse($row->month_start)->toDateString());

        $envelopeBp = $this->plan->rankEnvelopeBp();
        $blocks = [];

        foreach ($monthStarts as $monthStart) {
            $byRank = ($rankAggregates[$monthStart] ?? collect())->keyBy('rank_number');
            $aogoRow = $aogoAggregates[$monthStart] ?? null;

            $turnover = $byRank->isNotEmpty()
                ? (int) $byRank->max('turnover_paise')
                : null;

            // When the month's rows were written — i.e. when the engine (or a
            // testing recompute) last computed this month. The report shows
            // the data as it stood at this moment.
            $computedAt = collect([$byRank->max('computed_at'), $aogoRow->computed_at ?? null])
                ->filter()
                ->map(fn ($ts) => Carbon::parse($ts))
                ->max();

            $aogo = $aogoRow !== null ? [
                'grants' => (int) $aogoRow->grants,
                'points' => (int) $aogoRow->points,
                'point_value_paise' => $aogoRow->point_value_paise !== null ? (int) $aogoRow->point_value_paise : null,
                'income_paise' => (int) $aogoRow->income_paise,
            ] : null;

            $ranks = [];
            $totalIncome = $aogo['income_paise'] ?? 0;
            $totalLeftover = 0;

            foreach (range(1, 9) as $rank) {
                $agg = $byRank->get($rank);

                if ($agg !== null) {
                    $income = (int) $agg->income_paise;
                    // AO-GO grants are paid out of the Rank-1 pool, so Rank 1's
                    // leftover only reconciles after subtracting their income.
                    $leftover = (int) $agg->pool_paise - $income
                        - ($rank === 1 ? ($aogo['income_paise'] ?? 0) : 0);

                    $ranks[] = [
                        'rank' => $rank,
                        'name' => $this->plan->rankName($rank),
                        'pool_pct' => $this->plan->rankPoolPct($rank),
                        'pool_paise' => (int) $agg->pool_paise,
                        'frozen' => true,
                        'qualifiers' => (int) $agg->qualifier_count,
                        'held' => (int) $agg->held_count,
                        'total_points' => $agg->total_points !== null ? (int) $agg->total_points : null,
                        'point_value_paise' => $agg->point_value_paise !== null ? (int) $agg->point_value_paise : null,
                        'share_paise' => $agg->share_paise !== null ? (int) $agg->share_paise : null,
                        'income_paise' => $income,
                        'leftover_paise' => $leftover,
                    ];

                    $totalIncome += $income;
                    $totalLeftover += $leftover;

                    continue;
                }

                // No rows for this rank: nothing was frozen. Estimate the
                // unspent pool from the month's stored turnover and the
                // CURRENT settings, using the engine's exact arithmetic.
                $poolPct = $this->plan->rankPoolPct($rank);
                $estimated = $turnover !== null
                    ? max(0, (int) round($turnover * $envelopeBp / 10_000 * $poolPct / 100))
                    : 0;

                $ranks[] = [
                    'rank' => $rank,
                    'name' => $this->plan->rankName($rank),
                    'pool_pct' => $poolPct,
                    'pool_paise' => $estimated,
                    'frozen' => false,
                    'qualifiers' => 0,
                    'held' => 0,
                    'total_points' => null,
                    'point_value_paise' => null,
                    'share_paise' => null,
                    'income_paise' => 0,
                    'leftover_paise' => null,
                ];
            }

            $blocks[$monthStart] = [
                'computed_at' => $computedAt,
                'turnover_paise' => $turnover,
                'ranks' => $ranks,
                'aogo' => $aogo,
                'total_income_paise' => $totalIncome,
                'total_leftover_paise' => $totalLeftover,
            ];
        }

        return $blocks;
    }

    private function csvStr(string $value): string
    {
        $value = str_replace('"', '""', $value);
        if (preg_match('/^[=+\-@]/', $value)) {
            $value = "\t".$value;
        }

        return '"'.$value.'"';
    }
}
