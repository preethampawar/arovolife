<?php

declare(strict_types=1);

namespace App\Modules\Public\Http\Controllers;

use App\Modules\Public\Models\ContactInquiry;
use App\Modules\Public\Notifications\NewContactInquiryNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

final class ContactController extends Controller
{
    private const ALLOWED_REASONS = [
        'referral_link_required',
        'invalid_referral_link',
        'join_us',
        'general',
    ];

    public function show(Request $request): View
    {
        $reason = (string) $request->query('reason', 'general');
        if (! in_array($reason, self::ALLOWED_REASONS, true)) {
            $reason = 'general';
        }

        return view('public.contact', [
            'reason' => $reason,
        ]);
    }

    public function submit(Request $request): RedirectResponse
    {
        // Per-IP rate limit: 3 submissions / hour. Mitigates spam without
        // making honest mistakes painful.
        $key = 'contact:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, maxAttempts: 3)) {
            $seconds = RateLimiter::availableIn($key);

            return back()
                ->withInput()
                ->withErrors(['message' => "Too many submissions. Please try again in {$seconds} seconds."]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            // Same regex as registration step 1 — 10-digit Indian mobile starting 6-9.
            'phone_e164' => ['required', 'string', 'regex:/^(\+?91)?[6-9][0-9]{9}$/'],
            'address' => ['nullable', 'string', 'max:500'],
            'purpose' => ['required', 'in:become_distributor,support,compliance,partnership,other'],
            'message' => ['required', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:64'],
            // DPDP Act 2023 §6 — explicit, dated consent before processing.
            'consent_privacy' => ['required', 'accepted'],
        ], [
            'consent_privacy.required' => 'Please agree to the privacy notice before sending your message.',
            'consent_privacy.accepted' => 'Please agree to the privacy notice before sending your message.',
        ]);

        // Normalise phone to +91XXXXXXXXXX so admin views are consistent.
        $phone = preg_replace('/^\+?91/', '', $validated['phone_e164']);
        $phoneE164 = '+91'.$phone;

        $reason = $validated['reason'] ?? null;
        if ($reason !== null && ! in_array($reason, self::ALLOWED_REASONS, true)) {
            $reason = null;
        }

        $inquiry = ContactInquiry::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone_e164' => $phoneE164,
            'address' => $validated['address'] ?? null,
            'purpose' => $validated['purpose'],
            'message' => $validated['message'],
            'reason' => $reason,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
            'privacy_consent_at' => now(),
        ]);

        RateLimiter::hit($key, decaySeconds: 3600);

        // Route the queued notification to the configured support inbox.
        // We hand only the inquiry ID — the notification re-fetches inside
        // toMail() so PII is never serialised onto the queue payload.
        $supportEmail = (string) config('mail.support_address', env('SUPPORT_EMAIL', 'support@arovolife.com'));
        Notification::route('mail', $supportEmail)
            ->notify(new NewContactInquiryNotification($inquiry->id));

        return redirect()->route('contact.show')
            ->with('status', 'Thanks — our team will reach out within one business day.');
    }
}
