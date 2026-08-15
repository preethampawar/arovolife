<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Services\PersonalBvTitleService;
use App\Modules\Shared\Features\LifetimeAwardsFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

final class AdminAwRwCalculationController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly PersonalBvTitleService $titleService,
    ) {}

    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(LifetimeAwardsFeature::class), 404);

        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'in:pending,delivered,cancelled'],
            'type' => ['nullable', 'in:goods,cash'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $month = $request->query('month');
        $status = $request->query('status');
        $type = $request->query('type');

        $rows = $this->buildQuery($q, $month, $status, $type)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $distributorIds = collect($rows->items())->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);

        return view('admin.compensation.aw-rw-calculation.index', [
            'rows' => $rows,
            'q' => $q ?: null,
            'month' => $month,
            'status' => $status,
            'type' => $type,
            'titleService' => $this->titleService,
            'personalBvMap' => $personalBvMap,
        ]);
    }

    public function export(Request $request): Response
    {
        abort_unless(Feature::for(null)->active(LifetimeAwardsFeature::class), 404);

        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'in:pending,delivered,cancelled'],
            'type' => ['nullable', 'in:goods,cash'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $month = $request->query('month');
        $status = $request->query('status');
        $type = $request->query('type');

        $rows = $this->buildQuery($q, $month, $status, $type)->get();

        $distributorIds = $rows->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);

        $csv = "SNo,ADN,Name,Title,Rank,Month,Type,Award Description,Cash Reward (Rs),Status\n";

        foreach ($rows as $i => $row) {
            $title = $this->titleService->forBvPaise($personalBvMap[$row->distributor_id] ?? 0)->title ?? '';
            $cashReward = ($row->disbursement_type === 'cash' && $row->net_paise)
                ? number_format($row->net_paise / 100, 2, '.', '')
                : '—';
            $csv .= implode(',', [
                $i + 1,
                $this->csvStr($row->adn),
                $this->csvStr($row->full_name ?? ''),
                $this->csvStr($title),
                $this->csvStr($row->rank_name ?? 'Rank '.$row->rank_number),
                Carbon::parse($row->triggered_month)->format('Y-m'),
                $row->disbursement_type ?? '—',
                $this->csvStr($row->award_description ?? '—'),
                $cashReward,
                $this->csvStr($row->status),
            ])."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="aw-rw-'.now()->format('Y-m').'.csv"',
        ]);
    }

    private function buildQuery(string $q, ?string $month, ?string $status, ?string $type): Builder
    {
        return DB::table('lifetime_award_milestones as lam')
            ->join('distributors as d', 'd.id', '=', 'lam.distributor_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('rank_tiers as rt', 'rt.rank_number', '=', 'lam.rank_number')
            ->when($q, fn ($b) => $b->where(fn ($sub) => $sub
                ->where('d.adn', 'like', "%{$q}%")
                ->orWhere('u.full_name', 'like', "%{$q}%")
            ))
            ->when($month, fn ($b) => $b->whereRaw("DATE_FORMAT(lam.triggered_month, '%Y-%m') = ?", [$month]))
            ->when($status, fn ($b) => $b->where('lam.status', $status))
            ->when($type, fn ($b) => $b->where('lam.disbursement_type', $type))
            ->select(
                'lam.id',
                'lam.distributor_id',
                'lam.rank_number',
                'lam.triggered_month',
                'lam.award_description',
                'lam.disbursement_type',
                'lam.gross_paise',
                'lam.net_paise',
                'lam.status',
                'rt.rank_name',
                'd.adn',
                'u.full_name',
            )
            ->orderByDesc('lam.triggered_month')
            ->orderBy('lam.rank_number')
            ->orderByDesc('lam.id');
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
}
