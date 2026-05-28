<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Placeholder page for TDS / tax statements. The actual TDS generation
 * pipeline (quarterly Form 26AS reconciliation, downloadable PDFs) lands
 * in a later phase; this page exists so the dashboard "Documents" card
 * has a real destination and the empty state is honest.
 */
final class TaxStatementsController extends Controller
{
    public function show(): View|RedirectResponse
    {
        $user = Auth::user();

        if ($user !== null && $user->hasRole('admin')) {
            return redirect()->route('admin.dashboard');
        }

        if ($user?->distributor === null) {
            return redirect()->route('dashboard');
        }

        return view('membership.tax-statements');
    }
}
