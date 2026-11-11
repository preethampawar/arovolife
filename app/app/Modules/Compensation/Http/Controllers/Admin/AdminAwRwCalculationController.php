<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Services\BonusCalculationSnapshots;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\PersonalBvTitleService;
use App\Modules\Shared\Features\LifetimeAwardsFeature;
use App\Modules\Shared\Support\ReportExport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminAwRwCalculationController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly PersonalBvTitleService $titleService,
        private readonly BonusCalculationSnapshots $snapshots,
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(LifetimeAwardsFeature::class), 404);

        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'in:credited,cancelled,delivered'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $month = $request->query('month');
        $status = $request->query('status');

        $rows = $this->buildQuery($q, $month, $status)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $distributorIds = collect($rows->items())->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);

        // Header + formula blocks: the filtered month, else every month among
        // the rows on this page.
        $monthBlocks = $this->snapshots->awRwMonths($this->snapshots->monthsOnPage($rows->items(), 'triggered_month', $month));

        return view('admin.compensation.aw-rw-calculation.index', [
            'monthBlocks' => $monthBlocks,
            // Client 2026-10-09 (Q1): the awards are funded from a share of
            // each month's BV. Reported only; never gates an award.
            'awardsFund' => $this->snapshots->awardsFund(Carbon::now('Asia/Kolkata')),
            'awardsFundRateBp' => $this->plan->awardsFundRateBp(),
            'rows' => $rows,
            'q' => $q ?: null,
            'month' => $month,
            'status' => $status,
            'titleService' => $this->titleService,
            'personalBvMap' => $personalBvMap,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless(Feature::for(null)->active(LifetimeAwardsFeature::class), 404);

        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'in:credited,cancelled,delivered'],
        ]);

        $q = trim((string) ($request->query('q') ?? ''));
        $month = $request->query('month');
        $status = $request->query('status');

        $rows = $this->buildQuery($q, $month, $status)->get();

        // One TDS rate for the award and the bonuses (user decision
        // 2026-11-11, A3); the tax itself is on the payout line.
        $tdsRate = rtrim(rtrim(number_format($this->plan->tdsRateBp() / 100, 2), '0'), '.').'%';

        $columns = [
            ['key' => 'sno',    'label' => 'S.No.'],
            ['key' => 'month', 'label' => 'Month'],
            ['key' => 'adn',   'label' => 'ADN'],
            ['key' => 'name',  'label' => 'Name'],
            ['key' => 'rank',  'label' => 'Rank'],
            ['key' => 'amount', 'label' => 'Amount (Rs)'],
            ['key' => 'tds_rate', 'label' => 'TDS rate'],
            ['key' => 'status', 'label' => 'Status'],
        ];

        $out = $rows->values()->map(function ($row, int $i) use ($tdsRate): array {
            return [
                'sno' => $i + 1,
                'month' => Carbon::parse($row->achieved_month ?? $row->triggered_month)->format('Y-m'),
                'adn' => (string) $row->adn,
                'name' => (string) ($row->full_name ?? ''),
                'rank' => (string) ($row->rank_name ?? 'Rank '.$row->rank_number),
                'amount' => (int) $row->amount_paise / 100,
                'tds_rate' => $tdsRate,
                'status' => (string) $row->status,
            ];
        })->all();

        return ReportExport::respond($request, 'aw-rw-'.now()->format('Y-m'), $columns, $out);
    }

    private function buildQuery(string $q, ?string $month, ?string $status): Builder
    {
        return DB::table('lifetime_award_milestones as lam')
            ->join('distributors as d', 'd.id', '=', 'lam.distributor_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('rank_tiers as rt', 'rt.rank_number', '=', 'lam.rank_number')
            ->when($q, fn ($b) => $b->where(fn ($sub) => $sub
                ->where('d.adn', 'like', "%{$q}%")
                ->orWhere('u.full_name', 'like', "%{$q}%")
            ))
            ->when($month, fn ($b) => $b->where('lam.triggered_month', $month.'-01'))
            ->when($status, fn ($b) => $b->where('lam.status', $status))
            ->select(
                'lam.id',
                'lam.distributor_id',
                'lam.rank_number',
                'lam.amount_paise',
                'lam.triggered_month',
                'lam.achieved_month',
                'lam.award_description',
                'lam.status',
                'rt.rank_name',
                'd.adn',
                'u.full_name',
            )
            ->orderByDesc('lam.triggered_month')
            ->orderBy('lam.rank_number')
            ->orderByDesc('lam.id');
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
