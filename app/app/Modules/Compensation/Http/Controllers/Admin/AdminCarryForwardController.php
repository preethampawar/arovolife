<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Commerce\Support\Bv;
use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Support\Csv;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Laravel\Pennant\Feature;

final class AdminCarryForwardController extends Controller
{
    private const POWER_CF_CAP_PAISE = 45_000_000;

    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

        $this->validateFilters($request);

        return view('admin.compensation.carry-forwards.index', [
            'rows' => $this->filtered($request)->paginate(50)->withQueryString(),
            'cap' => self::POWER_CF_CAP_PAISE,
        ]);
    }

    public function export(Request $request): Response
    {
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

        $this->validateFilters($request);

        /** @var Collection<int, GsbCarryforward> $rows */
        $rows = $this->filtered($request)->get();

        $csv = "ADN,Power-side CF BV,Power Side,Slab-1 Weaker CF BV,Weaker Side\n";
        foreach ($rows as $row) {
            $powerLabel = match ($row->power_side) {
                'L' => 'Left',
                'R' => 'Right',
                default => '',
            };
            $weakerLabel = match ($row->power_side) {
                'L' => 'Right',
                'R' => 'Left',
                default => '',
            };

            $csv .= implode(',', [
                Csv::safe($row->distributor->adn ?? ''),
                Bv::points($row->power_side_bv_paise),
                Csv::safe($powerLabel),
                Bv::points($row->slab1_weaker_bv_paise),
                Csv::safe($weakerLabel),
            ])."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="gsb-carry-forwards-'.now()->toDateString().'.csv"',
        ]);
    }

    private function validateFilters(Request $request): void
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'filter' => ['nullable', 'in:near_cap'],
        ]);
    }

    /**
     * @return Builder<GsbCarryforward>
     */
    private function filtered(Request $request): Builder
    {
        return GsbCarryforward::with('distributor.user')
            ->when(
                $request->query('q'),
                fn ($b) => $b->whereHas('distributor', fn ($d) => $d->where('adn', 'like', '%'.$request->query('q').'%'))
            )
            ->when(
                $request->query('filter') === 'near_cap',
                fn ($b) => $b->where('power_side_bv_paise', '>=', (int) (self::POWER_CF_CAP_PAISE * 0.80))
            )
            ->orderByDesc('power_side_bv_paise');
    }
}
