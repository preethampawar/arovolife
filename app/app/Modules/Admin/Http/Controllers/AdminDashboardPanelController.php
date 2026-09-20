<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Services\DashboardPanelData;
use App\Modules\Admin\Support\DashboardPanels;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
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
            'commerce' => view('admin.dashboard.panels.commerce', [...$this->commerce($user, $data), ...$common]),
            'inventory' => view('admin.dashboard.panels.inventory', [...$data->inventory(), ...$common]),
            'compensation' => view('admin.dashboard.panels.compensation', [...$this->compensation($data), ...$common]),
            'people' => view('admin.dashboard.panels.people', [...$data->people(), ...$common]),
            default => abort(404),
        };
    }

    /**
     * Revenue and the order pipeline, on one card.
     *
     * The card itself is open to every admin, because the pipeline always was.
     * Revenue is not: `sales.report.view` gates the sales report, so it gates
     * the same numbers here. A viewer without it is handed `null` rather than
     * figures the view hides — nothing is computed, and nothing reaches their
     * browser to be un-hidden.
     *
     * @return array<string, mixed>
     */
    private function commerce(User $user, DashboardPanelData $data): array
    {
        $sales = $user->can('sales.report.view') ? $data->sales() : null;
        $orders = $data->orders();

        return [
            'sales' => $sales,
            'orders' => $orders,
            'generated_at' => $this->oldest($sales, $orders),
        ];
    }

    /**
     * Payouts, held money, what the plan cost this month, and whether the
     * engines that produced all three actually ran. One gate — `finance.record`
     * — covers every part, which is what lets them share a card.
     *
     * @return array<string, mixed>
     */
    private function compensation(DashboardPanelData $data): array
    {
        $money = $data->money();
        $engines = $data->engines();

        return [
            'money' => $money,
            'engines' => $engines,
            // Promoted out of the money payload: it is the only Carbon the
            // view needs by name, and reaching two levels in for it reads
            // worse than the one variable it becomes.
            'stuck_since' => $money['stuck_since'],
            'generated_at' => $this->oldest($money, $engines),
        ];
    }

    /**
     * The "as of" stamp for a card built from more than one payload.
     *
     * The oldest of them, never the newest: each is cached on its own clock,
     * and a card is only as fresh as its stalest number.
     *
     * @param  array<string, mixed>|null  ...$payloads
     */
    private function oldest(?array ...$payloads): Carbon
    {
        /** @var list<Carbon> $stamps */
        $stamps = [];

        foreach ($payloads as $payload) {
            if ($payload !== null && $payload['generated_at'] instanceof Carbon) {
                $stamps[] = $payload['generated_at'];
            }
        }

        return $stamps === [] ? Carbon::now() : min($stamps);
    }
}
