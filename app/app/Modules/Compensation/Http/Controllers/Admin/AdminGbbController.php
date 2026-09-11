<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\GbbMonthlyPool;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

final class AdminGbbController extends Controller
{
    public function index(): View
    {
        abort_unless(Feature::for(null)->active(GrowthBoosterBonusFeature::class), 404);

        $months = GbbMonthlyResult::query()
            // `year_month` must stay quoted here: YEAR_MONTH is a reserved
            // interval unit in MySQL 8, so a bare reference inside raw SQL is a
            // syntax error. groupBy()/where() are quoted by the query builder,
            // but selectRaw() is passed through verbatim. Backticks are
            // understood by both MySQL and SQLite.
            ->selectRaw('`year_month`, COUNT(*) as distributor_count, SUM(gbb_gross_paise) as total_gross_paise, SUM(repurchase_deduction_paise) as total_deduction_paise, SUM(gbb_net_paise) as total_net_paise, SUM(agp_earned) as total_agp, MAX(credited_at) as credited_at')
            ->where('status', GbbMonthlyResult::STATUS_CREDITED)
            ->groupBy('year_month')
            ->orderByDesc('year_month')
            ->get();

        // A month can be frozen and credit nobody — the pool row is written
        // BEFORE any credit, and an eligible-but-empty month is a legitimate ₹0
        // outcome. Reading only credited results, the index said "engine has not
        // yet run" over two frozen pools (F87), which invites an admin to
        // re-trigger a month the platform refuses to re-freeze.
        $months = $this->withFrozenMonths(
            $months,
            'year_month',
            GbbMonthlyPool::query()->orderByDesc('month_start')->pluck('month_start'),
        );

        return view('admin.compensation.gbb.index', compact('months'));
    }

    /**
     * Append the months that have a frozen pool but no credited result row, as
     * zero rows carrying the same shape the view already renders.
     *
     * @param  iterable<int, object>  $months
     * @param  iterable<int, mixed>  $frozenMonths
     * @return Collection<int, object>
     */
    private function withFrozenMonths(iterable $months, string $monthColumn, iterable $frozenMonths): Collection
    {
        $months = collect($months);

        $credited = $months->map(
            static fn (object $row): string => Carbon::parse((string) $row->{$monthColumn})->toDateString(),
        );

        $extra = collect($frozenMonths)
            ->map(static fn ($month): string => Carbon::parse((string) $month)->toDateString())
            ->unique()
            ->diff($credited)
            ->map(static fn (string $month): object => (object) [
                $monthColumn => $month,
                'distributor_count' => 0,
                'total_agp' => 0,
                'total_gross_paise' => 0,
                'total_deduction_paise' => 0,
                'total_net_paise' => 0,
                'credited_at' => null,
            ]);

        return $months->concat($extra)
            ->sortByDesc(static fn (object $row): string => Carbon::parse((string) $row->{$monthColumn})->toDateString())
            ->values();
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
                SUM(CASE WHEN status = \'credited\' THEN gbb_net_paise ELSE 0 END) as total_net_paise,
                COUNT(*) as distributor_count
            ')
            ->first();

        // Frozen month economics. Null for months run before the pool snapshot
        // existed — the view degrades to "—" rather than recomputing them.
        $pool = GbbMonthlyPool::query()
            ->where('month_start', $date->toDateString())
            ->first();

        return view('admin.compensation.gbb.show', compact('rows', 'summary', 'date', 'pool'));
    }
}
