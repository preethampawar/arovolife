<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Services\CompanyBvDistributionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/**
 * Compensation → BV Distribution: a period's company BV split across the
 * bonuses at their configured rates, as a chart and a table.
 */
final class AdminBvDistributionController extends Controller
{
    private const PERIODS = ['day', 'week', 'month', 'year'];

    public function __invoke(Request $request, CompanyBvDistributionService $service): View
    {
        $request->validate([
            'period' => ['nullable', 'in:'.implode(',', self::PERIODS)],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $period = (string) $request->query('period', 'month');
        $anchor = $request->filled('date')
            ? Carbon::parse((string) $request->query('date'), 'Asia/Kolkata')
            : Carbon::now('Asia/Kolkata');

        // Weeks follow the plan's Wednesday-to-Tuesday earning week.
        [$from, $to] = match ($period) {
            'day' => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay()],
            'week' => [$anchor->copy()->startOfWeek(Carbon::WEDNESDAY), $anchor->copy()->endOfWeek(Carbon::TUESDAY)],
            'year' => [$anchor->copy()->startOfYear(), $anchor->copy()->endOfYear()],
            default => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()],
        };

        return view('admin.compensation.bv-distribution.index', [
            'distribution' => $service->forPeriod($from, $to),
            'period' => $period,
            'date' => $anchor->toDateString(),
        ]);
    }
}
