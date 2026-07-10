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

final class AdminRankBonusCalculationController extends Controller
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
            'rank' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:pending,credited,reversed'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $month = $request->query('month');
        $rank = $request->query('rank');
        $status = $request->query('status');

        $rows = $this->buildQuery($q, $month, $rank, $status)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $distributorIds = collect($rows->items())->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);

        return view('admin.compensation.rb-calculation.index', [
            'rows' => $rows,
            'q' => $q ?: null,
            'month' => $month,
            'rank' => $rank,
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
            'rank' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:pending,credited,reversed'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $month = $request->query('month');
        $rank = $request->query('rank');
        $status = $request->query('status');

        $rows = $this->buildQuery($q, $month, $rank, $status)->get();

        $distributorIds = $rows->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);

        $csv = "SNo,ADN,Name,Title,Month,Rank,Gross RB (Rs),TDS (Rs),Net RB (Rs),Status\n";

        foreach ($rows as $i => $row) {
            $title = $this->titleService->forBvPaise($personalBvMap[$row->distributor_id] ?? 0)->title ?? '';
            $csv .= implode(',', [
                $i + 1,
                '"'.$row->adn.'"',
                '"'.($row->full_name ?? '').'"',
                '"'.$title.'"',
                Carbon::parse($row->month_start)->format('Y-m'),
                $row->rank_number,
                number_format($row->gross_paise / 100, 2, '.', ''),
                number_format($row->tds_paise / 100, 2, '.', ''),
                number_format($row->net_paise / 100, 2, '.', ''),
                '"'.$row->status.'"',
            ])."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="rank-bonus-'.now()->format('Y-m').'.csv"',
        ]);
    }

    private function buildQuery(string $q, ?string $month, ?string $rank, ?string $status): Builder
    {
        return DB::table('rank_bonus_results as rbr')
            ->join('distributors as d', 'd.id', '=', 'rbr.distributor_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->when($q, fn ($b) => $b->where(fn ($sub) => $sub
                ->where('d.adn', 'like', "%{$q}%")
                ->orWhere('u.full_name', 'like', "%{$q}%")
            ))
            ->when($month, fn ($b) => $b->whereRaw("DATE_FORMAT(rbr.month_start, '%Y-%m') = ?", [$month]))
            ->when($rank, fn ($b) => $b->where('rbr.rank_number', (int) $rank))
            ->when($status, fn ($b) => $b->where('rbr.status', $status))
            ->select(
                'rbr.id',
                'rbr.distributor_id',
                'rbr.month_start',
                'rbr.rank_number',
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
