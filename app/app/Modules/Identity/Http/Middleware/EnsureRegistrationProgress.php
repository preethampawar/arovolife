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

    // Step → route map for the redirect-on-skip path. Mirrors the canonical
    // 2026-05 step order (see WizardStateService::STEPS). Step 1 (sponsor &
    // placement) and step 2 (account) live in public routes and aren't
    // gated by this middleware; both are absent here.
    private const STEP_ROUTES = [
        3 => 'register.orientation',
        4 => 'register.consent',
        5 => 'register.pan',
        6 => 'register.aadhaar',
        7 => 'register.bank',
        8 => 'register.personal',
        9 => 'register.documents',
        10 => 'register.complete',
    ];

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
            $redirectStep = max(3, min($furthestAllowed, 10));
            $route = self::STEP_ROUTES[$redirectStep] ?? 'register';

            return redirect()->route($route);
        }

        return $next($request);
    }
}
