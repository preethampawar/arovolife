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
            // Session lost mid-flow with no draft restoration
            return redirect()->route('login')->with(
                'status',
                'Your registration session expired. Please sign in if you completed registration, or use your referral link again to start over.'
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
