<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\RankAogoGrant;
use App\Modules\Compensation\Models\RankMonthlyPass;
use App\Modules\Compensation\Models\RankMonthlyPool;
use App\Modules\Compensation\Services\BonusCalculationSnapshots;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Support\ReportExport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rank Bonus "Input & Output Per Month" calculation report — the monthly
 * sibling of the per-day GSB/MSB Input & Output reports, per rank instead of
 * per slab.
 *
 * Since the frozen roster (R-72) the engine writes one `rank_monthly_pools`
 * row per (month, rank) — pool, points, point value, per-qualifier share,
 * payout and leftover, frozen once and never rewritten — and that row is the
 * source for every month that has one, ranks with no qualifier included.
 *
 * Months frozen before that table existed have only the snapshot the engine
 * copied onto every rank_bonus_results row (turnover, per-rank pool, qualifier
 * count, points, point value), so those blocks are reconstructed with
 * MAX()/SUM() aggregates over the rows — MAX() is safe because every row of a
 * (month, rank) group carries the same snapshot, and requalification-held rows
 * leave their point columns null. AO-GO grantees get a credited Rank-1 result
 * row too (aogo_points set, rap_points null); those rows are excluded from the
 * achiever income sums so the AO-GO line — sourced from rank_aogo_grants — is
 * never counted twice. Income, deduction and credited sums always come from the
 * result rows, whichever source priced the pool.
 *
 * A month priced under the client's 2026-10-05 two-pass rule also has two
 * frozen rank_monthly_passes rows (one 20% pool; pass 1 = AO-GO + ranks 1..N,
 * pass 2 = ranks N+1..9 from the remainder, each at min(cap, ⌊pool ÷ points⌋)).
 * They feed the block's pass summary and the Pass column, and the pass-2 row's
 * leftover is the month's leftover — the engine stores it, nothing is derived.
 *
 * A month with no pass rows was priced under the per-rank pool rule in force
 * before the two-pass rule; it is flagged `legacy`, has no pass summary and
 * shows no pass per rank. Its ranks that had no qualifiers wrote no rows at
 * all; the report renders them asterisked with no pool, since the per-rank
 * pool percentage that once priced them is retired. Its per-rank leftover is
 * derived (pool − Σ gross), because those months never stored a flooring
 * remainder.
 */
final class AdminRankBonusInputOutputController extends Controller
{
    private const MONTHS_PER_PAGE = 12;

    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
        private readonly BonusCalculationSnapshots $snapshots,
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
            // The month's points model (two-pass, or the legacy Rank-1 one)
            // behind each formula strip — read through the same snapshot
            // service as the calculation reports.
            'rank1Snapshots' => array_combine($monthStarts, array_map(
                fn (string $monthStart): ?array => $this->snapshots->rankBonusMonth(Carbon::parse($monthStart)),
                $monthStarts,
            )),
            'rankNames' => $this->plan->rankNames(),
            'month' => $month,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless(Feature::for(null)->active(RankBonusFeature::class), 404);

        [$month, $from, $to] = $this->filters($request);

        $monthStarts = array_values($this->monthQuery($month, $from, $to)
            ->get()
            ->map(fn (\stdClass $row) => Carbon::parse($row->month_start)->toDateString())
            ->all());

        $blocks = $this->blocks($monthStarts);

        $columns = [
            ['key' => 'month',        'label' => 'Month'],
            ['key' => 'turnover',     'label' => 'Month Turnover'],
            ['key' => 'rank',         'label' => 'Rank'],
            ['key' => 'rank_name',    'label' => 'Rank Name'],
            ['key' => 'pass',         'label' => 'Pass'],
            ['key' => 'pool',         'label' => 'Pool (Rs)'],
            ['key' => 'qualifiers',   'label' => 'Qualifiers'],
            ['key' => 'held',         'label' => 'Held'],
            ['key' => 'blocked',      'label' => 'Repurchase Blocked'],
            ['key' => 'total_points', 'label' => 'Total Points'],
            ['key' => 'point_value',  'label' => 'Point Value / Share (Rs)'],
            ['key' => 'income',       'label' => 'Income (Rs)'],
            ['key' => 'deduction',    'label' => 'Repurchase Deduction (Rs)'],
            ['key' => 'credited',     'label' => 'Credited to Wallet (Rs)'],
            ['key' => 'leftover',     'label' => 'Leftover (Rs)'],
            ['key' => 'computed_at',  'label' => 'Computed At'],
        ];

