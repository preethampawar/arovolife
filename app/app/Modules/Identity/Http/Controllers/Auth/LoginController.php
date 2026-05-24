<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Auth;

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
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Per-(email + IP) lockout: 5 failed attempts inside ~15 minutes
        // halts further tries. Mitigates password-spray and credential
        // stuffing without making honest typos painful.
        $key = 'login:'.Str::lower($credentials['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            $seconds = RateLimiter::availableIn($key);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => "Too many failed login attempts. Try again in {$seconds} seconds."]);
        }

        // Pre-flight: if the user exists but has never set their own password
        // (e.g. spouse account from a couple registration), refuse login and
        // tell them to use the activation link they received by email.
        $candidate = User::query()
            ->where('email', $credentials['email'])->first();
        if ($candidate !== null && $candidate->password_set_at === null) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Your account has not been activated yet. Please use the activation link sent to your email, or contact support@arovolife.com.']);
        }

        if (Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']], $request->boolean('remember'))) {
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
                    'email' => 'This account has been closed. Please contact support if you believe this is an error.',
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
            ->withInput($request->only('email'))
            ->withErrors(['email' => 'These credentials do not match our records.']);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
