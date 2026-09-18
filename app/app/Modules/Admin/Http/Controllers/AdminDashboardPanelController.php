<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Services\DashboardPanelData;
use App\Modules\Admin\Support\DashboardPanels;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Laravel\Pennant\Feature;

/**
 * Serves one dashboard panel as a rendered HTML fragment.
 *
 * The dashboard shell issues no query at all; every number on `/admin` arrives
 * through here, one request per panel, fetched on load for the panels above
 * the fold and on scroll for the rest.
 *
 * **Fragments rather than JSON**, deliberately. Blade keeps ownership of the
 * markup and of `IndianNumber` formatting — the project rule that every
 * displayed number goes through it cannot hold if a second copy of the
 * formatting lives in JavaScript. It also means a panel the viewer may not see
 * is never serialised anywhere near their browser.
 *
 * Every gate here is default-deny, and the order matters:
 *
 * 1. an unknown key is a 404 (the route's `whereIn` catches most of them first);
 * 2. a feature-flagged panel whose flag is off is a **404, not a 403** — a 403
 *    would confirm the module exists, and a flag-off module leaves no trace;
 * 3. only then the permission, which is the ability that already gates the
 *    panel's source data rather than a new dashboard-specific one.
 */
final class AdminDashboardPanelController extends Controller
{
    public function show(Request $request, string $panel, DashboardPanelData $data): View
    {
        abort_unless(DashboardPanels::exists($panel), 404);

        $meta = DashboardPanels::PANELS[$panel];

        abort_if(
            $meta['feature'] !== null && ! Feature::for(null)->active($meta['feature']),
            404,
        );

        /** @var User $user */
        $user = $request->user();

        abort_unless($meta['permission'] === null || $user->can($meta['permission']), 403);

        $common = ['panelKey' => $panel, 'panelTitle' => $meta['title']];

        // Each arm names its view literally rather than composing the path from
        // `$panel`. A composed path is a string the analyser cannot check, so a
        // panel registered with no view behind it would only surface as a
        // 500 in front of an admin; spelled out, a missing view is a static
        // analysis failure before it is ever deployed.
        //
        // `default` is unreachable — `exists()` above already rejected anything
        // not in the registry — but it keeps the match total, so adding a panel
        // to the registry without wiring it here fails loudly rather than
        // falling through.
        return match ($panel) {
            'attention' => view('admin.dashboard.panels.attention', [...$data->attention($user), ...$common]),
            'sales' => view('admin.dashboard.panels.sales', [...$data->sales(), ...$common]),
            'orders' => view('admin.dashboard.panels.orders', [...$data->orders(), ...$common]),
            'inventory' => view('admin.dashboard.panels.inventory', [...$data->inventory(), ...$common]),
            'money' => view('admin.dashboard.panels.money', [...$data->money(), ...$common]),
            'engines' => view('admin.dashboard.panels.engines', [...$data->engines(), ...$common]),
            'people' => view('admin.dashboard.panels.people', [...$data->people(), ...$common]),
            default => abort(404),
        };
    }
}