        $out = [];

        foreach ($monthStarts as $monthStart) {
            $block = $blocks[$monthStart];
            $monthLabel = Carbon::parse($monthStart)->format('Y-m');
            $computedAt = $block['computed_at']?->format('Y-m-d H:i:s') ?? '';
            $turnover = $block['turnover_paise'] !== null ? $block['turnover_paise'] / 100 : '';

            foreach ($block['ranks'] as $rank) {
                $valuePaise = $rank['point_value_paise'] ?? $rank['share_paise'];

                $out[] = [
                    'month' => $monthLabel,
                    'turnover' => $turnover,
                    'rank' => $rank['rank'],
                    'rank_name' => $rank['name'].($rank['frozen'] ? '' : ' (estimated)'),
                    'pass' => $rank['pass'] ?? '',
                    'pool' => $rank['pool_paise'] / 100,
                    'qualifiers' => $rank['qualifiers'],
                    'held' => $rank['held'],
                    'blocked' => $rank['blocked'],
                    'total_points' => $rank['total_points'] ?? '',
                    'point_value' => $valuePaise !== null ? $valuePaise / 100 : '',
                    'income' => $rank['income_paise'] / 100,
                    'deduction' => $rank['deduction_paise'] / 100,
                    'credited' => $rank['credited_paise'] / 100,
                    'leftover' => $rank['leftover_paise'] !== null ? $rank['leftover_paise'] / 100 : '',
                    'computed_at' => $computedAt,
                ];
            }

            if ($block['aogo'] !== null) {
                $out[] = [
                    'month' => $monthLabel,
                    'turnover' => $turnover,
                    'rank' => 1,
                    'rank_name' => $block['legacy'] ? 'AO-GO (Rank 1 pool)' : 'AO-GO (pass 1)',
                    'pass' => $block['legacy'] ? '' : 1,
                    'pool' => '',
                    'qualifiers' => $block['aogo']['grants'],
                    'held' => 0,
                    'blocked' => 0,
                    'total_points' => $block['aogo']['points'],
                    'point_value' => $block['aogo']['point_value_paise'] !== null
                        ? $block['aogo']['point_value_paise'] / 100
                        : '',
                    'income' => $block['aogo']['income_paise'] / 100,
                    'deduction' => $block['aogo']['deduction_paise'] / 100,
                    'credited' => $block['aogo']['credited_paise'] / 100,
                    'leftover' => '',
                    'computed_at' => $computedAt,
                ];
            }

            foreach ($block['passes'] as $pass) {
                $out[] = [
                    'month' => $monthLabel,
                    'turnover' => $turnover,
                    'rank' => '',
                    'rank_name' => 'PASS '.$pass['pass'],
                    'pass' => $pass['pass'],
                    'pool' => $pass['pool_paise'] / 100,
                    'qualifiers' => '',
                    'held' => '',
                    'blocked' => '',
                    'total_points' => $pass['total_points'],
                    'point_value' => $pass['point_value_paise'] / 100,
                    'income' => $pass['payout_paise'] / 100,
                    'deduction' => '',
                    'credited' => '',
                    'leftover' => $pass['leftover_paise'] / 100,
                    'computed_at' => $computedAt,
                ];
            }

            $out[] = [
                'month' => $monthLabel,
                'turnover' => $turnover,
                'rank' => '',
                'rank_name' => 'MONTH TOTAL',
                'pass' => '',
                'pool' => '',
                'qualifiers' => '',
                'held' => '',
                'blocked' => '',
                'total_points' => '',
                'point_value' => '',
                'income' => $block['total_income_paise'] / 100,
                'deduction' => $block['total_deduction_paise'] / 100,
                'credited' => $block['total_credited_paise'] / 100,
                'leftover' => $block['total_leftover_paise'] / 100,
                'computed_at' => $computedAt,
            ];
        }

