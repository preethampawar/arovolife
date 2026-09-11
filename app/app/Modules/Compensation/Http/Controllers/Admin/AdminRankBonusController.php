<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Services\BonusCalculationSnapshots;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\RankBonusService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class AdminRankBonusController extends Controller
{
    public function __construct(
        private readonly CompensationPlanSettingsService $plan,
        private readonly BonusCalculationSnapshots $snapshots,
        private readonly RankBonusService $rankBonus,
    ) {}

    public function index(): View
    {
        abort_unless(Feature::for(null)->active(RankBonusFeature::class), 404);

        $months = RankBonusResult::query()
            ->selectRaw('
                month_start,
                COUNT(DISTINCT distributor_id) as qualifier_count,
                SUM(gross_paise) as total_gross_paise,
                SUM(repurchase_deduction_paise) as total_deduction_paise,
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
                MAX(qualifier_count) as qualifier_count,
                MAX(pool_paise) as pool_paise,
                SUM(gross_paise) as total_gross_paise,
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
        $rank1 = $this->snapshots->rankBonusMonth($date);

        // Distributors who reached a rank after the month's pool was frozen.
        // They are refused by the engine — a divided pool is never re-divided —
        // so an admin has to see them rather than discover a silent gap.
        $lateQualifiers = $this->rankBonus->qualifiedAfterFreeze($date);
        $lateAdns = $lateQualifiers === []
            ? collect()
            : Distributor::whereIn('id', array_merge(...array_values($lateQualifiers)))
                ->pluck('adn', 'id');

        return view('admin.compensation.rank-bonus.show', compact(
            'rows', 'rankSummaries', 'date', 'rankNames', 'rank1', 'lateQualifiers', 'lateAdns',
        ));
    }
}
