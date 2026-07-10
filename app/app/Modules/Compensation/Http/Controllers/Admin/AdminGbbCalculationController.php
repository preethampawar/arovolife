<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Services\PersonalBvTitleService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AdminGbbCalculationController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly PersonalBvTitleService $titleService,
    ) {}

    public function index(Request $request): View
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'in:pending,credited,reversed'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $month = $request->query('month');
        $status = $request->query('status');

        $rows = $this->queryRows($q, $month, $status);

        $distributorIds = collect($rows->items())->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);

        return view('admin.compensation.gbb-calculation.index', [
            'rows' => $rows,
            'q' => $q ?: null,
            'month' => $month,
            'status' => $status,
            'titleService' => $this->titleService,
            'personalBvMap' => $personalBvMap,
        ]);
    }

    public function export(Request $request): Response
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'in:pending,credited,reversed'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $month = $request->query('month');
        $status = $request->query('status');

        $rows = $this->buildQuery($q, $month, $status)->get();

        $distributorIds = $rows->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);

        $csv = "SNo,ADN,Name,Title,Month,AGP Points,AGP Value Per Point (Rs),Gross GBB (Rs),TDS (Rs),Net GBB (Rs),Status\n";

        foreach ($rows as $i => $row) {
            $title = $this->titleService->forBvPaise($personalBvMap[$row->distributor_id] ?? 0)->title ?? '';
            $agpValuePerPoint = $row->agp_earned > 0
                ? number_format($row->gbb_gross_paise / $row->agp_earned / 100, 2, '.', '')
                : '0.00';
            $csv .= implode(',', [
                $i + 1,
                '"'.$row->adn.'"',
                '"'.($row->full_name ?? '').'"',
                '"'.$title.'"',
                Carbon::parse($row->year_month)->format('Y-m'),
                $row->agp_earned,
                $agpValuePerPoint,
                number_format($row->gbb_gross_paise / 100, 2, '.', ''),
                number_format($row->tds_paise / 100, 2, '.', ''),
                number_format($row->gbb_net_paise / 100, 2, '.', ''),
                '"'.$row->status.'"',
            ])."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="gbb-calculation-'.now()->format('Y-m').'.csv"',
        ]);
    }

    private function queryRows(string $q, ?string $month, ?string $status): LengthAwarePaginator
    {
        return $this->buildQuery($q, $month, $status)
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    private function buildQuery(string $q, ?string $month, ?string $status): Builder
    {
        return DB::table('gbb_monthly_results as gmr')
            ->join('distributors as d', 'd.id', '=', 'gmr.distributor_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->when($q, fn ($b) => $b->where(fn ($sub) => $sub
                ->where('d.adn', 'like', "%{$q}%")
                ->orWhere('u.full_name', 'like', "%{$q}%")
            ))
            ->when($month, fn ($b) => $b->whereRaw("DATE_FORMAT(gmr.year_month, '%Y-%m') = ?", [$month]))
            ->when($status, fn ($b) => $b->where('gmr.status', $status))
            ->select(
                'gmr.id',
                'gmr.distributor_id',
                'gmr.year_month',
                'gmr.agp_earned',
                'gmr.gbb_gross_paise',
                'gmr.tds_paise',
                'gmr.gbb_net_paise',
                'gmr.status',
                'd.adn',
                'u.full_name',
            )
            ->orderByDesc('gmr.year_month')
            ->orderByDesc('gmr.id');
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
