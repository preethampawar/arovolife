<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\GsbCarryforward;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AdminCarryForwardController extends Controller
{
    private const POWER_CF_CAP_PAISE = 45_000_000;

    public function index(Request $request): View
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'filter' => ['nullable', 'in:near_cap'],
        ]);

        $query = GsbCarryforward::with('distributor.user')
            ->when(
                $request->query('q'),
                fn ($b) => $b->whereHas('distributor', fn ($d) => $d->where('adn', 'like', '%'.$request->query('q').'%'))
            )
            ->when(
                $request->query('filter') === 'near_cap',
                fn ($b) => $b->where('power_side_bv_paise', '>=', (int) (self::POWER_CF_CAP_PAISE * 0.80))
            )
            ->orderByDesc('power_side_bv_paise');

        return view('admin.compensation.carry-forwards.index', [
            'rows' => $query->paginate(50)->withQueryString(),
            'cap' => self::POWER_CF_CAP_PAISE,
        ]);
    }
}
