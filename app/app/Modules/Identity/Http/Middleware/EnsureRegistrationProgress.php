<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Services\DraftStateService;
use App\Modules\Identity\Services\WizardStateService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRegistrationProgress
{
    public function __construct(
        private readonly WizardStateService $wizard,
        private readonly DraftStateService $drafts,
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
        // Steps 1 (sponsor & placement) and 2 (account) are public — this
        // middleware never gates them.
        if ($requiredStep <= 2) {
            return $next($request);
        }

        $state = $this->wizard->get();

        if ($state === null || $this->wizard->userId() === null) {
            // Wizard state lost mid-flow (session expiry, browser cleared,
            // etc). Before bouncing to login, attempt to restore from the
            // av_draft cookie so the user can continue without re-entry.
            $rawToken = $request->cookie('av_draft');

            if (is_string($rawToken)) {
                $draft = $this->drafts->resolveFromToken($rawToken);

                if ($draft !== null) {
                    // Verify the cookie belongs to the currently authenticated user (if any).
                    $authId = Auth::id();
                    if ($authId !== null && $authId !== $draft->user_id) {
                        // Cookie belongs to a different user — fall through to login redirect.
                    } else {
                        // Check placement slots before restoring.
                        $takenSlots = (int) DB::table('distributors')
                            ->where('placement_parent_id', $draft->placement_id)
                            ->whereNotNull('placement_side')
                            ->count();

                        if ($takenSlots >= 2) {
                            $this->wizard->clearIntent();

                            return redirect()->route('join.show')
                                ->with('status', 'The placement position from your original invitation is no longer available. Please choose a new one.');
                        }

                        Auth::loginUsingId($draft->user_id);
                        $request->session()->regenerate();
                        $this->drafts->restoreToWizard($draft, $this->wizard);

                        // If the required step is beyond the draft's current step, redirect
                        // to the draft's current step instead.
                        if ($requiredStep > $draft->current_step) {
                            $redirectStep = max(3, min($draft->current_step, 10));
                            $route = self::STEP_ROUTES[$redirectStep] ?? 'register';

                            return redirect()->route($route);
                        }

                        return $next($request);
                    }
                }
            }

            // Per ADR-0003 we cannot just bounce them to /register — that
            // triggers start() which would route them to Contact Us because
            // the referral-link intent is also gone. Send them to login with
            // a flash so they can sign in or seek support.
            return redirect()->route('login')->with(
                'status',
                'Your registration session expired. Please sign in if you completed registration, or use your referral link again to start over.'
            );
        }

        $furthestAllowed = $this->wizard->currentStep();

        if ($requiredStep > $furthestAllowed) {
            // Clamp to the gated range (3..10). The route map covers exactly
            // those entries; fall back to /register if the math drifts.
            $redirectStep = max(3, min($furthestAllowed, 10));
            $route = self::STEP_ROUTES[$redirectStep] ?? 'register';

            return redirect()->route($route);
        }

        return $next($request);
    }
}
