<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Services\WizardStateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRegistrationProgress
{
    public function __construct(private readonly WizardStateService $wizard) {}

    private const STEP_ROUTES = [
        2 => 'register.orientation',
        3 => 'register.personal',
        4 => 'register.pan',
        5 => 'register.aadhaar',
        6 => 'register.bank',
        7 => 'register.documents',
        8 => 'register.placement',
        9 => 'register.consent',
        10 => 'register.complete',
    ];

    public function handle(Request $request, Closure $next, int $requiredStep): Response
    {
        // Step 1 (account) needs no prior state
        if ($requiredStep <= 1) {
            return $next($request);
        }

        $state = $this->wizard->get();

        if ($state === null || $this->wizard->userId() === null) {
            // Wizard state lost mid-flow (session expiry, browser cleared,
            // etc). Per ADR-0003 we cannot just bounce them to /register —
            // that triggers start() which would route them to Contact Us
            // because the referral-link intent is also gone. Send them to
            // login with a flash so they can sign in or seek support.
            return redirect()->route('login')->with(
                'status',
                'Your registration session expired. Please sign in if you completed registration, or use your referral link again to start over.'
            );
        }

        $furthestAllowed = $this->wizard->currentStep();

        if ($requiredStep > $furthestAllowed) {
            $redirectStep = max(2, min($furthestAllowed, 10));
            $route = self::STEP_ROUTES[$redirectStep] ?? 'register';

            return redirect()->route($route);
        }

        return $next($request);
    }
}
