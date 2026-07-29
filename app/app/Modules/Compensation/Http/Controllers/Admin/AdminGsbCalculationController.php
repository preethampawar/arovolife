<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Services\PersonalBvTitleService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GSB 1–7 slab daily (24-hr) calculation report (KP 2026-07-21).
 * Columns: SNo · ADN · Name · Title · Date · Slab · Score · Income.
 * Score is read from the per-row snapshot (gsb_cutoff_results.score), falling
 * back to the slab's current score for rows predating the snapshot column.
 * Grand totals for Score and Income are computed over the full filtered set.
 */
final class AdminGsbCalculationController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly PersonalBvTitleService $titleService,
    ) {}

    public function index(Request $request): View
    {
        [$q, $from, $to, $status, $slab] = $this->filters($request);

        $rows = $this->buildQuery($q, $from, $to, $status, $slab)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $distributorIds = collect($rows->items())->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);
        $totals = $this->totals($q, $from, $to, $status, $slab);

        return view('admin.compensation.gsb-calculation.index', [
            'rows' => $rows,
            'q' => $q ?: null,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'status' => $status,
            'slab' => $slab,
            'titleService' => $this->titleService,
            'personalBvMap' => $personalBvMap,
            'totalScore' => $totals['score'],
            'totalIncomePaise' => $totals['income_paise'],
        ]);
    }

    public function export(Request $request): Response
    {
        [$q, $from, $to, $status, $slab] = $this->filters($request);

        $rows = $this->buildQuery($q, $from, $to, $status, $slab)->get();

        $distributorIds = $rows->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);
        $totals = $this->totals($q, $from, $to, $status, $slab);

        $csv = "SNo,ADN,Name,Title,Date,Slab,Score,Score Value (Rs),Income (Rs),Status\n";
        foreach ($rows as $i => $row) {
            $title = $this->titleService->forBvPaise($personalBvMap[$row->distributor_id] ?? 0)->title ?? '';
            $csv .= implode(',', [
                $i + 1,
                $this->csvStr($row->adn),
                $this->csvStr($row->full_name ?? ''),
                $this->csvStr($title),
                Carbon::parse($row->cutoff_date)->toDateString(),
                $row->slab,
                (int) $row->score,
                $row->score_value_paise !== null ? number_format($row->score_value_paise / 100, 2, '.', '') : '',
                number_format($row->gross_gsb_paise / 100, 2, '.', ''),
                $this->csvStr($row->status),
            ])."\n";
        }

        // Grand total row across the full filtered set (Score, Income).
        $csv .= implode(',', [
            $this->csvStr('TOTAL'),
            '', '', '', '', '',
            $totals['score'],
            '',
            number_format($totals['income_paise'] / 100, 2, '.', ''),
            '',
        ])."\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="gsb-calculation-'.now()->toDateString().'.csv"',
        ]);
    }

    /**
     * @return array{0: string, 1: ?Carbon, 2: ?Carbon, 3: ?string, 4: ?int}
     */
    private function filters(Request $request): array
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', 'in:credited,calculated,repurchase_held,repurchase_suspended,reversed'],
            'slab' => ['nullable', 'integer', 'min:1', 'max:7'],
        ]);

        return [
            trim((string) ($request->query('q') ?? '')),
            $request->query('from') ? Carbon::parse((string) $request->query('from')) : null,
            $request->query('to') ? Carbon::parse((string) $request->query('to')) : null,
            $request->query('status'),
            $request->query('slab') !== null && $request->query('slab') !== '' ? (int) $request->query('slab') : null,
        ];
    }

    private function buildQuery(string $q, ?Carbon $from, ?Carbon $to, ?string $status, ?int $slab): Builder
    {
        return $this->filtered($q, $from, $to, $status, $slab)
            ->select(
                'gcr.id',
                'gcr.distributor_id',
                'gcr.cutoff_date',
                'gcr.slab',
                DB::raw('COALESCE(gcr.score, gs.score) as score'),
                DB::raw('COALESCE(gcr.score_value_paise, gs.score_value_paise) as score_value_paise'),
                'gcr.weaker_bv_paise',
                'gcr.left_bv_paise',
                'gcr.right_bv_paise',
                'gcr.gross_gsb_paise',
                'gcr.net_gsb_paise',
                'gcr.tds_paise',
                'gcr.status',
                'd.adn',
                'u.full_name',
            )
            ->orderByDesc('gcr.cutoff_date')
            ->orderByDesc('gcr.id');
    }

    /**
     * Grand totals over the full filtered set (not just the current page).
     *
     * @return array{score: int, income_paise: int}
     */
    private function totals(string $q, ?Carbon $from, ?Carbon $to, ?string $status, ?int $slab): array
    {
        $row = (array) $this->filtered($q, $from, $to, $status, $slab)
            ->selectRaw('COALESCE(SUM(COALESCE(gcr.score, gs.score)), 0) as total_score')
            ->selectRaw('COALESCE(SUM(gcr.gross_gsb_paise), 0) as total_income_paise')
            ->first();

        return [
            'score' => (int) ($row['total_score'] ?? 0),
            'income_paise' => (int) ($row['total_income_paise'] ?? 0),
        ];
    }

    /**
     * Shared filtered base query (joins + where clauses, no select/order) so the
     * paginated rows and the grand totals apply exactly the same filters.
     */
    private function filtered(string $q, ?Carbon $from, ?Carbon $to, ?string $status, ?int $slab): Builder
    {
        return DB::table('gsb_cutoff_results as gcr')
            ->join('distributors as d', 'd.id', '=', 'gcr.distributor_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('gsb_slabs as gs', 'gs.slab', '=', 'gcr.slab')
            ->whereNotNull('gcr.slab')
            ->when($q, fn ($b) => $b->where(fn ($sub) => $sub
                ->where('d.adn', 'like', "%{$q}%")
                ->orWhere('u.full_name', 'like', "%{$q}%")
            ))
            ->when($from, fn ($b) => $b->where('gcr.cutoff_date', '>=', $from->toDateString()))
            ->when($to, fn ($b) => $b->where('gcr.cutoff_date', '<=', $to->toDateString()))
            ->when($status, fn ($b) => $b->where('gcr.status', $status))
            ->when($slab, fn ($b) => $b->where('gcr.slab', $slab));
    }

    private function csvStr(string $value): string
    {
        $value = str_replace('"', '""', $value);
        if (preg_match('/^[=+\-@]/', $value)) {
            $value = "\t".$value;
        }

        return '"'.$value.'"';
    }

    /**
     * @param  int[]  $distributorIds
     * @return array<int, int> distributor_id → total personal BV paise
     */
    private function batchPersonalBvPaise(array $distributorIds): array
    {
        if ($distributorIds === []) {
            return [];
        }

        return DB::table('bv_ledger_entries')
            ->whereIn('distributor_id', $distributorIds)
            ->where('type', 'accrual')
            ->groupBy('distributor_id')
            ->pluck(DB::raw('SUM(bv_paise)'), 'distributor_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
