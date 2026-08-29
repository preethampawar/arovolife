<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Services\BonusCalculationSnapshots;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\PersonalBvTitleService;
use App\Modules\Shared\Features\FortuneBonusFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * FB Monthly Calculation report — KP's 2026-08-07 mock, one row per
 * distributor per month: ADN, Arete Center, name, title, rank, enrolment date,
 * matrix level, FB points, the month's point value and the resulting income.
 *
 * Since the KP 2026-08-09 cascade a participant's income is the guaranteed
 * minimum plus points × the value applied at their matrix level, capped per
 * level — the row's point_value_paise is that level value, shown next to the
 * total. Rows written before the pool + points rework carry no points and
 * render "—".
 */
final class AdminFortuneBonusCalculationController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly PersonalBvTitleService $titleService,
        private readonly CompensationPlanSettingsService $plan,
        private readonly BonusCalculationSnapshots $snapshots,
    ) {}

    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(FortuneBonusFeature::class), 404);

        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'in:pending,credited,skipped'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $month = $request->query('month');
        $status = $request->query('status');

        $rows = $this->buildQuery($q, $month, $status)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $items = collect($rows->items());
        $distributorIds = $items->pluck('distributor_id')->unique()->values()->all();

        $this->attachMonthlyRanks($items);

        // Header + formula blocks: the filtered month, else every month among
        // the rows on this page.
        $monthPools = $this->snapshots->fortuneMonths($this->snapshots->monthsOnPage($rows->items(), 'month_start', $month));

        return view('admin.compensation.fb-calculation.index', [
            'monthPools' => $monthPools,
            'rows' => $rows,
            'q' => $q ?: null,
            'month' => $month,
            'status' => $status,
            'titleService' => $this->titleService,
            'personalBvMap' => $this->batchPersonalBvPaise($distributorIds),
            'areteCenterMap' => $this->batchAreteCenters($distributorIds),
        ]);
    }

    public function export(Request $request): Response
    {
        abort_unless(Feature::for(null)->active(FortuneBonusFeature::class), 404);

        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'in:pending,credited,skipped'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $month = $request->query('month');
        $status = $request->query('status');

        $rows = $this->buildQuery($q, $month, $status)->get();

        $distributorIds = $rows->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);
        $areteCenterMap = $this->batchAreteCenters($distributorIds);
        $this->attachMonthlyRanks($rows);

        // Numbers stay ungrouped in CSV (project convention) — the grouped
        // Indian format is a display concern of the on-screen report.
        $csv = "SNo,ADN,Arete Center,Name,Title,Rank,Date,Level,FB Points,Value (Rs),Income (Rs),Status\n";

        foreach ($rows as $i => $row) {
            $title = $this->titleService->forBvPaise($personalBvMap[$row->distributor_id] ?? 0)->title ?? '';

            $csv .= implode(',', [
                $i + 1,
                $this->csvStr($row->adn),
                $this->csvStr($areteCenterMap[$row->distributor_id] ?? ''),
                $this->csvStr($row->full_name ?? ''),
                $this->csvStr($title),
                $this->csvStr($row->rank_name ?? ''),
                $row->first_gsb_date !== null ? Carbon::parse($row->first_gsb_date)->format('d/m/y') : '',
                $row->matrix_level,
                $row->points ?? '',
                $row->point_value_paise !== null ? number_format($row->point_value_paise / 100, 2, '.', '') : '',
                number_format($row->gross_paise / 100, 2, '.', ''),
                $this->csvStr($row->status),
            ])."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="fortune-bonus-'.now()->format('Y-m').'.csv"',
        ]);
    }

    private function buildQuery(string $q, ?string $month, ?string $status): Builder
    {
        return DB::table('fortune_bonus_results as fbr')
            ->join('distributors as d', 'd.id', '=', 'fbr.distributor_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('fortune_bonus_participants as fbp', function ($join): void {
                $join->on('fbp.distributor_id', '=', 'fbr.distributor_id')
                    ->on('fbp.month_start', '=', 'fbr.month_start');
            })
            ->when($q, fn ($b) => $b->where(fn ($sub) => $sub
                ->where('d.adn', 'like', "%{$q}%")
                ->orWhere('u.full_name', 'like', "%{$q}%")
            ))
            ->when($month, fn ($b) => $b->where('fbr.month_start', $month.'-01'))
            ->when($status, fn ($b) => $b->where('fbr.status', $status))
            ->select(
                'fbr.id',
                'fbr.distributor_id',
                'fbr.month_start',
                'fbr.matrix_level',
                'fbr.points',
                'fbr.point_value_paise',
                'fbr.gross_paise',
                'fbr.status',
                'fbp.first_gsb_date',
                'd.adn',
                'u.full_name',
            )
            ->orderByDesc('fbr.month_start')
            ->orderBy('fbr.matrix_level')
            ->orderByDesc('fbr.id');
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
     * Lifetime personal BV per distributor — the SIGNED NET sum over every
     * entry type, the canonical definition used by
     * BvLedgerService::totalPersonalBvPaise() and by the Fortune title gate in
     * FortuneBonusService. Filtering to `accrual` would show a Title here that
     * the engine no longer grants once a reversal has landed.
     *
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
            ->groupBy('distributor_id')
            ->pluck(DB::raw('SUM(bv_paise)'), 'distributor_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Current Arete Center per distributor (open membership: effective_to null).
     * The ADC itself is a later phase, so most rows have no center and render
     * "—" — the linkage is read here rather than invented.
     *
     * @param  int[]  $distributorIds
     * @return array<int, string> distributor_id → center name
     */
    private function batchAreteCenters(array $distributorIds): array
    {
        if ($distributorIds === []) {
            return [];
        }

        return DB::table('arete_center_members as acm')
            ->join('arete_centers as ac', 'ac.id', '=', 'acm.center_id')
            ->whereIn('acm.distributor_id', $distributorIds)
            ->whereNull('acm.effective_to')
            ->orderBy('acm.effective_from')
            ->pluck('ac.name', 'acm.distributor_id')
            ->map(fn ($v) => (string) $v)
            ->all();
    }

    /**
     * Set `rank` (number) and `rank_name` on every row from the highest rank
     * that distributor held in that row's month — both null when they held
     * none. The report shows the NAME (KP's mock: "SILVER", "PEARL"), read from
     * the admin-editable rank ladder rather than hardcoded. One grouped query
     * for the whole page — the report spans months, so the lookup is keyed by
     * both.
     *
     * @param  Collection<int, \stdClass>  $rows
     */
    private function attachMonthlyRanks(Collection $rows): void
    {
        $distributorIds = $rows->pluck('distributor_id')->unique()->values()->all();

        if ($distributorIds === []) {
            return;
        }

        $rankNames = $this->plan->rankNames();

        $months = $rows
            ->pluck('month_start')
            ->filter()
            ->map(fn ($month): string => Carbon::parse((string) $month)->toDateString())
            ->unique()
            ->values()
            ->all();

        $map = [];

        $qualifications = DB::table('rank_qualifications')
            ->whereIn('distributor_id', $distributorIds)
            ->whereIn('month_start', $months)
            ->where('status', RankQualification::STATUS_QUALIFIED)
            ->selectRaw('distributor_id, month_start, MAX(rank_number) as max_rank')
            ->groupBy('distributor_id', 'month_start')
            ->get();

        foreach ($qualifications as $qualification) {
            $key = (int) $qualification->distributor_id.'|'.Carbon::parse((string) $qualification->month_start)->toDateString();
            $map[$key] = (int) $qualification->max_rank;
        }

        foreach ($rows as $row) {
            $key = (int) $row->distributor_id.'|'.Carbon::parse((string) $row->month_start)->toDateString();
            $row->rank = $map[$key] ?? null;
            $row->rank_name = $row->rank !== null && isset($rankNames[$row->rank])
                ? mb_strtoupper($rankNames[$row->rank])
                : null;
        }
    }
}
