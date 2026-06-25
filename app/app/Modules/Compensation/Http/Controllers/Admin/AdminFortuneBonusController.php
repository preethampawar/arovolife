<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\FortuneBonusParticipant;
use App\Modules\Compensation\Models\FortuneBonusResult;
use App\Modules\Shared\Features\FortuneBonusFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class AdminFortuneBonusController extends Controller
{
    public function index(): View
    {
        abort_unless(Feature::for(null)->active(FortuneBonusFeature::class), 404);

        $months = FortuneBonusResult::query()
            ->selectRaw('
                month_start,
                COUNT(DISTINCT distributor_id) as participant_count,
                SUM(CASE WHEN status = ? THEN net_paise ELSE 0 END) as total_net_paise,
                MAX(credited_at) as credited_at
            ', [FortuneBonusResult::STATUS_CREDITED])
            ->groupBy('month_start')
            ->orderByDesc('month_start')
            ->get();

        return view('admin.compensation.fortune-bonus.index', compact('months'));
    }

    public function show(string $month): View
    {
        abort_unless(Feature::for(null)->active(FortuneBonusFeature::class), 404);

        $date = Carbon::parse($month.'-01');
        $monthStart = $date->toDateString();

        $levelSummaries = FortuneBonusResult::query()
            ->selectRaw('
                matrix_level,
                COUNT(*) as participant_count,
                SUM(gross_paise) as total_gross_paise,
                SUM(tds_paise) as total_tds_paise,
                SUM(net_paise) as total_net_paise
            ')
            ->where('month_start', $monthStart)
            ->groupBy('matrix_level')
            ->orderBy('matrix_level')
            ->get()
            ->keyBy('matrix_level');

        $rows = FortuneBonusParticipant::with('distributor')
            ->where('month_start', $monthStart)
            ->orderBy('position')
            ->paginate(50)
            ->withQueryString();

        $resultsByDistributor = FortuneBonusResult::where('month_start', $monthStart)
            ->get()
            ->keyBy('distributor_id');

        $levelBonusPaise = FortuneBonusParticipant::LEVEL_BONUS_PAISE;

        return view('admin.compensation.fortune-bonus.show', compact(
            'rows', 'levelSummaries', 'date', 'resultsByDistributor', 'levelBonusPaise',
        ));
    }
}
