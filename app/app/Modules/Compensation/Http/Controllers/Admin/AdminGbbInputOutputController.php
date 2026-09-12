<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\GbbMonthlyPool;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Compensation\Services\GrowthBoosterBonusService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Support\ReportExport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GBB "Input & Output Per Month" calculation report — the monthly sibling of
 * the per-day GSB/MSB Input & Output reports.
 *
 * One block per month showing the pool arithmetic behind every credit: the
 * month's total company BV, the GBB pool (the configured rate of that BV),
 * every distributor who earned AGP, the one point value the month froze, and
 * their income — footed by the month's total AGP and total income, which tally
 * to the frozen payout and leftover.
 *
 * Driven FROM gbb_monthly_pools (the frozen economics row) so a month whose
 * pool went unspent still appears. Repurchase-wallet-blocked rows are listed
 * with ₹0 — the wallet was not cleared at the last instant of the month, so
 * their AGP was excluded from the denominator and is never paid. Legacy held
 * and suspended rows are listed too: no run writes them any more, but held rows
 * WERE priced into their month's pool, so the month only reconciles with them
 * on the page. The report renders the pool row verbatim; it never recomputes
 * frozen economics.
 */
final class AdminGbbInputOutputController extends Controller
{
    private const MONTHS_PER_PAGE = 12;

    /** Statuses listed in the earner table (reversed rows are excluded). */
    private const EARNER_STATUSES = [
        GbbMonthlyResult::STATUS_CREDITED,
        GbbMonthlyResult::STATUS_REPURCHASE_HELD,
        GbbMonthlyResult::STATUS_REPURCHASE_SUSPENDED,
        GbbMonthlyResult::STATUS_REPURCHASE_WALLET_BLOCKED,
    ];

