<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Printable view of the distributor's signed Direct Seller Application — a
 * tabular summary of the identity, contact, KYC and registration details
 * arovolife holds for them. Read-only; mirrors the membership-card print
 * pattern (browser print → save-as-PDF).
 */
final class DirectSellerApplicationController extends Controller
{
    public function show(): View|RedirectResponse
    {
        $user = Auth::user();

        // Admins have no distributor row; route them to their console.
        if ($user !== null && $user->hasRole('admin')) {
            return redirect()->route('admin.dashboard');
        }

        $distributor = $user?->distributor()->with(['sponsor.user'])->first();

        if ($distributor === null) {
            return redirect()->route('dashboard');
        }

        return view('membership.direct-seller-application', [
            'distributor' => $distributor,
            'user' => $user,
            'sponsor' => $distributor->sponsor,
        ]);
    }
}
