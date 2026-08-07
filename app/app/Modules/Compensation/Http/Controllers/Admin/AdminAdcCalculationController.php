<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Services\PersonalBvTitleService;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

final class AdminAdcCalculationController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly PersonalBvTitleService $titleService,
    ) {}

    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class), 404);

        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'area' => ['nullable', 'string', 'max:100'],
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'in:pending,credited,reversed'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $area = trim((string) ($request->query('area') ?? ''));
        $month = $request->query('month');
        $status = $request->query('status');

        $rows = $this->buildQuery($q, $area, $month, $status)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $distributorIds = collect($rows->items())->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);

        return view('admin.compensation.adc-calculation.index', [
            'rows' => $rows,
            'q' => $q ?: null,
            'area' => $area ?: null,
            'month' => $month,
            'status' => $status,
            'titleService' => $this->titleService,
            'personalBvMap' => $personalBvMap,
        ]);
    }

    public function export(Request $request): Response
    {
        abort_unless(Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class), 404);

        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'area' => ['nullable', 'string', 'max:100'],
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'in:pending,credited,reversed'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $area = trim((string) ($request->query('area') ?? ''));
        $month = $request->query('month');
        $status = $request->query('status');

        $rows = $this->buildQuery($q, $area, $month, $status)->get();

        $distributorIds = $rows->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);

        $csv = "SNo,ADN,Arete Center,Name,Title,Rank,Month,Monthly Turnover BV (net),Rate %,Gross ADC (Rs),TDS (Rs),Net ADC (Rs),Status,Location,Pincode,District,State\n";

        foreach ($rows as $i => $row) {
            $title = $this->titleService->forBvPaise($personalBvMap[$row->distributor_id] ?? 0)->title ?? '';
            $turnoverBv = number_format($row->total_member_bv_paise / 100, 2, '.', '');
            $ratePct = $row->total_member_bv_paise > 0
                ? number_format($row->gross_paise / $row->total_member_bv_paise * 100, 2, '.', '')
                : '0.00';
            $csv .= implode(',', [
                $i + 1,
                $this->csvStr($row->adn),
                $this->csvStr($row->center_name ?? ''),
                $this->csvStr($row->full_name ?? ''),
                $this->csvStr($title),
                $this->csvStr($row->rank_name ?? '—'),
                Carbon::parse($row->month_start)->format('Y-m'),
                $turnoverBv,
                $ratePct,
                number_format($row->gross_paise / 100, 2, '.', ''),
                number_format($row->tds_paise / 100, 2, '.', ''),
                number_format($row->net_paise / 100, 2, '.', ''),
                $this->csvStr($row->status),
                $this->csvStr($row->center_location ?? ''),
                $this->csvStr($row->pincode ?? ''),
                $this->csvStr($row->district ?? ''),
                $this->csvStr($row->state ?? ''),
            ])."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="adc-calculation-'.now()->format('Y-m').'.csv"',
        ]);
    }

    /**
     * @param  string  $area  Free-text area search — matched against the centre's
     *                        pincode (exact or prefix), district, state and the
     *                        legacy free-text location.
     */
    private function buildQuery(string $q, string $area, ?string $month, ?string $status): Builder
    {
        return DB::table('adc_bonus_results as abr')
            ->join('distributors as d', 'd.id', '=', 'abr.distributor_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->join('arete_centers as ac', 'ac.id', '=', 'abr.center_id')
            ->when($q, fn ($b) => $b->where(fn ($sub) => $sub
                ->where('d.adn', 'like', "%{$q}%")
                ->orWhere('u.full_name', 'like', "%{$q}%")
            ))
            ->when($area, fn ($b) => $b->where(fn ($sub) => $sub
                ->where('ac.pincode', $area)
                ->orWhere('ac.pincode', 'like', "{$area}%")
                ->orWhere('ac.district', 'like', "%{$area}%")
                ->orWhere('ac.state', 'like', "%{$area}%")
                ->orWhere('ac.location', 'like', "%{$area}%")
            ))
            ->when($month, fn ($b) => $b->whereRaw("DATE_FORMAT(abr.month_start, '%Y-%m') = ?", [$month]))
            ->when($status, fn ($b) => $b->where('abr.status', $status))
            ->select(
                'abr.id',
                'abr.distributor_id',
                'abr.center_id',
                'abr.month_start',
                'abr.total_member_bv_paise',
                'abr.member_count',
                'abr.gross_paise',
                'abr.admin_charge_paise',
                'abr.tds_paise',
                'abr.net_paise',
                'abr.status',
                'd.adn',
                'u.full_name',
                'ac.name as center_name',
                'ac.location as center_location',
                'ac.pincode',
                'ac.district',
                'ac.state',
                DB::raw('(SELECT rt2.rank_name FROM rank_qualifications rq2
                    JOIN rank_tiers rt2 ON rt2.rank_number = rq2.rank_number
                    WHERE rq2.distributor_id = abr.distributor_id
                    AND rq2.month_start = abr.month_start
                    AND rq2.status = "qualified"
                    ORDER BY rq2.rank_number DESC LIMIT 1) as rank_name'),
            )
            ->orderByDesc('abr.month_start')
            ->orderByDesc('abr.total_member_bv_paise')
            ->orderByDesc('abr.id');
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
     * `bv_paise` is signed (+ accrual, − reversal), so the unfiltered SUM is the
     * net personal BV. Filtering to accruals would overstate the title of a
     * distributor whose orders were later refunded.
     *
     * @param  int[]  $distributorIds
     * @return array<int, int> distributor_id → net personal BV paise
     */
    private function batchPersonalBvPaise(array $distributorIds): array
    {
        if ($distributorIds === []) {
            return [];
        }

        return DB::table('bv_ledger_entries')
            ->whereIn('distributor_id', $distributorIds)
            ->groupBy('distributor_id')
            ->pluck(DB::raw('SUM(bv_paise)'), 'distributor_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
