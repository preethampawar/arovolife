<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Auth;

use App\Modules\Identity\Http\Rules\NotPwned;
use App\Modules\Identity\Http\Rules\StrongPassword;
use App\Modules\Identity\Services\Exceptions\InvalidResetTokenError;
use App\Modules\Identity\Services\RequestPasswordReset;
use App\Modules\Identity\Services\ResetPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class PasswordResetController extends Controller
{
    public function __construct(
        private readonly RequestPasswordReset $request,
        private readonly ResetPassword $reset,
    ) {}

    public function showRequest(): View
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        ($this->request)($validated['email']);

        // Generic confirmation regardless of whether the email matched a
        // user — prevents account-existence enumeration via this endpoint.
        return back()->with('status', 'If an account exists for that email, we have sent a password-reset link. The link expires in 60 minutes.');
    }

    public function showReset(Request $request, string $token): View|RedirectResponse
    {
        $email = (string) $request->query('email', '');
        if ($email === '' || $token === '') {
            return redirect()->route('password.request')->withErrors([
                'email' => 'Reset link is missing required information. Please request a new one.',
            ]);
        }

        return view('auth.reset-password', [
            'token' => $token,
            'email' => $email,
        ]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed', new StrongPassword, new NotPwned],
        ]);

        try {
            $user = ($this->reset)(
                email: $validated['email'],
                rawToken: $token,
                newPassword: $validated['password'],
            );
        } catch (InvalidResetTokenError $e) {
            return back()->withInput($request->only('email'))->withErrors([
                'email' => $e->getMessage(),
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Your password has been reset. You are now signed in.');
    }
}
