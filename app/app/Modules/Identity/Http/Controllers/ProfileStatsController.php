<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Services\DistributorIdCardStats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Printable "Profile Stats" page — the same ID-card stats panel shown on the
 * dashboard, rendered on its own page with an arovolife header and contact
 * footer so a distributor can print it or save it as a PDF via the browser's
 * print dialog.
 *
 * Reads from {@see DistributorIdCardStats} so the figures match every other
 * identity surface (dashboard panel, tree Details popup, membership card).
 */
final class ProfileStatsController extends Controller
{
    public function show(DistributorIdCardStats $idCardService): View|RedirectResponse
    {
        $user = Auth::user();

        // Admins have no distributor row; there is no profile to download.
        if ($user !== null && $user->hasRole('admin')) {
            return redirect()->route('admin.dashboard');
        }

        $distributor = $user?->distributor;

        // Registration not yet complete — no stats to issue.
        if ($distributor === null) {
            return redirect()->route('dashboard');
        }

        return view('membership.profile-stats', [
            'idCardStats' => $idCardService->full($distributor),
            'idPhotoUrl' => $idCardService->photoUrl($distributor),
        ]);
    }
}
