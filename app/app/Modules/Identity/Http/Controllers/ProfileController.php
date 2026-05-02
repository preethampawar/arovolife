<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Logged-in profile management.
 *
 * Two flows live here:
 *
 *   - Profile view + edit (name, phone) — email stays read-only because it
 *     keys identity-graph artefacts (audit log, password-reset tokens).
 *   - Change-password (current + new + confirm) — uses the same Password
 *     rule as the registration wizard so policy stays in sync.
 *
 * Both endpoints are gated by the `auth` middleware. Audit-logged so the
 * compliance team can spot brute-force / hijack patterns.
 */
final class ProfileController extends Controller
{
    public function show(): View
    {
        return view('profile.show', [
            'user' => Auth::user(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = Auth::user();
        abort_if($user === null, 401);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:120'],
            'phone_e164' => [
                'required',
                'string',
                'regex:/^\+91[6-9]\d{9}$/',
                Rule::unique('users', 'phone_e164')->ignore($user->id),
            ],
        ], [
            'phone_e164.regex' => 'Phone must be a valid Indian mobile number in +91XXXXXXXXXX format.',
        ]);

        $before = ['full_name' => $user->full_name, 'phone_e164' => $user->phone_e164];

        $user->update($data);

        AuditLog::create([
            'actor_id' => $user->id,
            'action' => 'profile.updated',
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'details' => ['before' => $before, 'after' => $data],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('profile.show')->with('status', 'Profile updated.');
    }

    public function showPasswordForm(): View
    {
        return view('profile.password');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = Auth::user();
        abort_if($user === null, 401);

        $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->uncompromised()],
        ], [
            'new_password.uncompromised' => 'This password has appeared in a known data breach. Please choose a different one.',
        ]);

        if (! Hash::check($request->string('current_password')->toString(), $user->password_hash)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        $user->update([
            'password_hash' => Hash::make($request->string('new_password')->toString()),
            'password_set_at' => now(),
        ]);

        AuditLog::create([
            'actor_id' => $user->id,
            'action' => 'password.changed',
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'details' => ['source' => 'profile'],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('profile.show')->with('status', 'Password changed.');
    }
}
