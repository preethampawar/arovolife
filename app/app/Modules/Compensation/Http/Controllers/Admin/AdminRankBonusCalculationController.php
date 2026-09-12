<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Services\BonusCalculationSnapshots;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\PersonalBvTitleService;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
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
 * RB Monthly Calculation report — two tables per KP's 2026-08-05 mocks:
 * Rank 1 (points model: RAP / AO-GO points × point value) and Ranks 2–9
 * (equal split: rank + income). AO-GO grantee rows live in the Rank-1 table
 * with RAP shown as "—".
 */
final class AdminRankBonusCalculationController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly PersonalBvTitleService $titleService,
        private readonly BonusCalculationSnapshots $snapshots,
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(RankBonusFeature::class), 404);

        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'month' => ['nullable', 'date_format:Y-m'],
            'rank' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:pending,credited,reversed,requalification_held,repurchase_wallet_blocked'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $month = $request->query('month');
        $rank = $request->query('rank');
        $status = $request->query('status');

        $rank1Rows = $this->buildQuery($q, $month, 1, $status)
            ->paginate(self::PER_PAGE, ['*'], 'p1')
            ->withQueryString();

        $rankRows = $this->buildQuery($q, $month, (int) $rank >= 2 ? (int) $rank : null, $status, higherRanksOnly: true)
            ->paginate(self::PER_PAGE, ['*'], 'p2')
            ->withQueryString();

        $distributorIds = collect($rank1Rows->items())
            ->merge($rankRows->items())
            ->pluck('distributor_id')
            ->unique()
            ->values()
            ->all();

        // The Arete Center column only exists while the ADC feature is on.
        $adcOn = Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class);

        // One Rank-1 header + formula block per month on view: the filtered
        // month, or every month present among the Rank-1 rows on this page.
        $rank1Months = is_string($month)
            ? [Carbon::parse($month.'-01')]
            : collect($rank1Rows->items())
                ->map(fn (\stdClass $row) => Carbon::parse($row->month_start)->startOfMonth())
                ->unique(fn (Carbon $m) => $m->toDateString())
                ->sortDesc()
                ->values()
                ->all();

        $rank1Blocks = [];
        foreach ($rank1Months as $rank1Month) {
            $snapshot = $this->snapshots->rankBonusMonth($rank1Month);
            if ($snapshot !== null) {
                $rank1Blocks[] = ['date' => $rank1Month, 'rank1' => $snapshot];
            }
        }

        return view('admin.compensation.rb-calculation.index', [
            'rank1Rows' => $rank1Rows,
            'rankRows' => $rankRows,
            'rank1Blocks' => $rank1Blocks,
            'rankNames' => $this->plan->rankNames(),
            'q' => $q ?: null,
            'month' => $month,
            'rank' => $rank,
            'status' => $status,
            'titleService' => $this->titleService,
            'personalBvMap' => $this->batchPersonalBvPaise($distributorIds),
            'adcOn' => $adcOn,
            'areteCenterMap' => $adcOn ? $this->batchAreteCenters($distributorIds) : [],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless(Feature::for(null)->active(RankBonusFeature::class), 404);

        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'month' => ['nullable', 'date_format:Y-m'],
            'rank' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:pending,credited,reversed,requalification_held,repurchase_wallet_blocked'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $month = $request->query('month');
        $rank = $request->query('rank');
        $status = $request->query('status');

        $rows = $this->buildQuery($q, $month, $rank !== null ? (int) $rank : null, $status)->get();

        $distributorIds = $rows->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);

        // The Arete Center column only exists while the ADC feature is on —
        // header and cells drop together so the export stays rectangular.
        $adcOn = Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class);
        $areteCenterMap = $adcOn ? $this->batchAreteCenters($distributorIds) : [];

        $columns = array_merge([
            ['key' => 'sno', 'label' => 'SNo'],
            ['key' => 'adn', 'label' => 'ADN'],
        ], $adcOn ? [
            ['key' => 'arete_center', 'label' => 'Arete Center'],
        ] : [], [
            ['key' => 'name',       'label' => 'Name'],
            ['key' => 'title',      'label' => 'Title'],
            ['key' => 'month',      'label' => 'Month'],
            ['key' => 'rank',       'label' => 'Rank'],
            ['key' => 'rap',        'label' => 'RAP'],
            ['key' => 'aogo',       'label' => 'AO-GO Points'],
            ['key' => 'point_value', 'label' => 'Point Value (Rs)'],
            ['key' => 'gross',      'label' => 'Gross RB (Rs)'],
            ['key' => 'deduction',  'label' => 'Repurchase Deduction (Rs)'],
            ['key' => 'credited',   'label' => 'Credited to Wallet (Rs)'],
            ['key' => 'status',     'label' => 'Status'],
        ]);

        $out = $rows->values()->map(function ($row, int $i) use ($personalBvMap, $adcOn, $areteCenterMap): array {
            $title = $this->titleService->forBvPaise($personalBvMap[$row->distributor_id] ?? 0)->title ?? '';

            return array_merge([
                'sno' => $i + 1,
                'adn' => (string) $row->adn,
            ], $adcOn ? [
                'arete_center' => (string) ($areteCenterMap[$row->distributor_id] ?? ''),
            ] : [], [
                'name' => (string) ($row->full_name ?? ''),
                'title' => $title,
                'month' => Carbon::parse($row->month_start)->format('Y-m'),
                'rank' => $row->rank_number,
                'rap' => $row->rap_points ?? '',
                'aogo' => $row->aogo_points ?? '',
                'point_value' => $row->point_value_paise !== null ? $row->point_value_paise / 100 : '',
                'gross' => $row->gross_paise / 100,
                'deduction' => $row->repurchase_deduction_paise / 100,
                'credited' => $row->net_paise / 100,
                'status' => (string) $row->status,
            ]);
        })->all();

        return ReportExport::respond($request, 'rank-bonus-'.now()->format('Y-m'), $columns, $out);
    }

    private function buildQuery(string $q, ?string $month, ?int $rank, ?string $status, bool $higherRanksOnly = false): Builder
    {
        return DB::table('rank_bonus_results as rbr')
            ->join('distributors as d', 'd.id', '=', 'rbr.distributor_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->when($q, fn ($b) => $b->where(fn ($sub) => $sub
                ->where('d.adn', 'like', "%{$q}%")
                ->orWhere('u.full_name', 'like', "%{$q}%")
            ))
            ->when($month, fn ($b) => $b->where('rbr.month_start', $month.'-01'))
            ->when($higherRanksOnly, fn ($b) => $b->where('rbr.rank_number', '>', 1))
            ->when($rank, fn ($b) => $b->where('rbr.rank_number', $rank))
            ->when($status, fn ($b) => $b->where('rbr.status', $status))
            ->select(
                'rbr.id',
                'rbr.distributor_id',
                'rbr.month_start',
                'rbr.rank_number',
                'rbr.rap_points',
                'rbr.aogo_points',
                'rbr.total_points',
                'rbr.point_value_paise',
                'rbr.gross_paise',
                'rbr.repurchase_deduction_paise',
                'rbr.net_paise',
                'rbr.status',
                'd.adn',
                'u.full_name',
            )
            ->orderByDesc('rbr.month_start')
            ->orderBy('rbr.rank_number')
            ->orderByDesc('rbr.id');
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

    /**
     * Current Arete Center per distributor (open membership: effective_to null).
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
}
