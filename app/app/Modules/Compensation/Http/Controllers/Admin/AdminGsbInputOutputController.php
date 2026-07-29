<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\GsbDailyPool;
use App\Modules\Compensation\Services\GsbDailyPoolService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GSB "Input & Output Per Day" calculation report (KP 2026-07-29).
 *
 * One block per cut-off day, split into the fixed section (slabs 1–2, score
 * value always the slab's fixed ₹ value) and the variable section (slabs 3–7,
 * the day's frozen pool score value with its variance vs the fixed cap).
 * Day/week numbers count from the first pooled day (gsb_daily_pools anchor).
 *
 * Per-slab aggregates cover the statuses whose gross funded the day's pool
 * (credited / frozen / repurchase held / suspended / reversed) so the day's
 * grand total reconciles with the pool row's leftover. Suspended income is
 * later forfeited and reversed income was debited back from the wallet, so on
 * such days the stored leftover understates the real residue — the report
 * shows the pool row verbatim (frozen economics). Per-slab score value uses
 * MAX(score_value_paise): when a day+slab mixes snapshotted and legacy rows
 * one value is shown, but money is unaffected (income sums gross_gsb_paise).
 */
final class AdminGsbInputOutputController extends Controller
{
    private const DAYS_PER_PAGE = 10;

    private const FUNDED_STATUSES = ['credited', 'frozen', 'repurchase_held', 'repurchase_suspended', 'reversed'];

    public function index(Request $request): View
    {
        [$day, $week, $from, $to] = $this->filters($request);

        $anchor = $this->anchorDate();

        $pools = $this->poolQuery($anchor, $day, $week, $from, $to)
            ->paginate(self::DAYS_PER_PAGE)
            ->withQueryString();

        $dates = array_values(array_map(
            fn (GsbDailyPool $p) => $p->cutoff_date->toDateString(),
            $pools->items(),
        ));

        return view('admin.compensation.gsb-input-output.index', [
            'pools' => $pools,
            'slabAggregates' => $this->slabAggregates($dates),
            'anchor' => $anchor,
            'day' => $day,
            'week' => $week,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]);
    }

    public function export(Request $request): Response
    {
        [$day, $week, $from, $to] = $this->filters($request);

        $anchor = $this->anchorDate();
        $pools = $this->poolQuery($anchor, $day, $week, $from, $to)->get();
        $aggregates = $this->slabAggregates(array_values(
            $pools->map(fn (GsbDailyPool $p) => $p->cutoff_date->toDateString())->all(),
        ));

        $csv = "Day,Week,Date,Day Total BV (Rs),GSB Pool (Rs),Slab,Section,Achievers,Total Score,Score Value (Rs),Income (Rs),Variance (Rs)\n";

        foreach ($pools as $pool) {
            $dateStr = $pool->cutoff_date->toDateString();
            $dayNo = $this->dayNumber($anchor, $pool->cutoff_date);
            $weekNo = $dayNo === null ? null : intdiv($dayNo - 1, 7) + 1;
            $grandTotal = 0;

            foreach ($aggregates[$dateStr] ?? [] as $agg) {
                $isFixed = ! GsbDailyPoolService::isVariableSlab((int) $agg->slab);
                $valuePaise = $this->displayScoreValuePaise($agg, $pool);
                $variancePaise = $isFixed ? 0 : $valuePaise - $pool->variable_score_value_cap_paise;
                $grandTotal += (int) $agg->income_paise;

                $csv .= implode(',', [
                    $dayNo ?? '',
                    $weekNo ?? '',
                    $dateStr,
                    number_format($pool->company_bv_paise / 100, 2, '.', ''),
                    number_format($pool->pool_paise / 100, 2, '.', ''),
                    (int) $agg->slab,
                    $this->csvStr($isFixed ? 'Fixed' : 'Variable'),
                    (int) $agg->achievers,
                    (int) $agg->total_score,
                    number_format($valuePaise / 100, 2, '.', ''),
                    number_format($agg->income_paise / 100, 2, '.', ''),
                    number_format($variancePaise / 100, 2, '.', ''),
                ])."\n";
            }

            $csv .= implode(',', [
                $dayNo ?? '',
                $weekNo ?? '',
                $dateStr,
                number_format($pool->company_bv_paise / 100, 2, '.', ''),
                number_format($pool->pool_paise / 100, 2, '.', ''),
                '',
                $this->csvStr('DAY TOTAL'),
                '',
                '',
                '',
                number_format($grandTotal / 100, 2, '.', ''),
                $this->csvStr('leftover '.number_format($pool->leftover_paise / 100, 2, '.', '')),
            ])."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="gsb-input-output-'.now()->toDateString().'.csv"',
        ]);
    }

    /**
     * @return array{0: ?int, 1: ?int, 2: ?Carbon, 3: ?Carbon}
     */
    private function filters(Request $request): array
    {
        $request->validate([
            'day' => ['nullable', 'integer', 'min:1'],
            'week' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return [
            $request->query('day') !== null && $request->query('day') !== '' ? (int) $request->query('day') : null,
            $request->query('week') !== null && $request->query('week') !== '' ? (int) $request->query('week') : null,
            $request->query('from') ? Carbon::parse((string) $request->query('from')) : null,
            $request->query('to') ? Carbon::parse((string) $request->query('to')) : null,
        ];
    }

    /** First pooled day — day 1 of the report's day/week numbering. */
    private function anchorDate(): ?Carbon
    {
        $min = GsbDailyPool::min('cutoff_date');

        return $min === null ? null : Carbon::parse((string) $min)->startOfDay();
    }

    public function dayNumber(?Carbon $anchor, Carbon $date): ?int
    {
        if ($anchor === null) {
            return null;
        }

        return (int) $anchor->diffInDays($date->copy()->startOfDay()) + 1;
    }

    /** @return Builder<GsbDailyPool> */
    private function poolQuery(?Carbon $anchor, ?int $day, ?int $week, ?Carbon $from, ?Carbon $to): Builder
    {
        return GsbDailyPool::query()
            ->when($day !== null && $anchor !== null, fn ($q) => $q->whereDate(
                'cutoff_date', $anchor->copy()->addDays($day - 1)->toDateString(),
            ))
            ->when($week !== null && $anchor !== null, fn ($q) => $q
                ->whereDate('cutoff_date', '>=', $anchor->copy()->addDays(($week - 1) * 7)->toDateString())
                ->whereDate('cutoff_date', '<=', $anchor->copy()->addDays(($week - 1) * 7 + 6)->toDateString()))
            ->when($from, fn ($q) => $q->whereDate('cutoff_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('cutoff_date', '<=', $to->toDateString()))
            ->orderByDesc('cutoff_date');
    }

    /**
     * Per-day, per-slab aggregates over the pool-funded result statuses.
     *
     * @param  list<string>  $dates
     * @return array<string, list<\stdClass>> date → rows {slab, achievers, total_score, income_paise, snap_value_paise, fixed_value_paise}
     */
    private function slabAggregates(array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        return DB::table('gsb_cutoff_results as gcr')
            ->leftJoin('gsb_slabs as gs', 'gs.slab', '=', 'gcr.slab')
            ->whereNotNull('gcr.slab')
            ->whereIn('gcr.status', self::FUNDED_STATUSES)
            ->whereIn(DB::raw('DATE(gcr.cutoff_date)'), $dates)
            ->groupBy('gcr.cutoff_date', 'gcr.slab')
            ->select('gcr.cutoff_date', 'gcr.slab')
            ->selectRaw('COUNT(*) as achievers')
            ->selectRaw('COALESCE(SUM(COALESCE(gcr.score, gs.score)), 0) as total_score')
            ->selectRaw('COALESCE(SUM(gcr.gross_gsb_paise), 0) as income_paise')
            ->selectRaw('MAX(gcr.score_value_paise) as snap_value_paise')
            ->selectRaw('MAX(gs.score_value_paise) as fixed_value_paise')
            ->orderBy('gcr.slab')
            ->get()
            ->groupBy(fn (\stdClass $row) => Carbon::parse($row->cutoff_date)->toDateString())
            ->map(fn ($rows) => array_values($rows->all()))
            ->all();
    }

    /**
     * Score value shown for a slab row: the per-row snapshot when present,
     * else the day's frozen pool value (variable) / the slab's fixed value.
     */
    public function displayScoreValuePaise(\stdClass $agg, GsbDailyPool $pool): int
    {
        if ($agg->snap_value_paise !== null) {
            return (int) $agg->snap_value_paise;
        }

        return GsbDailyPoolService::isVariableSlab((int) $agg->slab)
            ? $pool->variable_score_value_paise
            : (int) ($agg->fixed_value_paise ?? 0);
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
