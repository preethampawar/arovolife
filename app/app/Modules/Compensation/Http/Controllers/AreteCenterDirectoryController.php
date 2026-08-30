<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers;

use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Shared\Support\IndianStates;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * Members-only directory of active Arete Development Centres (spec §F).
 *
 * Signed-in distributors (and admin staff) only: distributor-run centres list a personal
 * contact number, which is personal data under the DPDP Act — a public
 * page would publish it to anyone. Shows no owner ADN, member counts or
 * earnings (hard rule 3).
 */
final class AreteCenterDirectoryController extends Controller
{
    public function index(Request $request): View
    {
        // Members, plus back-office staff (who need to see exactly what a
        // member sees). Guests are turned away before this by `auth`.
        $user = $request->user();
        abort_unless($user !== null && ($user->distributor !== null || $user->isStaff()), 403);

        $centreId = (int) $request->query('centre', 0);
        $state = (string) $request->query('state', '');
        $city = trim((string) $request->query('city', ''));

        if ($state !== '' && ! in_array($state, IndianStates::all(), true)) {
            $state = '';
        }

        $active = AreteCenter::query()->active();

        $centreOptions = (clone $active)->orderBy('name')->get(['id', 'name']);
        $stateOptions = (clone $active)->whereNotNull('state')->distinct()->orderBy('state')->pluck('state');
        $cityOptions = (clone $active)
            ->when($state !== '', fn ($q) => $q->where('state', $state))
            ->whereNotNull('city')->distinct()->orderBy('city')->pluck('city');

        $centers = (clone $active)
            ->when($centreId > 0, fn ($q) => $q->whereKey($centreId))
            ->when($state !== '', fn ($q) => $q->where('state', $state))
            ->when($city !== '', fn ($q) => $q->where('city', $city))
            ->orderByRaw('CASE WHEN state IS NULL THEN 1 ELSE 0 END')
            ->orderBy('state')->orderBy('city')->orderBy('name')
            ->get();

        return view('my.arete-centre.directory', [
            'centers' => $centers,
            'centreOptions' => $centreOptions,
            'stateOptions' => $stateOptions,
            'cityOptions' => $cityOptions,
            'filters' => ['centre' => $centreId, 'state' => $state, 'city' => $city],
        ]);
    }
}
