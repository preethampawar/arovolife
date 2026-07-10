<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Services\PersonalBvTitleService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AdminGsbCalculationController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly PersonalBvTitleService $titleService,
    ) {}

    public function index(Request $request): View
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', 'in:credited,calculated,repurchase_held,repurchase_suspended,reversed'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $from = $request->query('from') ? Carbon::parse((string) $request->query('from')) : null;
        $to = $request->query('to') ? Carbon::parse((string) $request->query('to')) : null;
        $status = $request->query('status');

        $rows = $this->queryRows($q, $from, $to, $status);

        $distributorIds = collect($rows->items())->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);

        return view('admin.compensation.gsb-calculation.index', [
            'rows' => $rows,
            'q' => $q ?: null,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'status' => $status,
            'titleService' => $this->titleService,
            'personalBvMap' => $personalBvMap,
        ]);
    }

    private function queryRows(string $q, ?Carbon $from, ?Carbon $to, ?string $status): LengthAwarePaginator
    {
        return DB::table('gsb_cutoff_results as gcr')
            ->join('distributors as d', 'd.id', '=', 'gcr.distributor_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->whereNotNull('gcr.slab')
            ->when($q, fn ($b) => $b->where(fn ($sub) => $sub
                ->where('d.adn', 'like', "%{$q}%")
                ->orWhere('u.full_name', 'like', "%{$q}%")
            ))
            ->when($from, fn ($b) => $b->where('gcr.cutoff_date', '>=', $from->toDateString()))
            ->when($to, fn ($b) => $b->where('gcr.cutoff_date', '<=', $to->toDateString()))
            ->when($status, fn ($b) => $b->where('gcr.status', $status))
            ->select(
                'gcr.id',
                'gcr.distributor_id',
                'gcr.cutoff_date',
                'gcr.slab',
                'gcr.weaker_bv_paise',
                'gcr.left_bv_paise',
                'gcr.right_bv_paise',
                'gcr.gross_gsb_paise',
                'gcr.net_gsb_paise',
                'gcr.tds_paise',
                'gcr.status',
                'd.adn',
                'u.full_name',
            )
            ->orderByDesc('gcr.cutoff_date')
            ->orderByDesc('gcr.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
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
