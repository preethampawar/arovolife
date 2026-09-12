<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Commerce\Support\Bv;
use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Support\ReportExport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function export(Request $request): StreamedResponse
    {
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

        $this->validateFilters($request);

        /** @var Collection<int, GsbCarryforward> $rows */
        $rows = $this->filtered($request)->get();

        $columns = [
            ['key' => 'adn', 'label' => 'ADN'],
            ['key' => 'power_cf_bv', 'label' => 'Power-side CF BV'],
            ['key' => 'power_side', 'label' => 'Power Side'],
            ['key' => 'weaker_cf_bv', 'label' => 'Slab-1 Weaker CF BV'],
            ['key' => 'weaker_side', 'label' => 'Weaker Side'],
        ];

        $out = $rows->map(function (GsbCarryforward $row): array {
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

            return [
                'adn' => (string) ($row->distributor->adn ?? ''),
                'power_cf_bv' => Bv::points($row->power_side_bv_paise),
                'power_side' => $powerLabel,
                'weaker_cf_bv' => Bv::points($row->slab1_weaker_bv_paise),
                'weaker_side' => $weakerLabel,
            ];
        })->values()->all();

        return ReportExport::respond($request, 'gsb-carry-forwards-'.now()->toDateString(), $columns, $out);
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
