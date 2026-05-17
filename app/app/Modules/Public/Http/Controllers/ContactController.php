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
            'states' => $this->indianStates(),
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

        $stateCodes = array_keys($this->indianStates());

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            // Same regex as registration step 1 — 10-digit Indian mobile starting 6-9.
            'phone_e164' => ['required', 'string', 'regex:/^(\+?91)?[6-9][0-9]{9}$/'],
            // Postal address block — all five required so support can route
            // and acknowledge inquiries by region. PIN is the 6-digit Indian
            // postal code; state is the same two-letter code set used by the
            // registration wizard (KA, MH, TG, …) for consistency.
            'address' => ['required', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:120'],
            'district' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'in:'.implode(',', $stateCodes)],
            'pin_code' => ['required', 'string', 'regex:/^[1-9][0-9]{5}$/'],
            'purpose' => ['required', 'in:become_distributor,support,compliance,partnership,other'],
            'message' => ['required', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:64'],
            // DPDP Act 2023 §6 — explicit, dated consent before processing.
            'consent_privacy' => ['required', 'accepted'],
        ], [
            'address.required' => 'Please enter your full postal address.',
            'city.required' => 'Please enter your city.',
            'district.required' => 'Please enter your district.',
            'state.required' => 'Please pick your state.',
            'state.in' => 'Please pick a valid Indian state.',
            'pin_code.required' => 'Please enter your 6-digit PIN code.',
            'pin_code.regex' => 'PIN code must be exactly 6 digits, e.g. 500032.',
            'consent_privacy.required' => 'Please agree to the privacy notice before sending your message.',
            'consent_privacy.accepted' => 'Please agree to the privacy notice before sending your message.',
        ], [
            'phone_e164' => 'mobile number',
            'pin_code' => 'PIN code',
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
            'address' => $validated['address'],
            'city' => $validated['city'],
            'district' => $validated['district'],
            'state' => strtoupper($validated['state']),
            'pin_code' => $validated['pin_code'],
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

    /**
     * Indian state / UT code → display-name map. Same set the registration
     * wizard uses (RegistrationWizardController::indianStates) so the two
     * forms stay in lockstep. Duplicated for now; lift into a shared
     * support package the next time a third caller appears.
     *
     * @return array<string, string>
     */
    private function indianStates(): array
    {
        return [
            'AN' => 'Andaman and Nicobar Islands',
            'AP' => 'Andhra Pradesh',
            'AR' => 'Arunachal Pradesh',
            'AS' => 'Assam',
            'BR' => 'Bihar',
            'CH' => 'Chandigarh',
            'CT' => 'Chhattisgarh',
            'DN' => 'Dadra and Nagar Haveli',
            'DD' => 'Daman and Diu',
            'DL' => 'Delhi',
            'GA' => 'Goa',
            'GJ' => 'Gujarat',
            'HR' => 'Haryana',
            'HP' => 'Himachal Pradesh',
            'JK' => 'Jammu and Kashmir',
            'JH' => 'Jharkhand',
            'KA' => 'Karnataka',
            'KL' => 'Kerala',
            'LD' => 'Lakshadweep',
            'MP' => 'Madhya Pradesh',
            'MH' => 'Maharashtra',
            'MN' => 'Manipur',
            'ML' => 'Meghalaya',
            'MZ' => 'Mizoram',
            'NL' => 'Nagaland',
            'OR' => 'Odisha',
            'PY' => 'Puducherry',
            'PB' => 'Punjab',
            'RJ' => 'Rajasthan',
            'SK' => 'Sikkim',
            'TN' => 'Tamil Nadu',
            'TG' => 'Telangana',
            'TR' => 'Tripura',
            'UP' => 'Uttar Pradesh',
            'UT' => 'Uttarakhand',
            'WB' => 'West Bengal',
        ];
    }
}
