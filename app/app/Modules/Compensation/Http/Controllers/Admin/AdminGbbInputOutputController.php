<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\GbbMonthlyPool;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

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
 * pool went unspent still appears. Held rows (repurchase grace) are listed —
 * their AGP sits inside the frozen denominator and releases credit at the
 * frozen point value. Suspended rows are listed with ₹0 — their AGP was
 * excluded from the denominator and is never paid. The report renders the
 * pool row verbatim; it never recomputes frozen economics.
 */
final class AdminGbbInputOutputController extends Controller
{
    private const MONTHS_PER_PAGE = 12;

    /** Statuses listed in the earner table (reversed rows are excluded). */
    private const EARNER_STATUSES = [
        GbbMonthlyResult::STATUS_CREDITED,
        GbbMonthlyResult::STATUS_REPURCHASE_HELD,
        GbbMonthlyResult::STATUS_REPURCHASE_SUSPENDED,
    ];

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
            'month' => $month,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function export(Request $request): Response
    {
        abort_unless(Feature::for(null)->active(GrowthBoosterBonusFeature::class), 404);

        [$month, $from, $to] = $this->filters($request);

        $pools = $this->poolQuery($month, $from, $to)->get();
        $earners = $this->earners(array_values(
            $pools->map(fn (GbbMonthlyPool $p) => $p->month_start)->all(),
        ));

        $csv = "Month,Month Total BV,GBB Pool (Rs),Total AGP,Point Value (Rs),Distributor ADN,Distributor Name,AGP,Income (Rs),Status,Computed At\n";

        foreach ($pools as $pool) {
            $monthLabel = Carbon::parse($pool->month_start)->format('Y-m');
            $computedAt = $pool->created_at?->format('Y-m-d H:i:s') ?? '';
            $totalIncome = 0;

            foreach ($earners[$pool->month_start] ?? [] as $row) {
                $totalIncome += (int) $row->income_paise;

                $csv .= implode(',', [
                    $monthLabel,
                    number_format($pool->company_bv_paise / 100, 2, '.', ''),
                    number_format($pool->pool_paise / 100, 2, '.', ''),
                    $pool->total_agp,
                    number_format($pool->point_value_paise / 100, 2, '.', ''),
                    $this->csvStr((string) ($row->adn ?? '')),
                    $this->csvStr((string) ($row->full_name ?? '')),
                    (int) $row->agp_earned,
                    number_format(((int) $row->income_paise) / 100, 2, '.', ''),
                    $this->csvStr((string) $row->status),
                    $computedAt,
                ])."\n";
            }

            $csv .= implode(',', [
                $monthLabel,
                number_format($pool->company_bv_paise / 100, 2, '.', ''),
                number_format($pool->pool_paise / 100, 2, '.', ''),
                $pool->total_agp,
                number_format($pool->point_value_paise / 100, 2, '.', ''),
                '',
                $this->csvStr('MONTH TOTAL'),
                $pool->total_agp,
                number_format($totalIncome / 100, 2, '.', ''),
                $this->csvStr('leftover '.number_format($pool->leftover_paise / 100, 2, '.', '')),
                $computedAt,
            ])."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="gbb-input-output-'.now()->toDateString().'.csv"',
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
     * @return array<string, list<\stdClass>> month_start → rows {distributor_id, adn, full_name, agp_earned, point_value_paise, income_paise, status}
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
            ->orderByDesc('gmr.agp_earned')
            ->get()
            ->groupBy(fn (\stdClass $row) => Carbon::parse($row->year_month)->toDateString())
            ->map(fn ($rows) => array_values($rows->all()))
            ->all();
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
