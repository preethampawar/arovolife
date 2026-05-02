<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Auth;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Http\Rules\NotPwned;
use App\Modules\Identity\Http\Rules\StrongPassword;
use App\Modules\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Spouse-account activation. The spouse user is created during couple
 * registration with a random password and `password_set_at = NULL`. This
 * controller serves the signed-URL landing where the spouse sets their
 * own password and flips the flag, unblocking login.
 */
final class SpouseActivationController extends Controller
{
    public function show(Request $request, int $user): View|RedirectResponse
    {
        // hasValidSignature() is enforced by the `signed` middleware on the
        // route; this controller is only reachable with a valid, unexpired
        // signature.
        $u = User::query()->findOrFail($user);

        if ($u->password_set_at !== null) {
            return redirect()->route('login')
                ->with('status', 'This account is already active. Please sign in with your password.');
        }

        return view('auth.spouse-activate', ['user' => $u]);
    }

    public function submit(Request $request, int $user): RedirectResponse
    {
        $u = User::query()->findOrFail($user);

        if ($u->password_set_at !== null) {
            return redirect()->route('login')
                ->with('status', 'This account is already active.');
        }

        $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed', new StrongPassword, new NotPwned],
        ]);

        $u->update([
            'password_hash' => Hash::make($request->input('password')),
            'password_set_at' => now(),
        ]);

        AuditLog::create([
            'actor_id' => $u->id,
            'action' => 'identity.spouse.activated',
            'subject_type' => 'user',
            'subject_id' => $u->id,
            'details' => ['ip' => $request->ip()],
        ]);

        Auth::login($u);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Welcome — your account is now active.');
    }
}
