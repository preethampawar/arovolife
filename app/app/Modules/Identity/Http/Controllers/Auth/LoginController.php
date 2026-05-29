<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Auth;

use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class LoginController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Distributors may sign in with either their email address or their
        // 9-digit ADN. Emails always contain '@'; anything without one is
        // treated as an ADN and resolved to the owning user's email via the
        // distributors table. An unmatched ADN simply falls through and fails
        // authentication like any wrong credential — no account enumeration.
        //
        // Couple registrations share ONE ADN across two distributor rows
        // (hard rule #6), each with its own user + password and an
        // is_primary_couple flag. When an ADN resolves to a couple, the
        // login form surfaces a "Primary account holder" checkbox so the
        // spouse can be disambiguated; we pick the matching row (defaulting
        // to the primary holder). A solo ADN ignores the checkbox entirely.
        $loginInput = trim($credentials['login']);
        $email = $loginInput;
        if (! str_contains($loginInput, '@')) {
            $matches = Distributor::query()
                ->where('adn', $loginInput)
                ->with('user')
                ->get();

            $chosen = $matches->count() > 1
                ? ($matches->firstWhere('is_primary_couple', $request->boolean('primary'))
                    ?? $matches->firstWhere('is_primary_couple', true)
                    ?? $matches->first())
                : $matches->first();

            $email = $chosen?->user?->email ?? $loginInput;
        }

        // Look the user up once; we need them both for the post-reset
        // throttle-clear and for the unactivated-account pre-flight below.
        $candidate = User::query()
            ->where('email', $email)->first();

        // Per-(email + IP) lockout: 5 failed attempts inside ~15 minutes
        // halts further tries. Mitigates password-spray and credential
        // stuffing without making honest typos painful.
        $key = 'login:'.Str::lower($email).'|'.$request->ip();

        // If an admin recently reset this user's password, drop any stale
        // lockout BEFORE the throttle check so the user isn't blocked by
        // attempts they made against their old password. Consumed once: the
        // flag is nulled here so it can't be reused to bypass the limiter.
        if ($candidate !== null && $candidate->login_throttle_cleared_at !== null) {
            RateLimiter::clear($key);
            $candidate->update(['login_throttle_cleared_at' => null]);
        }

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            $seconds = RateLimiter::availableIn($key);

            return back()
                ->withInput($request->only('login'))
                ->withErrors(['login' => "Too many failed login attempts. Try again in {$seconds} seconds."]);
        }

        // Pre-flight: if the user exists but has never set their own password
        // (e.g. spouse account from a couple registration), refuse login and
        // tell them to use the activation link they received by email.
        if ($candidate !== null && $candidate->password_set_at === null) {
            return back()
                ->withInput($request->only('login'))
                ->withErrors(['login' => 'Your account has not been activated yet. Please use the activation link sent to your email, or contact support@arovolife.com.']);
        }

        if (Auth::attempt(['email' => $email, 'password' => $credentials['password']], $request->boolean('remember'))) {
            RateLimiter::clear($key);
            $request->session()->regenerate();

            $user = Auth::user();

            // Terminated accounts can authenticate against the password but
            // are immediately signed out and shown an error — they have no
            // place to go. Same pattern as banks use on closed accounts.
            if ($user->status === 'terminated') {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return back()->withErrors([
                    'login' => 'This account has been closed. Please contact support if you believe this is an error.',
                ]);
            }

            if ($user->hasRole('admin')) {
                return redirect()->intended(route('admin.dashboard'));
            }

            // 'pending' has two meanings now:
            //   1. registration not yet completed → send back to the wizard
            //   2. registration complete, KYC under admin review → dashboard
            // The presence of a distributors row distinguishes them.
            if ($user->status === 'pending' && $user->distributor === null) {
                return redirect()->route('register.orientation');
            }

            // Rejected applicants land on the re-upload page. The
            // RedirectRejectedToResubmit middleware would catch this anyway,
            // but redirecting explicitly here keeps the URL bar clean.
            if ($user->status === 'rejected') {
                return redirect()->route('kyc.resubmit.show');
            }

            return redirect()->intended(route('dashboard'));
        }

        // Failed attempt: charge the bucket. 15-minute decay matches the
        // master plan's policy.
        RateLimiter::hit($key, decaySeconds: 900);

        // Defence-in-depth: rotate the session ID on every state change,
        // including failed credentials. Mitigates session-fixation where
        // an attacker pre-seeds a session and convinces the victim to log
        // in into "their" session.
        $request->session()->migrate(true);

        return back()
            ->withInput($request->only('login'))
            ->withErrors(['login' => 'These credentials do not match our records.']);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
