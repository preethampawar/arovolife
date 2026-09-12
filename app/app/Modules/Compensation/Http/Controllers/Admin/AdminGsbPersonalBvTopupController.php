<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\GsbPersonalBvTopup;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Support\ReportExport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function export(Request $request): StreamedResponse
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

        $columns = [
            ['key' => 'date', 'label' => 'Date'],
            ['key' => 'adn', 'label' => 'ADN'],
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'order_id', 'label' => 'Order ID'],
            ['key' => 'bv', 'label' => 'BV'],
            ['key' => 'side', 'label' => 'Side'],
            ['key' => 'type', 'label' => 'Type'],
            ['key' => 'reversed_at', 'label' => 'Reversed At'],
        ];

        $out = $rows->map(function (GsbPersonalBvTopup $r): array {
            return [
                'date' => $r->date->toDateString(),
                'adn' => (string) ($r->distributor->adn ?? ''),
                'name' => (string) ($r->distributor->user?->full_name ?? ''),
                'order_id' => (int) $r->order_id,
                'bv' => (int) ($r->bv_paise / 100),
                'side' => $r->side === 'L' ? 'Left' : 'Right',
                'type' => $r->reversed_at ? 'Reversed' : 'Topup',
                'reversed_at' => $r->reversed_at?->toDateTimeString() ?? '',
            ];
        })->values()->all();

        return ReportExport::respond($request, 'gsb-personal-bv-topups-'.$date->toDateString(), $columns, $out);
    }
}
