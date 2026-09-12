<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Services\BonusCalculationSnapshots;
use App\Modules\Compensation\Services\PersonalBvTitleService;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use App\Modules\Shared\Support\ReportExport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MSB daily (24-hr) calculation report (KP 2026-07-25 points engine).
 * Columns: SNo · Sponsor ADN · Name · Title · Date · Sponsee ADN · Name ·
 * MSB-Points · Value · Received Income. Points/value come from the per-row
 * snapshots (legacy rate-ladder rows have none and render "—").
 * Grand totals for MSB points and Income are computed over the full filtered set.
 */
final class AdminMsbCalculationController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly PersonalBvTitleService $titleService,
        private readonly BonusCalculationSnapshots $snapshots,
    ) {}

    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(MentorshipBonusFeature::class), 404);

        [$q, $from, $to, $status, $slab] = $this->filters($request);

        $rows = $this->buildQuery($q, $from, $to, $status, $slab)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $sponsorIds = collect($rows->items())->pluck('sponsor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($sponsorIds);
        $totals = $this->totals($q, $from, $to, $status, $slab);

        // Header + formula blocks: one per cut-off day on this page, newest
        // first, capped so a 50-row page cannot render 50 of them.
        $pageDates = $this->snapshots->datesOnPage($rows->items(), 'cutoff_date');
        $dayPools = $this->snapshots->msbDays($pageDates->take(BonusCalculationSnapshots::MAX_DAILY_BLOCKS)->all());

        return view('admin.compensation.msb-calculation.index', [
            'dayPools' => $dayPools,
            'hiddenDays' => max(0, $pageDates->count() - BonusCalculationSnapshots::MAX_DAILY_BLOCKS),
            'rows' => $rows,
            'q' => $q ?: null,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'status' => $status,
            'slab' => $slab,
            'titleService' => $this->titleService,
            'personalBvMap' => $personalBvMap,
            'totalPoints' => $totals['points'],
            'totalIncomePaise' => $totals['income_paise'],
            'totalDeductionPaise' => $totals['deduction_paise'],
            'totalCreditedPaise' => $totals['credited_paise'],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless(Feature::for(null)->active(MentorshipBonusFeature::class), 404);

        [$q, $from, $to, $status, $slab] = $this->filters($request);

        $rows = $this->buildQuery($q, $from, $to, $status, $slab)->get();

        $sponsorIds = $rows->pluck('sponsor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($sponsorIds);
        $totals = $this->totals($q, $from, $to, $status, $slab);

        $columns = [
            ['key' => 'sno',          'label' => 'SNo'],
            ['key' => 'sponsor_adn',  'label' => 'Sponsor ADN'],
            ['key' => 'sponsor_name', 'label' => 'Sponsor Name'],
            ['key' => 'title',        'label' => 'Title'],
            ['key' => 'date',         'label' => 'Date'],
            ['key' => 'sponsee_adn',  'label' => 'Sponsee ADN'],
            ['key' => 'sponsee_name', 'label' => 'Sponsee Name'],
            ['key' => 'msb_points',   'label' => 'MSB Points'],
            ['key' => 'value',        'label' => 'Value (Rs)'],
            ['key' => 'income',       'label' => 'Income (Rs)'],
            ['key' => 'deduction',    'label' => 'Repurchase Deduction (Rs)'],
            ['key' => 'credited',     'label' => 'Credited to Wallet (Rs)'],
            ['key' => 'status',       'label' => 'Status'],
        ];

        $out = $rows->values()->map(function ($row, int $i) use ($personalBvMap): array {
            $title = $this->titleService->forBvPaise($personalBvMap[$row->sponsor_id] ?? 0)->title ?? '';

            return [
                'sno' => $i + 1,
                'sponsor_adn' => (string) $row->sponsor_adn,
                'sponsor_name' => (string) ($row->sponsor_name ?? ''),
                'title' => $title,
                'date' => Carbon::parse($row->cutoff_date)->toDateString(),
                'sponsee_adn' => (string) $row->sponsee_adn,
                'sponsee_name' => (string) ($row->sponsee_name ?? ''),
                'msb_points' => $row->msb_points !== null ? (int) $row->msb_points : '',
                'value' => $row->msb_point_value_paise !== null ? $row->msb_point_value_paise / 100 : '',
                'income' => $row->mb_gross_paise / 100,
                'deduction' => $row->repurchase_deduction_paise / 100,
                'credited' => $row->mb_net_paise / 100,
                'status' => (string) $row->status,
            ];
        })->all();

        // Grand total row across the full filtered set (MSB points, Income,
        // repurchase deduction, credited to wallet).
        $out[] = [
            'sno' => 'TOTAL', 'sponsor_adn' => '', 'sponsor_name' => '', 'title' => '', 'date' => '',
            'sponsee_adn' => '', 'sponsee_name' => '',
            'msb_points' => $totals['points'], 'value' => '',
            'income' => $totals['income_paise'] / 100,
            'deduction' => $totals['deduction_paise'] / 100,
            'credited' => $totals['credited_paise'] / 100,
            'status' => '',
        ];

        return ReportExport::respond($request, 'msb-calculation-'.now()->toDateString(), $columns, $out);
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
            'status' => ['nullable', 'in:credited,failed'],
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
                'mbr.id',
                'mbr.sponsor_id',
                'mbr.sponsee_id',
                'mbr.cutoff_date',
                'mbr.slab',
                'mbr.msb_points',
                'mbr.msb_point_value_paise',
                'mbr.sponsee_gsb_paise',
                'mbr.mb_gross_paise',
                'mbr.repurchase_deduction_paise',
                'mbr.mb_net_paise',
                'mbr.status',
                'sponsor.adn as sponsor_adn',
                'sponsor_user.full_name as sponsor_name',
                'sponsee.adn as sponsee_adn',
                'sponsee_user.full_name as sponsee_name',
            )
            ->orderByDesc('mbr.cutoff_date')
            ->orderByDesc('mbr.id');
    }

    /**
     * Grand totals over the full filtered set (not just the current page).
     * Legacy ladder rows have null msb_points and drop out of the points sum;
     * their income still counts.
     *
     * @return array{points: int, income_paise: int, deduction_paise: int, credited_paise: int}
     */
    private function totals(string $q, ?Carbon $from, ?Carbon $to, ?string $status, ?int $slab): array
    {
        $row = (array) $this->filtered($q, $from, $to, $status, $slab)
            ->selectRaw('COALESCE(SUM(mbr.msb_points), 0) as total_points')
            ->selectRaw('COALESCE(SUM(mbr.mb_gross_paise), 0) as total_income_paise')
            ->selectRaw('COALESCE(SUM(mbr.repurchase_deduction_paise), 0) as total_deduction_paise')
            ->selectRaw('COALESCE(SUM(mbr.mb_net_paise), 0) as total_credited_paise')
            ->first();

        return [
            'points' => (int) ($row['total_points'] ?? 0),
            'income_paise' => (int) ($row['total_income_paise'] ?? 0),
            'deduction_paise' => (int) ($row['total_deduction_paise'] ?? 0),
            'credited_paise' => (int) ($row['total_credited_paise'] ?? 0),
        ];
    }

    /**
     * Shared filtered base query (joins + where clauses, no select/order) so the
     * paginated rows and the grand totals apply exactly the same filters.
     */
    private function filtered(string $q, ?Carbon $from, ?Carbon $to, ?string $status, ?int $slab): Builder
    {
        return DB::table('mentorship_bonus_results as mbr')
            ->join('distributors as sponsor', 'sponsor.id', '=', 'mbr.sponsor_id')
            ->leftJoin('users as sponsor_user', 'sponsor_user.id', '=', 'sponsor.user_id')
            ->join('distributors as sponsee', 'sponsee.id', '=', 'mbr.sponsee_id')
            ->leftJoin('users as sponsee_user', 'sponsee_user.id', '=', 'sponsee.user_id')
            ->when($q, fn ($b) => $b->where(fn ($sub) => $sub
                ->where('sponsor.adn', 'like', "%{$q}%")
                ->orWhere('sponsor_user.full_name', 'like', "%{$q}%")
                ->orWhere('sponsee.adn', 'like', "%{$q}%")
                ->orWhere('sponsee_user.full_name', 'like', "%{$q}%")
            ))
            ->when($from, fn ($b) => $b->where('mbr.cutoff_date', '>=', $from->toDateString()))
            ->when($to, fn ($b) => $b->where('mbr.cutoff_date', '<=', $to->toDateString()))
            ->when($status, fn ($b) => $b->where('mbr.status', $status))
            ->when($slab, fn ($b) => $b->where('mbr.slab', $slab));
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
