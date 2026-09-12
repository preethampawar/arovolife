<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\PersonalBvTitleService;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Support\ReportExport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminDailyCutoffController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly PersonalBvTitleService $titleService,
        private readonly CompensationPlanSettingsService $plan,
    ) {}

    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

        $request->validate([
            'date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:credited,reversed,failed,no_match,frozen,below_600bv,calculated,repurchase_forfeited'],
            'q' => ['nullable', 'string', 'max:64'],
        ]);

        $date = $request->query('date') ? Carbon::parse((string) $request->query('date')) : Carbon::today();
        $status = $request->query('status');
        $q = $request->query('q');

        $query = GsbCutoffResult::with('distributor.user')
            ->where('cutoff_date', $date->toDateString())
            ->when($status, fn ($b) => $b->where('status', $status))
            ->when($q, fn ($b) => $b->whereHas('distributor', fn ($d) => $d->where('adn', 'like', "%{$q}%")))
            ->orderByRaw("CASE status WHEN 'failed' THEN 0 WHEN 'credited' THEN 1 WHEN 'repurchase_forfeited' THEN 2 WHEN 'no_match' THEN 3 WHEN 'below_600bv' THEN 4 WHEN 'frozen' THEN 5 WHEN 'calculated' THEN 6 ELSE 7 END");

        $rows = $query->paginate(self::PER_PAGE)->withQueryString();

        $distributorIds = $rows->pluck('distributor_id')->all();
        $personalBvMap = BvLedgerEntry::query()
            ->whereIn('distributor_id', $distributorIds)
            ->selectRaw('distributor_id, SUM(bv_paise) as total')
            ->groupBy('distributor_id')
            ->pluck('total', 'distributor_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $titleMap = collect($distributorIds)->mapWithKeys(function (int $id) use ($personalBvMap): array {
            $title = $this->titleService->forBvPaise($personalBvMap[$id] ?? 0)->title;

            return [$id => $title];
        })->all();

        return view('admin.compensation.daily-cutoffs.index', [
            'rows' => $rows,
            'date' => $date,
            'status' => $status,
            'q' => $q,
            'titleMap' => $titleMap,
            'slabThresholdTip' => $this->plan->gsbSlabThresholdSummary(),
        ]);
    }

    public function show(string $date): View
    {
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

        $parsed = Carbon::parse($date);

        $rows = GsbCutoffResult::with('distributor.user')
            ->where('cutoff_date', $parsed->toDateString())
            ->orderByRaw("CASE status WHEN 'failed' THEN 0 WHEN 'credited' THEN 1 WHEN 'repurchase_forfeited' THEN 2 WHEN 'no_match' THEN 3 WHEN 'below_600bv' THEN 4 WHEN 'frozen' THEN 5 WHEN 'calculated' THEN 6 ELSE 7 END")
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.compensation.daily-cutoffs.show', [
            'rows' => $rows,
            'parsed' => $parsed,
            'slabThresholdTip' => $this->plan->gsbSlabThresholdSummary(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

        $request->validate([
            'date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:credited,reversed,failed,no_match,frozen,below_600bv,calculated,repurchase_forfeited'],
        ]);
        $date = $request->query('date') ? Carbon::parse((string) $request->query('date')) : Carbon::today();
        $status = $request->query('status');

        $rows = GsbCutoffResult::with('distributor.user')
            ->where('cutoff_date', $date->toDateString())
            ->when($status, fn ($b) => $b->where('status', $status))
            ->get();

        $columns = [
            ['key' => 'adn', 'label' => 'ADN'],
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'left_bv', 'label' => 'Left BV'],
            ['key' => 'right_bv', 'label' => 'Right BV'],
            ['key' => 'slab', 'label' => 'Slab'],
            ['key' => 'gross_gsb', 'label' => 'Gross GSB (Rs)'],
            ['key' => 'deduction', 'label' => 'Repurchase Deduction (Rs)'],
            ['key' => 'credited', 'label' => 'Credited to Wallet (Rs)'],
            ['key' => 'status', 'label' => 'Status'],
        ];

        $out = $rows->map(function (GsbCutoffResult $r): array {
            return [
                'adn' => (string) ($r->distributor->adn ?? ''),
                'name' => (string) ($r->distributor->user?->full_name ?? ''),
                'left_bv' => (int) ($r->left_bv_paise / 100),
                'right_bv' => (int) ($r->right_bv_paise / 100),
                'slab' => (string) ($r->slab ?? ''),
                'gross_gsb' => $r->gross_gsb_paise / 100,
                'deduction' => $r->repurchase_deduction_paise / 100,
                'credited' => $r->net_gsb_paise / 100,
                'status' => (string) $r->status,
            ];
        })->values()->all();

        return ReportExport::respond($request, 'gsb-cutoff-'.$date->toDateString(), $columns, $out);
    }
}
