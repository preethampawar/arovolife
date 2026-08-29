<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\RankBonusMonthSnapshot;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class AdminRankBonusController extends Controller
{
    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
        private readonly RankBonusMonthSnapshot $snapshot,
    ) {}

    public function index(): View
    {
        abort_unless(Feature::for(null)->active(RankBonusFeature::class), 404);

        $months = RankBonusResult::query()
            ->selectRaw('
                month_start,
                COUNT(DISTINCT distributor_id) as qualifier_count,
                SUM(net_paise) as total_net_paise,
                MAX(credited_at) as credited_at
            ')
            ->where('status', RankBonusResult::STATUS_CREDITED)
            ->groupBy('month_start')
            ->orderByDesc('month_start')
            ->get();

        return view('admin.compensation.rank-bonus.index', compact('months'));
    }

    public function show(string $month): View
    {
        abort_unless(Feature::for(null)->active(RankBonusFeature::class), 404);

        $date = Carbon::parse($month.'-01');

        $rankSummaries = RankBonusResult::query()
            ->selectRaw('
                rank_number,
                COUNT(*) as qualifier_count,
                MAX(pool_paise) as pool_paise,
                SUM(gross_paise) as total_gross_paise,
                SUM(admin_charge_paise) as total_admin_paise,
                SUM(tds_paise) as total_tds_paise,
                SUM(net_paise) as total_net_paise
            ')
            ->where('month_start', $date->toDateString())
            ->groupBy('rank_number')
            ->orderBy('rank_number')
            ->get()
            ->keyBy('rank_number');

        $rows = RankBonusResult::with('distributor')
            ->where('month_start', $date->toDateString())
            ->orderBy('rank_number')
            ->orderByDesc('gross_paise')
            ->paginate(50)
            ->withQueryString();

        $rankNames = $this->plan->rankNames();
        $rank1 = $this->snapshot->rank1($date);

        return view('admin.compensation.rank-bonus.show', compact('rows', 'rankSummaries', 'date', 'rankNames', 'rank1'));
    }
}
