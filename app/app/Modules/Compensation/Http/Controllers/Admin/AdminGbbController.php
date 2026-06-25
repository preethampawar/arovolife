<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class AdminGbbController extends Controller
{
    public function index(): View
    {
        abort_unless(Feature::for(null)->active(GrowthBoosterBonusFeature::class), 404);

        $months = GbbMonthlyResult::query()
            ->selectRaw('year_month, COUNT(*) as distributor_count, SUM(gbb_net_paise) as total_net_paise, SUM(agp_earned) as total_agp, MAX(credited_at) as credited_at')
            ->where('status', GbbMonthlyResult::STATUS_CREDITED)
            ->groupBy('year_month')
            ->orderByDesc('year_month')
            ->get();

        return view('admin.compensation.gbb.index', compact('months'));
    }

    public function show(string $month): View
    {
        abort_unless(Feature::for(null)->active(GrowthBoosterBonusFeature::class), 404);

        $date = Carbon::parse($month.'-01');

        $rows = GbbMonthlyResult::with('distributor')
            ->where('year_month', $date->toDateString())
            ->orderByDesc('agp_earned')
            ->paginate(50)
            ->withQueryString();

        $summary = GbbMonthlyResult::where('year_month', $date->toDateString())
            ->selectRaw('
                SUM(agp_earned) as total_agp,
                MAX(company_turnover_paise) as company_turnover_paise,
                MAX(pool_paise) as pool_paise,
                MAX(total_pool_agp) as total_pool_agp,
                SUM(gbb_gross_paise) as total_gross_paise,
                SUM(tds_paise) as total_tds_paise,
                SUM(gbb_net_paise) as total_net_paise,
                COUNT(*) as distributor_count
            ')
            ->first();

        return view('admin.compensation.gbb.show', compact('rows', 'summary', 'date'));
    }
}
