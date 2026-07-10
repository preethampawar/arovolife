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

final class AdminFortuneBonusCalculationController extends Controller
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
            'status' => ['nullable', 'in:pending,credited,skipped'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $month = $request->query('month');
        $status = $request->query('status');

        $rows = $this->buildQuery($q, $month, $status)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $distributorIds = collect($rows->items())->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);

        return view('admin.compensation.fb-calculation.index', [
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
            'status' => ['nullable', 'in:pending,credited,skipped'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $month = $request->query('month');
        $status = $request->query('status');

        $rows = $this->buildQuery($q, $month, $status)->get();

        $distributorIds = $rows->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);

        $csv = "SNo,ADN,Name,Title,Rank (Tier),Month,Matrix Level,Gross FB (Rs),TDS (Rs),Net FB (Rs),Status\n";

        foreach ($rows as $i => $row) {
            $title = $this->titleService->forBvPaise($personalBvMap[$row->distributor_id] ?? 0)->title ?? '';
            $csv .= implode(',', [
                $i + 1,
                '"'.$row->adn.'"',
                '"'.($row->full_name ?? '').'"',
                '"'.$title.'"',
                '"'.($row->eligibility_tier ?? '—').'"',
                Carbon::parse($row->month_start)->format('Y-m'),
                $row->matrix_level,
                number_format($row->gross_paise / 100, 2, '.', ''),
                number_format($row->tds_paise / 100, 2, '.', ''),
                number_format($row->net_paise / 100, 2, '.', ''),
                '"'.$row->status.'"',
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
            ->when($month, fn ($b) => $b->whereRaw("DATE_FORMAT(fbr.month_start, '%Y-%m') = ?", [$month]))
            ->when($status, fn ($b) => $b->where('fbr.status', $status))
            ->select(
                'fbr.id',
                'fbr.distributor_id',
                'fbr.month_start',
                'fbr.matrix_level',
                'fbr.gross_paise',
                'fbr.tds_paise',
                'fbr.net_paise',
                'fbr.status',
                'fbp.eligibility_tier',
                'd.adn',
                'u.full_name',
            )
            ->orderByDesc('fbr.month_start')
            ->orderBy('fbr.matrix_level')
            ->orderByDesc('fbr.id');
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
