<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Services\BonusCalculationSnapshots;
use App\Modules\Compensation\Services\PersonalBvTitleService;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Support\ReportExport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminGbbCalculationController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly PersonalBvTitleService $titleService,
        private readonly BonusCalculationSnapshots $snapshots,
    ) {}

    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(GrowthBoosterBonusFeature::class), 404);

        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'in:pending,credited,reversed,repurchase_wallet_blocked'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $month = $request->query('month');
        $status = $request->query('status');

        $rows = $this->queryRows($q, $month, $status);

        $distributorIds = collect($rows->items())->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);

        // Header + formula blocks: the filtered month, else every month among
        // the rows on this page.
        $monthPools = $this->snapshots->gbbMonths($this->snapshots->monthsOnPage($rows->items(), 'year_month', $month));

        return view('admin.compensation.gbb-calculation.index', [
            'monthPools' => $monthPools,
            'rows' => $rows,
            'q' => $q ?: null,
            'month' => $month,
            'status' => $status,
            'titleService' => $this->titleService,
            'personalBvMap' => $personalBvMap,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless(Feature::for(null)->active(GrowthBoosterBonusFeature::class), 404);

        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'in:pending,credited,reversed,repurchase_wallet_blocked'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $month = $request->query('month');
        $status = $request->query('status');

        $rows = $this->buildQuery($q, $month, $status)->get();

        $distributorIds = $rows->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);

        $columns = [
            ['key' => 'sno',          'label' => 'SNo'],
            ['key' => 'adn',         'label' => 'ADN'],
            ['key' => 'name',        'label' => 'Name'],
            ['key' => 'title',       'label' => 'Title'],
            ['key' => 'month',       'label' => 'Month'],
            ['key' => 'agp_points',  'label' => 'AGP Points'],
            ['key' => 'point_value', 'label' => 'Point Value (Rs)'],
            ['key' => 'value_per_point', 'label' => 'AGP Value Per Point (Rs)'],
            ['key' => 'gross',       'label' => 'Gross GBB (Rs)'],
            ['key' => 'deduction',   'label' => 'Repurchase Deduction (Rs)'],
            ['key' => 'credited',    'label' => 'Credited to Wallet (Rs)'],
            ['key' => 'status',      'label' => 'Status'],
        ];

        $out = $rows->values()->map(function ($row, int $i) use ($personalBvMap): array {
            $title = $this->titleService->forBvPaise($personalBvMap[$row->distributor_id] ?? 0)->title ?? '';
            $agpValuePerPoint = $row->agp_earned > 0
                ? $row->gbb_gross_paise / $row->agp_earned / 100
                : 0.0;
            // Frozen month point value; legacy rows predate the snapshot column.
            $pointValue = $row->point_value_paise !== null
                ? (int) $row->point_value_paise / 100
                : '';

            return [
                'sno' => $i + 1,
                'adn' => (string) $row->adn,
                'name' => (string) ($row->full_name ?? ''),
                'title' => $title,
                'month' => Carbon::parse($row->year_month)->format('Y-m'),
                'agp_points' => $row->agp_earned,
                'point_value' => $pointValue,
                'value_per_point' => $agpValuePerPoint,
                'gross' => $row->gbb_gross_paise / 100,
                'deduction' => $row->repurchase_deduction_paise / 100,
                'credited' => $row->gbb_net_paise / 100,
                'status' => (string) $row->status,
            ];
        })->all();

        return ReportExport::respond($request, 'gbb-calculation-'.now()->format('Y-m'), $columns, $out);
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
            ->when($month, fn ($b) => $b->where('gmr.year_month', $month.'-01'))
            ->when($status, fn ($b) => $b->where('gmr.status', $status))
            ->select(
                'gmr.id',
                'gmr.distributor_id',
                'gmr.year_month',
                'gmr.agp_earned',
                'gmr.point_value_paise',
                'gmr.gbb_gross_paise',
                'gmr.repurchase_deduction_paise',
                'gmr.gbb_net_paise',
                'gmr.status',
                'd.adn',
                'u.full_name',
            )
            ->orderByDesc('gmr.year_month')
            ->orderByDesc('gmr.id');
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
