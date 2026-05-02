<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Modules\Compliance\Services\CancelCoolingOff;
use App\Modules\Compliance\Services\Exceptions\CoolingOffAlreadyCancelledError;
use App\Modules\Compliance\Services\Exceptions\CoolingOffWindowExpiredError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class CoolingOffController extends Controller
{
    public function __construct(
        private readonly CancelCoolingOff $cancel,
    ) {}

    public function show(): View|RedirectResponse
    {
        $user = Auth::user();
        $distributor = $user?->distributor;

        if ($distributor === null) {
            return redirect()->route('dashboard');
        }

        return view('compliance.cooling-off', [
            'distributor' => $distributor,
            'now' => now(),
            'isWithinWindow' => now()->lessThanOrEqualTo($distributor->cooling_off_end_at),
        ]);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->validate([
            'confirm' => ['required', 'in:yes'],
        ]);

        $user = Auth::user();
        $distributor = $user?->distributor;

        if ($distributor === null) {
            return redirect()->route('dashboard')
                ->withErrors(['distributor' => 'No distributor record found for this account.']);
        }

        try {
            ($this->cancel)($distributor->id, actorUserId: $user->id);
        } catch (CoolingOffWindowExpiredError) {
            return back()->withErrors(['cooling_off' => 'The 30-day cooling-off window has ended.']);
        } catch (CoolingOffAlreadyCancelledError) {
            return back()->withErrors(['cooling_off' => 'This registration has already been cancelled.']);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with(
            'status',
            'Your registration has been cancelled. You will receive a written confirmation by email.',
        );
    }
}
