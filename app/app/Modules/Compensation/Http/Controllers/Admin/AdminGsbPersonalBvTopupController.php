<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\GsbPersonalBvTopup;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class AdminGsbPersonalBvTopupController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

        $request->validate([
            'date' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:64'],
            'type' => ['nullable', 'in:active,reversed'],
        ]);

        $date = $request->query('date') ? Carbon::parse((string) $request->query('date')) : Carbon::today();
        $q = $request->query('q');
        $type = $request->query('type');

        $query = GsbPersonalBvTopup::with('distributor.user')
            ->whereDate('date', $date->toDateString())
            ->when($type === 'active', fn ($b) => $b->whereNull('reversed_at'))
            ->when($type === 'reversed', fn ($b) => $b->whereNotNull('reversed_at'))
            ->when($q, fn ($b) => $b->whereHas('distributor', fn ($d) => $d->where('adn', 'like', "%{$q}%")))
            ->orderBy('distributor_id')
            ->orderBy('created_at');

        return view('admin.compensation.personal-bv-topups.index', [
            'rows' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'date' => $date,
            'q' => $q,
            'type' => $type,
        ]);
    }

    public function export(Request $request): Response
    {
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

        $request->validate([
            'date' => ['nullable', 'date'],
            'type' => ['nullable', 'in:active,reversed'],
        ]);

        $date = $request->query('date') ? Carbon::parse((string) $request->query('date')) : Carbon::today();
        $type = $request->query('type');

        $rows = GsbPersonalBvTopup::with('distributor.user')
            ->whereDate('date', $date->toDateString())
            ->when($type === 'active', fn ($b) => $b->whereNull('reversed_at'))
            ->when($type === 'reversed', fn ($b) => $b->whereNotNull('reversed_at'))
            ->orderBy('distributor_id')
            ->orderBy('created_at')
            ->get();

        $csv = "Date,ADN,Name,Order ID,BV,Side,Type,Reversed At\n";
        foreach ($rows as $r) {
            $csv .= '"'.$r->date->toDateString().'",'
                .'"'.($r->distributor->adn ?? '').'",'
                .'"'.($r->distributor->user?->full_name ?? '').'",'
                .(int) $r->order_id.','
                .(int) ($r->bv_paise / 100).','
                .'"'.($r->side === 'L' ? 'Left' : 'Right').'",'
                .'"'.($r->reversed_at ? 'Reversed' : 'Topup').'",'
                .'"'.($r->reversed_at?->toDateTimeString() ?? '').'"'."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="gsb-personal-bv-topups-'.$date->toDateString().'.csv"',
        ]);
    }
}
