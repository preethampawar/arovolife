<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Shared\Features\FamilyCardFeature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Laravel\Pennant\Feature;

/**
 * The arovolife Family Card — a display-only card (name, ADN, "1 + 5 family
 * members") the distributor can view, print, or save as a 2-page PDF via the
 * browser's print dialog, the same way as the membership card.
 *
 * It is not an insurance policy and carries no benefit (R-117); the card never
 * shows family members' names.
 */
final class FamilyCardController extends Controller
{
    /** In-app page (left sidebar → My Family Card): the card plus a download link. */
    public function index(): View|RedirectResponse
    {
        return $this->render('membership.my-family-card');
    }

    /** Standalone printable page — Download PDF / Print. */
    public function show(): View|RedirectResponse
    {
        return $this->render('membership.family-card');
    }

    /**
     * @param  view-string  $view
     */
    private function render(string $view): View|RedirectResponse
    {
        abort_unless(Feature::for(null)->active(FamilyCardFeature::class), 404);

        $user = Auth::user();

        if ($user !== null && $user->isSuperStaff()) {
            return redirect()->route('admin.dashboard');
        }

        $distributor = $user?->distributor;

        // No ADN yet, or the ADN is closed — no card to show.
        if ($distributor === null || $user->status === 'terminated') {
            return redirect()->route('dashboard');
        }

        return view($view, [
            'name' => $user->full_name,
            'adn' => $distributor->adn,
        ]);
    }
}
