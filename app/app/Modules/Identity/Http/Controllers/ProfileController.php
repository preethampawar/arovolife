<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Http\Rules\NotPwned;
use App\Modules\Identity\Http\Rules\StrongPassword;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Notifications\OtpCodeNotification;
use App\Modules\Shared\Otp\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Logged-in profile management.
 *
 * Two flows live here:
 *
 *   - Profile view + edit. Identity fields are READ-ONLY: full name, ADN, and
 *     the KYC numbers (PAN / Aadhaar / bank) are locked because they are
 *     verified identity — changes go through admin KYC review, not self-service
 *     (hard rule #6, #8). Only the contact details — mobile, email, address —
 *     are editable. KYC numbers are only ever shown MASKED (last-4).
 *   - Change-password (current + new + confirm) — uses the same Password
 *     rule as the registration wizard so policy stays in sync.
 *
 * Both endpoints are gated by the `auth` middleware. Audit-logged so the
 * compliance team can spot brute-force / hijack patterns.
 */
final class ProfileController extends Controller
{
    /** OTP scope for a self-service mobile/email change. */
    private const OTP_PURPOSE = 'profile_contact_change';

    public function show(): View
    {
        $user = Auth::user();

        return view('profile.show', [
            'user' => $user,
            // The distributor record backs the read-only identity block
            // (ADN + masked PAN/Aadhaar/bank). Null for a non-distributor
            // (e.g. an admin) — the view hides that block.
            'distributor' => $user?->distributor,
        ]);
    }

    public function update(Request $request, OtpService $otp): RedirectResponse
    {
        /** @var User|null $user */
        $user = Auth::user();
        abort_if($user === null, 401);

        // Only contact details are editable. full_name / ADN / PAN / Aadhaar /
        // bank are verified identity and are NOT accepted here — any such input
        // is ignored.
        $data = $request->validate([
            'phone_e164' => [
                'required',
                'string',
                'regex:/^\+91[6-9]\d{9}$/',
                Rule::unique('users', 'phone_e164')->ignore($user->id),
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'address' => ['nullable', 'string', 'max:500'],
        ], [
            'phone_e164.regex' => 'Phone must be a valid Indian mobile number in +91XXXXXXXXXX format.',
            'email.unique' => 'That email address is already in use by another account.',
        ]);

        $emailChanged = mb_strtolower(trim($data['email'])) !== mb_strtolower((string) $user->email);
        $phoneChanged = $data['phone_e164'] !== $user->phone_e164;
        $address = $data['address'] ?? null;

        // Address is not security-sensitive — persist immediately.
        if ($address !== $user->address) {
            $oldAddress = $user->address;
            $user->update(['address' => $address]);
            $this->audit($user, 'profile.updated', [
                'before' => ['address' => $oldAddress], 'after' => ['address' => $address],
            ], $request);
        }

        // Mobile / email changes only take effect after an OTP confirmation.
        if (! $emailChanged && ! $phoneChanged) {
            return redirect()->route('profile.show')->with('status', 'Profile updated.');
        }

        $target = $emailChanged ? $data['email'] : (string) $user->email;
        $code = $otp->issue(self::OTP_PURPOSE, (string) $user->id, [
            'email' => $data['email'],
            'phone_e164' => $data['phone_e164'],
            'email_changed' => $emailChanged,
        ]);
        // OTP to the user's email (and, once an SMS gateway is integrated, to
        // the new mobile too). For an email change the code goes to the NEW
        // address, proving the user can receive there.
        Notification::route('mail', $target)->notify(
            new OtpCodeNotification($code, 'update your arovolife contact details'),
        );

        $this->audit($user, 'profile.contact_otp_sent', [
            'email_changed' => $emailChanged,
            'phone_changed' => $phoneChanged,
            'target' => $this->maskEmail($target),
        ], $request);

        return redirect()->route('profile.show')
            ->withInput($request->only('phone_e164', 'email', 'address'))
            ->with('profile_otp', ['email_masked' => $this->maskEmail($target)]);
    }

    /**
     * Confirm a pending mobile/email change with the emailed OTP. Only on a
     * valid code does the change take effect.
     */
    public function confirmOtp(Request $request, OtpService $otp): RedirectResponse
    {
        /** @var User|null $user */
        $user = Auth::user();
        abort_if($user === null, 401);

        $request->validate([
            'otp' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], [
            'otp.regex' => 'Enter the 6-digit code we emailed you.',
        ]);

        $result = $otp->verify(self::OTP_PURPOSE, (string) $user->id, $request->string('otp')->toString());

        if (! $result->ok) {
            $redirect = redirect()->route('profile.show')->withErrors(['otp' => $result->message()]);
            // Keep the modal open for a retry while a code is still pending.
            $pending = $otp->peek(self::OTP_PURPOSE, (string) $user->id);
            if ($pending !== null) {
                $target = ($pending['email_changed'] ?? false) ? (string) $pending['email'] : (string) $user->email;
                $redirect->with('profile_otp', ['email_masked' => $this->maskEmail($target)]);
            }

            return $redirect;
        }

        /** @var array{email: string, phone_e164: string, email_changed: bool} $pending */
        $pending = $result->payload;
        $emailChanged = (bool) ($pending['email_changed'] ?? false);

        // Re-check uniqueness at apply time — the value may have been taken by
        // another account between submit and confirm (avoids a DB-level 500).
        $clash = User::query()
            ->where('id', '!=', $user->id)
            ->where(function ($q) use ($pending): void {
                $q->where('email', $pending['email'])->orWhere('phone_e164', $pending['phone_e164']);
            })
            ->exists();
        if ($clash) {
            return redirect()->route('profile.show')->withErrors([
                'otp' => 'That email or mobile is now in use by another account. Please start the change again.',
            ]);
        }

        $before = ['email' => $user->email, 'phone_e164' => $user->phone_e164];
        $update = ['email' => $pending['email'], 'phone_e164' => $pending['phone_e164']];
        if ($emailChanged) {
            // New email is unverified until a future re-verification flow.
            $update['email_verified_at'] = null;
        }
        $user->update($update);

        $this->audit($user, 'profile.updated', [
            'before' => $before, 'after' => $update, 'email_changed' => $emailChanged, 'via' => 'otp',
        ], $request);

        return redirect()->route('profile.show')->with('status', 'Your contact details were updated.');
    }

    /** Resend the OTP for the still-pending contact change (rate-limited). */
    public function resendOtp(Request $request, OtpService $otp): RedirectResponse
    {
        /** @var User|null $user */
        $user = Auth::user();
        abort_if($user === null, 401);

        $pending = $otp->peek(self::OTP_PURPOSE, (string) $user->id);
        if ($pending === null) {
            return redirect()->route('profile.show');
        }

        $target = ($pending['email_changed'] ?? false) ? (string) $pending['email'] : (string) $user->email;

        $rlKey = 'otp-resend:'.$user->id;
        if (RateLimiter::tooManyAttempts($rlKey, maxAttempts: 3)) {
            return redirect()->route('profile.show')
                ->withErrors(['otp' => 'Too many code requests. Please wait a few minutes and try again.'])
                ->with('profile_otp', ['email_masked' => $this->maskEmail($target)]);
        }
        RateLimiter::hit($rlKey, decaySeconds: 600);

        $code = $otp->issue(self::OTP_PURPOSE, (string) $user->id, $pending);
        Notification::route('mail', $target)->notify(
            new OtpCodeNotification($code, 'update your arovolife contact details'),
        );

        return redirect()->route('profile.show')
            ->with('status', 'A new code has been sent.')
            ->with('profile_otp', ['email_masked' => $this->maskEmail($target)]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function audit(User $user, string $action, array $details, Request $request): void
    {
        AuditLog::create([
            'actor_id' => $user->id,
            'action' => $action,
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'details' => $details,
            'ip' => $request->ip(),
        ]);
    }

    /** ravikumar@gmail.com → r•••••••@gmail.com (for on-screen confirmation copy). */
    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2 || $parts[1] === '') {
            return '•••';
        }
        [$local, $domain] = $parts;
        $head = mb_substr($local, 0, 1);

        return $head.str_repeat('•', max(1, mb_strlen($local) - 1)).'@'.$domain;
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
            'new_password' => ['required', 'string', 'min:8', 'confirmed', new StrongPassword, new NotPwned],
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
