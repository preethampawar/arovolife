<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Services\PersonalBvTitleService;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

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
    ) {}

    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(RankBonusFeature::class), 404);

        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'month' => ['nullable', 'date_format:Y-m'],
            'rank' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:pending,credited,reversed,requalification_held'],
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

        return view('admin.compensation.rb-calculation.index', [
            'rank1Rows' => $rank1Rows,
            'rankRows' => $rankRows,
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

    public function export(Request $request): Response
    {
        abort_unless(Feature::for(null)->active(RankBonusFeature::class), 404);

        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'month' => ['nullable', 'date_format:Y-m'],
            'rank' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:pending,credited,reversed,requalification_held'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $month = $request->query('month');
        $rank = $request->query('rank');
        $status = $request->query('status');

        $rows = $this->buildQuery($q, $month, $rank !== null ? (int) $rank : null, $status)->get();

        $distributorIds = $rows->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);

        // The Arete Center column only exists while the ADC feature is on —
        // header and cells drop together so the CSV stays rectangular.
        $adcOn = Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class);
        $areteCenterMap = $adcOn ? $this->batchAreteCenters($distributorIds) : [];

        $csv = 'SNo,ADN,'.($adcOn ? 'Arete Center,' : '')."Name,Title,Month,Rank,RAP,AO-GO Points,Point Value (Rs),Gross RB (Rs),TDS (Rs),Net RB (Rs),Status\n";

        foreach ($rows as $i => $row) {
            $title = $this->titleService->forBvPaise($personalBvMap[$row->distributor_id] ?? 0)->title ?? '';
            $csv .= implode(',', array_merge([
                $i + 1,
                $this->csvStr($row->adn),
            ], $adcOn ? [$this->csvStr($areteCenterMap[$row->distributor_id] ?? '')] : [], [
                $this->csvStr($row->full_name ?? ''),
                $this->csvStr($title),
                Carbon::parse($row->month_start)->format('Y-m'),
                $row->rank_number,
                $row->rap_points ?? '',
                $row->aogo_points ?? '',
                $row->point_value_paise !== null ? number_format($row->point_value_paise / 100, 2, '.', '') : '',
                number_format($row->gross_paise / 100, 2, '.', ''),
                number_format($row->tds_paise / 100, 2, '.', ''),
                number_format($row->net_paise / 100, 2, '.', ''),
                $this->csvStr($row->status),
            ]))."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="rank-bonus-'.now()->format('Y-m').'.csv"',
        ]);
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
            ->when($month, fn ($b) => $b->whereRaw("DATE_FORMAT(rbr.month_start, '%Y-%m') = ?", [$month]))
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
                'rbr.tds_paise',
                'rbr.net_paise',
                'rbr.status',
                'd.adn',
                'u.full_name',
            )
            ->orderByDesc('rbr.month_start')
            ->orderBy('rbr.rank_number')
            ->orderByDesc('rbr.id');
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
