<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Services\WizardStateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRegistrationProgress
{
    public function __construct(
        private readonly WizardStateService $wizard,
    ) {}

    public function handle(Request $request, Closure $next, int $requiredStep): Response
    {
        // Steps 1 (sponsor & placement) and 2 (account) are public
        if ($requiredStep <= 2) {
            return $next($request);
        }

        $state = $this->wizard->get();

        if ($state === null) {
            // Session lost mid-flow. A previous version of this code redirected
            // to /login, which is wrong for the common case: a fresh visitor
            // who hasn't created an account yet has nothing to log in *to*.
            //
            // Better path: if the wizard intent (sponsor + placement, set at
            // step 1) is still in the session, send the user back to /register
            // with the original referral params so they can re-enter at step 2.
            // If even the intent is gone, send them to /join with a clear
            // expired-session message — they can paste their referral details
            // again or fall back to /login if they realise they already
            // completed registration.
            $intent = $this->wizard->intent();
            if (is_array($intent) && ! empty($intent['sponsor_adn']) && ! empty($intent['placement_adn'])) {
                return redirect()->route('register', [
                    'sponsor' => $intent['sponsor_adn'],
                    'placement' => $intent['placement_adn'],
                ]);
            }

            return redirect()->route('join.show')->with(
                'status',
                'Your registration session expired. Please re-enter your referral details to continue. If you already completed registration, sign in instead.'
            );
        }

        $furthestAllowed = $this->wizard->currentStep();

        if ($requiredStep > $furthestAllowed) {
            // Redirect-on-skip: send the visitor to their furthest-completed
            // step, resolved through the canonical WizardStateService map. A
            // previous local copy of that map went stale when the Arete step
            // was inserted, redirecting step-10 users to /register/complete
            // itself — an infinite loop. Clamped to 11 so the redirect can
            // never target the gated Complete route (step 12).
            $redirectStep = max(3, min($furthestAllowed, 11));

            return redirect()->route(WizardStateService::stepRoute($redirectStep));
        }

        return $next($request);
    }
}
