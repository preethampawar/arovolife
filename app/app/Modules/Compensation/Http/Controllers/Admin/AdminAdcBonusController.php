<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\AdcBonusResult;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class AdminAdcBonusController extends Controller
{
    public function index(): View
    {
        abort_unless(Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class), 404);

        $months = AdcBonusResult::query()
            ->selectRaw('
                month_start,
                COUNT(DISTINCT center_id) as center_count,
                SUM(CASE WHEN status = ? THEN net_paise ELSE 0 END) as total_net_paise,
                MAX(credited_at) as credited_at
            ', [AdcBonusResult::STATUS_CREDITED])
            ->groupBy('month_start')
            ->orderByDesc('month_start')
            ->get();

        return view('admin.compensation.adc-bonus.index', compact('months'));
    }

    public function show(string $month): View
    {
        abort_unless(Feature::for(null)->active(AreteDevelopmentCenterBonusFeature::class), 404);

        $date = Carbon::parse($month.'-01');

        $results = AdcBonusResult::with('center', 'distributor')
            ->where('month_start', $date->toDateString())
            ->orderByDesc('gross_paise')
            ->paginate(50)
            ->withQueryString();

        return view('admin.compensation.adc-bonus.show', compact('results', 'date'));
    }
}
