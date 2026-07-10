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

final class AdminGenosTransactionsController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly PersonalBvTitleService $titleService,
    ) {}

    public function index(Request $request): View
    {
        $request->validate([
            'tab' => ['nullable', 'in:credits,reversals'],
            'q' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $tab = $request->query('tab', 'credits');
        $q = trim((string) ($request->query('q') ?? ''));
        $from = $request->query('from') ? Carbon::parse((string) $request->query('from')) : null;
        $to = $request->query('to') ? Carbon::parse((string) $request->query('to')) : null;

        $rows = $tab === 'reversals'
            ? $this->queryReversals($q, $from, $to)
            : $this->queryCredits($q, $from, $to);

        // Batch personal BV paise → title for visible ancestor_ids.
        $ancestorIds = collect($rows->items())->pluck('ancestor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($ancestorIds);

        return view('admin.compensation.genos-transactions.index', [
            'rows' => $rows,
            'tab' => $tab,
            'q' => $q ?: null,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'titleService' => $this->titleService,
            'personalBvMap' => $personalBvMap,
        ]);
    }

    private function queryCredits(string $q, ?Carbon $from, ?Carbon $to): LengthAwarePaginator
    {
        return DB::table('group_bv_credits as gbc')
            ->join('distributors as anc', 'anc.id', '=', 'gbc.ancestor_id')
            ->leftJoin('users as anc_user', 'anc_user.id', '=', 'anc.user_id')
            ->join('orders as o', 'o.id', '=', 'gbc.order_id')
            ->when($q, fn ($b) => $b->where(fn ($sub) => $sub
                ->where('anc.adn', 'like', "%{$q}%")
                ->orWhere('anc_user.full_name', 'like', "%{$q}%")
            ))
            ->when($from, fn ($b) => $b->where('gbc.date', '>=', $from->toDateString()))
            ->when($to, fn ($b) => $b->where('gbc.date', '<=', $to->toDateString()))
            ->select(
                'gbc.id',
                'gbc.ancestor_id',
                'gbc.order_id',
                'gbc.side',
                'gbc.bv_paise',
                'gbc.debt_consumed_paise',
                'gbc.date',
                'anc.adn as ancestor_adn',
                'anc_user.full_name as ancestor_name',
                'o.created_at as order_date',
            )
            ->orderByDesc('gbc.date')
            ->orderByDesc('gbc.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    private function queryReversals(string $q, ?Carbon $from, ?Carbon $to): LengthAwarePaginator
    {
        return DB::table('group_bv_reversals as gbr')
            ->join('distributors as anc', 'anc.id', '=', 'gbr.ancestor_id')
            ->leftJoin('users as anc_user', 'anc_user.id', '=', 'anc.user_id')
            ->join('orders as o', 'o.id', '=', 'gbr.order_id')
            ->when($q, fn ($b) => $b->where(fn ($sub) => $sub
                ->where('anc.adn', 'like', "%{$q}%")
                ->orWhere('anc_user.full_name', 'like', "%{$q}%")
            ))
            ->when($from, fn ($b) => $b->where('gbr.date', '>=', $from->toDateString()))
            ->when($to, fn ($b) => $b->where('gbr.date', '<=', $to->toDateString()))
            ->select(
                'gbr.id',
                'gbr.ancestor_id',
                'gbr.order_id',
                'gbr.side',
                'gbr.bv_paise',
                'gbr.absorbed_paise',
                'gbr.debt_paise',
                'gbr.date',
                'gbr.created_at as reversed_at',
                'anc.adn as ancestor_adn',
                'anc_user.full_name as ancestor_name',
                'o.created_at as order_date',
            )
            ->orderByDesc('gbr.date')
            ->orderByDesc('gbr.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * @param  int[]  $ancestorIds
     * @return array<int, int> distributor_id → total personal BV paise
     */
    private function batchPersonalBvPaise(array $ancestorIds): array
    {
        if ($ancestorIds === []) {
            return [];
        }

        return DB::table('bv_ledger_entries')
            ->whereIn('distributor_id', $ancestorIds)
            ->where('type', 'accrual')
            ->groupBy('distributor_id')
            ->pluck(DB::raw('SUM(bv_paise)'), 'distributor_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