    public function __construct(private readonly GrowthBoosterBonusService $gbb) {}

    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(GrowthBoosterBonusFeature::class), 404);

        [$month, $from, $to] = $this->filters($request);

        $pools = $this->poolQuery($month, $from, $to)
            ->paginate(self::MONTHS_PER_PAGE)
            ->withQueryString();

        $monthStarts = array_values(array_map(
            fn (GbbMonthlyPool $p) => $p->month_start,
            $pools->items(),
        ));

        return view('admin.compensation.gbb-input-output.index', [
            'pools' => $pools,
            'earners' => $this->earners($monthStarts),
            'lateEarners' => $this->lateEarners($monthStarts),
            'month' => $month,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless(Feature::for(null)->active(GrowthBoosterBonusFeature::class), 404);

        [$month, $from, $to] = $this->filters($request);

        $pools = $this->poolQuery($month, $from, $to)->get();
        $earners = $this->earners(array_values(
            $pools->map(fn (GbbMonthlyPool $p) => $p->month_start)->all(),
        ));

        $columns = [
            ['key' => 'month',          'label' => 'Month'],
            ['key' => 'month_total_bv', 'label' => 'Month Total BV'],
            ['key' => 'gbb_pool',       'label' => 'GBB Pool (Rs)'],
            ['key' => 'total_agp',      'label' => 'Total AGP'],
            ['key' => 'point_value',    'label' => 'Point Value (Rs)'],
            ['key' => 'adn',            'label' => 'Distributor ADN'],
            ['key' => 'name',           'label' => 'Distributor Name'],
            ['key' => 'agp',            'label' => 'AGP'],
            ['key' => 'income',         'label' => 'Income (Rs)'],
            ['key' => 'deduction',      'label' => 'Repurchase Deduction (Rs)'],
            ['key' => 'credited',       'label' => 'Credited to Wallet (Rs)'],
            ['key' => 'status',         'label' => 'Status'],
            ['key' => 'computed_at',    'label' => 'Computed At'],
        ];

        $out = [];

        foreach ($pools as $pool) {
            $monthLabel = Carbon::parse($pool->month_start)->format('Y-m');
            $computedAt = $pool->created_at?->format('Y-m-d H:i:s') ?? '';
            $totalIncome = 0;
            $totalDeduction = 0;
            $totalCredited = 0;

            foreach ($earners[$pool->month_start] ?? [] as $row) {
                $totalIncome += (int) $row->income_paise;
                $totalDeduction += (int) $row->deduction_paise;
                $totalCredited += (int) $row->credited_paise;

                $out[] = [
                    'month' => $monthLabel,
                    'month_total_bv' => $pool->company_bv_paise / 100,
                    'gbb_pool' => $pool->pool_paise / 100,
                    'total_agp' => $pool->total_agp,
                    'point_value' => $pool->point_value_paise / 100,
                    'adn' => (string) ($row->adn ?? ''),
                    'name' => (string) ($row->full_name ?? ''),
                    'agp' => (int) $row->agp_earned,
                    'income' => ((int) $row->income_paise) / 100,
                    'deduction' => ((int) $row->deduction_paise) / 100,
                    'credited' => ((int) $row->credited_paise) / 100,
                    'status' => (string) $row->status,
                    'computed_at' => $computedAt,
                ];
            }

            $out[] = [
                'month' => $monthLabel,
                'month_total_bv' => $pool->company_bv_paise / 100,
                'gbb_pool' => $pool->pool_paise / 100,
                'total_agp' => $pool->total_agp,
                'point_value' => $pool->point_value_paise / 100,
                'adn' => '',
                'name' => 'MONTH TOTAL',
                'agp' => $pool->total_agp,
                'income' => $totalIncome / 100,
                'deduction' => $totalDeduction / 100,
                'credited' => $totalCredited / 100,
                'status' => 'leftover '.number_format($pool->leftover_paise / 100, 2, '.', ''),
                'computed_at' => $computedAt,
            ];
        }

        return ReportExport::respond($request, 'gbb-input-output-'.now()->toDateString(), $columns, $out);
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
     * month_start is a raw 'Y-m-d' string column (see GbbMonthlyPool) — the
     * 'Y-m-01' string comparisons below work identically on MySQL DATE and
     * the SQLite test driver.
     *
     * @return Builder<GbbMonthlyPool>
     */
    private function poolQuery(?string $month, ?string $from, ?string $to): Builder
    {
        return GbbMonthlyPool::query()
            ->when($month, fn ($q) => $q->where('month_start', $month.'-01'))
            ->when($from, fn ($q) => $q->where('month_start', '>=', $from.'-01'))
            ->when($to, fn ($q) => $q->where('month_start', '<=', $to.'-01'))
            ->orderByDesc('month_start');
    }

    /**
     * Per-month AGP earners: one row per distributor with the AGP they earned,
     * the frozen point value and their gross. Includes held rows (their AGP is
     * in the frozen denominator) and suspended rows (₹0, excluded from the
     * denominator) so the month's total AGP reconciles visibly.
     *
     * @param  list<string>  $monthStarts  'Y-m-01' keys
     * @return array<string, list<\stdClass>> month_start → rows {distributor_id, adn, full_name, agp_earned, point_value_paise, income_paise, deduction_paise, credited_paise, status}
     */
    private function earners(array $monthStarts): array
    {
        if ($monthStarts === []) {
            return [];
        }

        return DB::table('gbb_monthly_results as gmr')
            ->join('distributors as d', 'd.id', '=', 'gmr.distributor_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->whereIn('gmr.status', self::EARNER_STATUSES)
            ->whereIn('gmr.year_month', $monthStarts)
            ->select('gmr.year_month', 'gmr.distributor_id', 'd.adn', 'u.full_name', 'gmr.status')
            ->selectRaw('gmr.agp_earned as agp_earned')
            ->selectRaw('gmr.point_value_paise as point_value_paise')
            ->selectRaw('gmr.gbb_gross_paise as income_paise')
            ->selectRaw('gmr.repurchase_deduction_paise as deduction_paise')
            ->selectRaw('gmr.gbb_net_paise as credited_paise')
            ->orderByDesc('gmr.agp_earned')
            ->get()
            ->groupBy(fn (\stdClass $row) => Carbon::parse($row->year_month)->toDateString())
            ->map(fn ($rows) => array_values($rows->all()))
            ->all();
    }

    /**
     * Distributors who earned AGP after a month's pool was frozen. The engine
     * refuses them — a pool that has already been divided is never re-divided —
     * so an admin has to see them rather than discover a silent gap.
     *
     * @param  list<string>  $monthStarts  'Y-m-01' keys
     * @return array<string, array<int, string>> month_start → distributor id → ADN
     */
    private function lateEarners(array $monthStarts): array
    {
        $late = [];

        foreach ($monthStarts as $monthStart) {
            $ids = $this->gbb->qualifiedAfterFreeze(Carbon::parse($monthStart));

            if ($ids === []) {
                continue;
            }

            $late[$monthStart] = Distributor::whereIn('id', $ids)
                ->pluck('adn', 'id')
                ->map(fn ($adn): string => (string) $adn)
                ->all();
        }

        return $late;
    }
}
