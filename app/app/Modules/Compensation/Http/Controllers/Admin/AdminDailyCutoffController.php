<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\PersonalBvTitleService;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

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
            'status' => ['nullable', 'in:credited,reversed,failed,no_match,frozen,below_600bv,calculated'],
            'q' => ['nullable', 'string', 'max:64'],
        ]);

        $date = $request->query('date') ? Carbon::parse((string) $request->query('date')) : Carbon::today();
        $status = $request->query('status');
        $q = $request->query('q');

        $query = GsbCutoffResult::with('distributor.user')
            ->where('cutoff_date', $date->toDateString())
            ->when($status, fn ($b) => $b->where('status', $status))
            ->when($q, fn ($b) => $b->whereHas('distributor', fn ($d) => $d->where('adn', 'like', "%{$q}%")))
            ->orderByRaw("CASE status WHEN 'failed' THEN 0 WHEN 'credited' THEN 1 WHEN 'no_match' THEN 2 WHEN 'below_600bv' THEN 3 WHEN 'frozen' THEN 4 WHEN 'calculated' THEN 5 ELSE 6 END");

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
            ->orderByRaw("CASE status WHEN 'failed' THEN 0 WHEN 'credited' THEN 1 WHEN 'no_match' THEN 2 WHEN 'below_600bv' THEN 3 WHEN 'frozen' THEN 4 WHEN 'calculated' THEN 5 ELSE 6 END")
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.compensation.daily-cutoffs.show', [
            'rows' => $rows,
            'parsed' => $parsed,
            'slabThresholdTip' => $this->plan->gsbSlabThresholdSummary(),
        ]);
    }

    public function export(Request $request): Response
    {
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

        $request->validate([
            'date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:credited,reversed,failed,no_match,frozen,below_600bv,calculated'],
        ]);
        $date = $request->query('date') ? Carbon::parse((string) $request->query('date')) : Carbon::today();
        $status = $request->query('status');

        $rows = GsbCutoffResult::with('distributor.user')
            ->where('cutoff_date', $date->toDateString())
            ->when($status, fn ($b) => $b->where('status', $status))
            ->get();

        $csv = "ADN,Name,Left BV,Right BV,Slab,Gross GSB (Rs),Admin Charge (Rs),TDS (Rs),Net GSB (Rs),Status\n";
        foreach ($rows as $r) {
            $csv .= '"'.($r->distributor->adn ?? '').'",'
                .'"'.($r->distributor->user?->full_name ?? '').'",'
                .(int) ($r->left_bv_paise / 100).','
                .(int) ($r->right_bv_paise / 100).','
                .'"'.($r->slab ?? '').'",'
                .number_format($r->gross_gsb_paise / 100, 2, '.', '').','
                .number_format($r->admin_charge_paise / 100, 2, '.', '').','
                .number_format($r->tds_paise / 100, 2, '.', '').','
                .number_format($r->net_gsb_paise / 100, 2, '.', '').','
                .'"'.$r->status.'"'."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="gsb-cutoff-'.$date->toDateString().'.csv"',
        ]);
    }
}
