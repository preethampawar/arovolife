<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Support\DashboardPanels;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * The admin dashboard shell.
 *
 * This method runs **no query**. It used to compute everything eagerly — seven
 * counts, a joined audit feed with a presenter warm-up per row, a recent
 * registrations list and three inventory alerts — so every visit to the console
 * landing page paid for numbers the viewer might never scroll to.
 *
 * Now it emits one placeholder per panel {@see DashboardPanels::visibleTo()}
 * says this viewer may see, and each panel fetches its own rendered fragment
 * from {@see AdminDashboardPanelController}: on load for the two above the
 * fold, on scroll for the rest.
 *
 * `visibleTo()` runs the permission and feature-flag checks here rather than in
 * the view, so a panel the viewer may not see never reaches the DOM — hiding
 * one in the browser would still have shipped the fact that the module exists.
 */
final class AdminDashboardController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.dashboard', [
            'panels' => DashboardPanels::visibleTo($request->user()),
        ]);
    }
}
