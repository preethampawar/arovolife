<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Services\DistributorIdCardStats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Distributor membership card — a printable front/back ID card the
 * distributor can view, print, or save as a 2-page PDF (front = page 1,
 * back = page 2) via the browser's print dialog.
 *
 * Reads from {@see DistributorIdCardStats} so the name / ADN / join date
 * match every other identity surface (dashboard panel, tree Details popup).
 */
final class MembershipCardController extends Controller
{
    public function show(DistributorIdCardStats $idCardService): View|RedirectResponse
    {
        $user = Auth::user();

        // Admins have no distributor row; they have no membership card.
        if ($user !== null && $user->hasRole('admin')) {
            return redirect()->route('admin.dashboard');
        }

        $distributor = $user?->distributor;

        // Registration not yet complete — no card to issue.
        if ($distributor === null) {
            return redirect()->route('dashboard');
        }

        return view('membership.card', [
            'stats' => $idCardService->full($distributor),
            'photoUrl' => $idCardService->photoUrl($distributor),
        ]);
    }
}
