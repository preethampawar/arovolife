<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Services\PersonalBvTitleService;
use App\Modules\Shared\Support\ReportExport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function export(Request $request): StreamedResponse
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

        if ($tab === 'reversals') {
            $rows = $this->buildReversalQuery($q, $from, $to)->get();
            $ancestorIds = $rows->pluck('ancestor_id')->unique()->values()->all();
            $personalBvMap = $this->batchPersonalBvPaise($ancestorIds);

            $columns = [
                ['key' => 'sno', 'label' => 'SNo'],
                ['key' => 'adn', 'label' => 'ADN'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'title', 'label' => 'Title'],
                ['key' => 'side', 'label' => 'Side'],
                ['key' => 'order_id', 'label' => 'Order ID'],
                ['key' => 'order_date', 'label' => 'Order Date'],
                ['key' => 'bv', 'label' => 'BV'],
                ['key' => 'reversal_date', 'label' => 'Reversal Date'],
                ['key' => 'absorbed', 'label' => 'BV Absorbed'],
                ['key' => 'debt', 'label' => 'Forward Debt BV'],
            ];

            $out = $rows->values()->map(function ($row, int $i) use ($personalBvMap): array {
                $title = $this->titleService->forBvPaise($personalBvMap[$row->ancestor_id] ?? 0)->title ?? '';

                return [
                    'sno' => $i + 1,
                    'adn' => (string) $row->ancestor_adn,
                    'name' => (string) ($row->ancestor_name ?? ''),
                    'title' => $title,
                    'side' => $row->side,
                    'order_id' => '#'.$row->order_id,
                    'order_date' => $row->order_date ? Carbon::parse($row->order_date)->toDateString() : '',
                    'bv' => (int) round($row->bv_paise / 100),
                    'reversal_date' => $row->reversed_at ? Carbon::parse($row->reversed_at)->toDateString() : '',
                    'absorbed' => (int) round($row->absorbed_paise / 100),
                    'debt' => (int) round($row->debt_paise / 100),
                ];
            })->all();

            return ReportExport::respond($request, 'genos-bv-reversals-'.now()->toDateString(), $columns, $out);
        }

        $rows = $this->buildCreditQuery($q, $from, $to)->get();
        $ancestorIds = $rows->pluck('ancestor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($ancestorIds);

        $columns = [
            ['key' => 'sno', 'label' => 'SNo'],
            ['key' => 'adn', 'label' => 'ADN'],
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'title', 'label' => 'Title'],
            ['key' => 'side', 'label' => 'Side'],
            ['key' => 'order_id', 'label' => 'Order ID'],
            ['key' => 'order_date', 'label' => 'Order Date'],
            ['key' => 'bv', 'label' => 'BV'],
            ['key' => 'debt_consumed', 'label' => 'Debt Consumed BV'],
        ];

        $out = $rows->values()->map(function ($row, int $i) use ($personalBvMap): array {
            $title = $this->titleService->forBvPaise($personalBvMap[$row->ancestor_id] ?? 0)->title ?? '';

            return [
                'sno' => $i + 1,
                'adn' => (string) $row->ancestor_adn,
                'name' => (string) ($row->ancestor_name ?? ''),
                'title' => $title,
                'side' => $row->side,
                'order_id' => '#'.$row->order_id,
                'order_date' => $row->order_date ? Carbon::parse($row->order_date)->toDateString() : '',
                'bv' => (int) round($row->bv_paise / 100),
                'debt_consumed' => (int) round($row->debt_consumed_paise / 100),
            ];
        })->all();

        return ReportExport::respond($request, 'genos-bv-credits-'.now()->toDateString(), $columns, $out);
    }

    private function queryCredits(string $q, ?Carbon $from, ?Carbon $to): LengthAwarePaginator
    {
        return $this->buildCreditQuery($q, $from, $to)
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    private function queryReversals(string $q, ?Carbon $from, ?Carbon $to): LengthAwarePaginator
    {
        return $this->buildReversalQuery($q, $from, $to)
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    private function buildCreditQuery(string $q, ?Carbon $from, ?Carbon $to): Builder
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
            ->orderByDesc('gbc.id');
    }

    private function buildReversalQuery(string $q, ?Carbon $from, ?Carbon $to): Builder
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
            ->orderByDesc('gbr.id');
    }

    /**
     * `bv_paise` is signed (+ accrual, − reversal), so the unfiltered SUM is the
     * net personal BV. Filtering to accruals would overstate the title of a
     * distributor whose orders were later refunded.
     *
     * @param  int[]  $ancestorIds
     * @return array<int, int> distributor_id → net personal BV paise
     */
    private function batchPersonalBvPaise(array $ancestorIds): array
    {
        if ($ancestorIds === []) {
            return [];
        }

        return DB::table('bv_ledger_entries')
            ->whereIn('distributor_id', $ancestorIds)
            ->groupBy('distributor_id')
            ->pluck(DB::raw('SUM(bv_paise)'), 'distributor_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