        return ReportExport::respond($request, 'rb-input-output-'.now()->toDateString(), $columns, $out);
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
     * Months with any Rank Bonus activity, newest first. The unions catch a
     * month whose only spend was AO-GO grants (no qualifier rows at any rank)
     * and a month frozen with nobody to pay — a two-pass month's pass rows
     * carry the whole envelope as leftover, and a legacy month's per-rank pool
     * rows carry each pool as leftover, which is exactly what this report must
     * show.
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
            ->union($constrain(DB::table('rank_monthly_passes')->select('month_start')))
            ->union($constrain(DB::table('rank_monthly_pools')->select('month_start')))
            ->orderByDesc('month_start');
    }

    /**
     * Assemble one report block per month: the frozen pass summary (two-pass
     * months only), the nine rank rows (frozen where result rows exist,
     * asterisked where a legacy month wrote none), the AO-GO line, and the
     * month totals.
     *
     * @param  list<string>  $monthStarts  'Y-m-01' keys
     * @return array<string, array{
     *     computed_at: ?Carbon,
     *     turnover_paise: ?int,
     *     legacy: bool,
     *     passes_incomplete: bool,
     *     envelope_bp: int,
     *     envelope_paise: ?int,
     *     passes: list<array{pass: int, pool_paise: int, total_points: int, raw_point_value_paise: int, point_value_cap_paise: int, point_value_paise: int, payout_paise: int, leftover_paise: int}>,
     *     ranks: list<array{rank: int, name: string, pass: ?int, pool_paise: int, frozen: bool, qualifiers: int, held: int, blocked: int, total_points: ?int, point_value_paise: ?int, share_paise: ?int, income_paise: int, deduction_paise: int, credited_paise: int, leftover_paise: ?int}>,
     *     aogo: ?array{grants: int, points: int, point_value_paise: ?int, income_paise: int, deduction_paise: int, credited_paise: int},
     *     total_income_paise: int,
     *     total_deduction_paise: int,
     *     total_credited_paise: int,
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
            ->selectRaw('SUM(CASE WHEN aogo_points IS NULL THEN COALESCE(repurchase_deduction_paise, 0) ELSE 0 END) as deduction_paise')
            ->selectRaw("SUM(CASE WHEN status = 'credited' AND aogo_points IS NULL THEN COALESCE(net_paise, 0) ELSE 0 END) as credited_paise")
            // AO-GO grantees are credited through a Rank-1 result row (aogo_points
            // set); rank_aogo_grants itself stores no deduction, so the AO-GO
            // line's deduction and credited figures come from those rows.
            ->selectRaw('SUM(CASE WHEN aogo_points IS NOT NULL THEN COALESCE(repurchase_deduction_paise, 0) ELSE 0 END) as aogo_deduction_paise')
            ->selectRaw("SUM(CASE WHEN status = 'credited' AND aogo_points IS NOT NULL THEN COALESCE(net_paise, 0) ELSE 0 END) as aogo_credited_paise")
            ->selectRaw("MAX(CASE WHEN status = 'credited' AND aogo_points IS NULL THEN gross_paise ELSE NULL END) as share_paise")
            ->selectRaw("SUM(CASE WHEN status = 'requalification_held' THEN 1 ELSE 0 END) as held_count")
            // Qualifiers who earned the rank but failed the repurchase-wallet =
            // ₹0 gate. They pay nothing and their share falls to leftover, so
            // without this count a rank reads as if it simply had fewer people.
            ->selectRaw("SUM(CASE WHEN status = 'repurchase_wallet_blocked' THEN 1 ELSE 0 END) as blocked_count")
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

        // The frozen pool rows, where the month has them (R-72 onwards).
        $frozenPools = RankMonthlyPool::query()
            ->whereIn('month_start', $monthStarts)
            ->get()
            ->groupBy(fn (RankMonthlyPool $pool): string => Carbon::parse($pool->month_start)->toDateString())
            ->map(fn ($pools) => $pools->keyBy('rank_number'));

        // Two-pass months (client 2026-10-05): the rank rows carry each rank's
        // allotment with leftover 0; the month's leftover is on the pass-2 row.
        $monthPasses = RankMonthlyPass::query()
            ->whereIn('month_start', $monthStarts)
            ->orderBy('pass')
            ->get()
            ->groupBy(fn (RankMonthlyPass $pass): string => Carbon::parse($pass->month_start)->toDateString());

        // Save refuses an out-of-range ceiling, but the database does not, and
        // the accessor throws on one. The report of a frozen month must still
        // render: each rank's pass then comes off the month's frozen pool rows.
        try {
            $firstPassMaxRank = $this->plan->rankFirstPassMaxRank();
        } catch (\RuntimeException $e) {
            $firstPassMaxRank = null;

            Log::warning('rank.report.first_pass_max_rank_invalid', [
                'error' => $e->getMessage(),
                'fallback' => 'frozen pool pass',
            ]);
        }

        $blocks = [];

        foreach ($monthStarts as $monthStart) {
            $byRank = ($rankAggregates[$monthStart] ?? collect())->keyBy('rank_number');
            $pools = $frozenPools[$monthStart] ?? collect();
            $aogoRow = $aogoAggregates[$monthStart] ?? null;
            $passRows = $monthPasses[$monthStart] ?? collect();
            $legacy = $passRows->isEmpty();
            // The pass boundary for a rank without a frozen pool row: the
            // setting, or — when it is unreadable — the highest rank this
            // month's freeze priced in pass 1 (null: unknown, shown as —).
            $frozenBoundary = $pools->where('pass', 1)->max('rank_number');
            $passBoundary = $firstPassMaxRank ?? ($frozenBoundary !== null ? (int) $frozenBoundary : null);

            $passes = array_values($passRows->map(fn (RankMonthlyPass $pass): array => [
                'pass' => (int) $pass->pass,
                'pool_paise' => (int) $pass->pool_paise,
                'total_points' => (int) $pass->total_points,
                'raw_point_value_paise' => (int) $pass->raw_point_value_paise,
                'point_value_cap_paise' => (int) $pass->point_value_cap_paise,
                'point_value_paise' => (int) $pass->point_value_paise,
                'payout_paise' => (int) $pass->payout_paise,
                'leftover_paise' => (int) $pass->leftover_paise,
            ])->all());

            $turnover = match (true) {
                $pools->isNotEmpty() => (int) $pools->first()->company_turnover_paise,
                $byRank->isNotEmpty() => (int) $byRank->max('turnover_paise'),
                default => null,
            };

            // When the month's rows were written — i.e. when the engine (or a
            // testing recompute) last computed this month. The report shows
            // the data as it stood at this moment.
            $computedAt = collect([$byRank->max('computed_at'), $aogoRow->computed_at ?? null, $pools->max('created_at')])
                ->filter()
                ->map(fn ($ts) => Carbon::parse($ts))
                ->max();

            $rank1 = $byRank->get(1);

            $aogo = $aogoRow !== null ? [
                'grants' => (int) $aogoRow->grants,
                'points' => (int) $aogoRow->points,
                'point_value_paise' => $aogoRow->point_value_paise !== null ? (int) $aogoRow->point_value_paise : null,
                'income_paise' => (int) $aogoRow->income_paise,
                'deduction_paise' => (int) ($rank1->aogo_deduction_paise ?? 0),
                'credited_paise' => (int) ($rank1->aogo_credited_paise ?? 0),
            ] : null;

            $ranks = [];
            $totalIncome = $aogo['income_paise'] ?? 0;
            $totalDeduction = $aogo['deduction_paise'] ?? 0;
            $totalCredited = $aogo['credited_paise'] ?? 0;
            // The engine writes both pass rows in one transaction; a month
            // with only one is an incomplete freeze and is flagged, never
            // read as "leftover ₹0" (principle 5).
            $passesIncomplete = ! $legacy && count($passes) !== 2;
            $totalLeftover = (int) ($passRows->firstWhere('pass', 2)->leftover_paise ?? 0);

            // A two-pass month froze its envelope on the pass rows; a legacy
            // month pairs its turnover with the CURRENT envelope setting.
            $envelopeBp = $legacy ? $this->plan->rankEnvelopeBp() : (int) $passRows->first()->envelope_bp;
            $envelopePaise = match (true) {
                ! $legacy => (int) $passRows->first()->envelope_paise,
                $turnover !== null => max(0, intdiv($turnover * $envelopeBp, 10_000)),
                default => null,
            };

            foreach (range(1, 9) as $rank) {
                $agg = $byRank->get($rank);
                /** @var RankMonthlyPool|null $pool */
                $pool = $pools->get($rank);

                if ($agg !== null || $pool !== null) {
                    $income = (int) ($agg->income_paise ?? 0);
                    $deduction = (int) ($agg->deduction_paise ?? 0);
                    $credited = (int) ($agg->credited_paise ?? 0);
                    $poolPaise = $pool !== null ? (int) $pool->pool_paise : (int) $agg->pool_paise;
                    // The frozen row stores the engine's own remainder; a
                    // legacy month derives it. AO-GO grants are paid out of the
                    // Rank-1 pool, so a derived Rank-1 leftover only
                    // reconciles after subtracting their income.
                    $leftover = $pool !== null
                        ? (int) $pool->leftover_paise
                        : $poolPaise - $income - ($rank === 1 ? ($aogo['income_paise'] ?? 0) : 0);

                    $ranks[] = [
                        'rank' => $rank,
                        'name' => $this->plan->rankName($rank),
                        // A legacy pool row carries the migrated default pass 1,
                        // which would mislead — legacy months show no pass.
                        'pass' => $legacy ? null : ($pool->pass ?? $this->passFor($rank, $passBoundary)),
                        'pool_paise' => $poolPaise,
                        'frozen' => true,
                        'qualifiers' => (int) ($agg->qualifier_count ?? 0),
                        'held' => (int) ($agg->held_count ?? 0),
                        'blocked' => (int) ($agg->blocked_count ?? 0),
                        'total_points' => $pool !== null
                            ? ($pool->total_points !== null ? (int) $pool->total_points : null)
                            : ($agg->total_points !== null ? (int) $agg->total_points : null),
                        'point_value_paise' => $pool !== null
                            ? ($pool->point_value_paise !== null ? (int) $pool->point_value_paise : null)
                            : ($agg->point_value_paise !== null ? (int) $agg->point_value_paise : null),
                        'share_paise' => $pool !== null
                            ? (int) $pool->gross_per_qualifier_paise
                            : ($agg->share_paise !== null ? (int) $agg->share_paise : null),
                        'income_paise' => $income,
                        'deduction_paise' => $deduction,
                        'credited_paise' => $credited,
                        'leftover_paise' => $leftover,
                    ];

                    $totalIncome += $income;
                    $totalDeduction += $deduction;
                    $totalCredited += $credited;
                    $totalLeftover += $leftover;

                    continue;
                }

                // No rows for this rank: nothing was frozen (a legacy month).
                // The per-rank pool percentage is retired (client 2026-10-05
                // one pool, two passes), so there is no per-rank share left
                // to estimate.
                $ranks[] = [
                    'rank' => $rank,
                    'name' => $this->plan->rankName($rank),
                    'pass' => $legacy ? null : $this->passFor($rank, $passBoundary),
                    'pool_paise' => 0,
                    'frozen' => false,
                    'qualifiers' => 0,
                    'held' => 0,
                    'blocked' => 0,
                    'total_points' => null,
                    'point_value_paise' => null,
                    'share_paise' => null,
                    'income_paise' => 0,
                    'deduction_paise' => 0,
                    'credited_paise' => 0,
                    'leftover_paise' => null,
                ];
            }

            $blocks[$monthStart] = [
                'computed_at' => $computedAt,
                'turnover_paise' => $turnover,
                'legacy' => $legacy,
                'passes_incomplete' => $passesIncomplete,
                'envelope_bp' => $envelopeBp,
                'envelope_paise' => $envelopePaise,
                'passes' => $passes,
                'ranks' => $ranks,
                'aogo' => $aogo,
                'total_income_paise' => $totalIncome,
                'total_deduction_paise' => $totalDeduction,
                'total_credited_paise' => $totalCredited,
                'total_leftover_paise' => $totalLeftover,
            ];
        }

        return $blocks;
    }

    /** The pass a rank falls in under a pass-1 ceiling; null when the ceiling is unknown. */
    private function passFor(int $rank, ?int $firstPassMaxRank): ?int
    {
        if ($firstPassMaxRank === null) {
            return null;
        }

        return $rank <= $firstPassMaxRank ? 1 : 2;
    }
}
