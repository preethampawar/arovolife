@extends('layouts.app')
@section('title', 'My profile')

@section('content')
@php
    // Shared classes for the locked (read-only) identity fields.
    $lockedInput = 'w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-600';
    $editLabel = 'block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5';
    $editInput = 'w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500';
    $lockTip = 'this is part of your verified identity and can only be changed by arovolife after KYC review.';
@endphp
<div>
    <h1 class="text-2xl font-bold text-gray-900 mb-1">My profile</h1>
    <p class="text-sm text-gray-600 mb-6">Your verified identity is shown for reference; update the contact details we use to reach you below.</p>

    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-6 text-sm text-blue-900">
        <p class="font-semibold mb-1">Your profile</p>
        <p class="leading-relaxed">Your name, ADN and KYC details (PAN, Aadhaar, bank) are <strong>locked</strong> — they are your verified identity and can only be changed by arovolife after KYC review. You can update your <strong>mobile, email and address</strong> below.</p>
    </div>

    @if(session('status'))
        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
            <ul class="text-sm text-red-700 space-y-1 list-disc list-inside">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('profile.update') }}" class="bg-white rounded-2xl border border-gray-200 p-6 space-y-5"
        data-confirm="Save these profile changes?"
        data-confirm-title="Update profile"
        data-confirm-impact="Updates your mobile, email and address. Your name, ADN and KYC details are not changed here.">
        @csrf
        @method('PATCH')

        {{-- ── Verified identity — read-only (1–5) ───────────────────────── --}}
        <div class="space-y-5">
            {{-- 1) FULL NAME --}}
            <div>
                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5">Full name <x-help-tip :text="$lockTip" /></label>
                <input type="text" value="{{ $user->full_name }}" disabled class="{{ $lockedInput }}">
            </div>

            @if($distributor)
            {{-- 2) ADN --}}
            <div>
                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5">ADN <x-help-tip text="your permanent arovolife Distributor Number." /></label>
                <input type="text" value="{{ $distributor->adn }}" disabled class="{{ $lockedInput }} font-mono">
            </div>

            {{-- 3) PAN (masked — last 4 only) --}}
            <div>
                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5">PAN card number <x-help-tip text="masked for your security — only the last 4 characters are shown." /></label>
                <input type="text" value="{{ $distributor->pan_masked ?? '—' }}" disabled class="{{ $lockedInput }} font-mono tracking-wider">
            </div>

            {{-- 4) AADHAAR (masked — last 4 only) --}}
            <div>
                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5">Aadhaar number <x-help-tip text="masked for your security — only the last 4 digits are shown." /></label>
                <input type="text" value="{{ $distributor->aadhaar_masked ?? '—' }}" disabled class="{{ $lockedInput }} font-mono tracking-wider">
            </div>

            {{-- 5) BANK ACCOUNT DETAILS (IFSC + on-file indicator; account number never shown) --}}
            <div>
                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5">Bank account details <x-help-tip text="your account number is encrypted and never shown; only the branch IFSC is displayed." /></label>
                @if(filled($distributor->bank_ifsc))
                    <input type="text" value="Account on file ••••  ·  IFSC {{ $distributor->bank_ifsc }}" disabled class="{{ $lockedInput }} font-mono">
                @else
                    <input type="text" value="Not added yet" disabled class="{{ $lockedInput }}">
                @endif
            </div>
            @endif
        </div>

        {{-- ── Contact details — editable (6–8) ──────────────────────────── --}}
        <div class="border-t border-gray-100 pt-5 space-y-5">
            {{-- 6) MOBILE NUMBER --}}
            <div>
                <label for="phone_e164" class="{{ $editLabel }}">Mobile (+91…) <x-help-tip text="use your indian mobile number in +91 format; arovolife uses it for account and service messages." /></label>
                <input type="tel" id="phone_e164" name="phone_e164" value="{{ old('phone_e164', $user->phone_e164) }}" required pattern="^\+91[6-9]\d{9}$"
                       class="{{ $editInput }} font-mono">
            </div>

            {{-- 7) E-MAIL ID --}}
            <div>
                <label for="email" class="{{ $editLabel }}">Email ID <x-help-tip text="the address arovolife uses for account, order and service emails." /></label>
                <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required
                       class="{{ $editInput }}">
            </div>

            {{-- 8) ADDRESS --}}
            <div>
                <label for="address" class="{{ $editLabel }}">Address <x-help-tip text="your mailing address for arovolife correspondence." /></label>
                <textarea id="address" name="address" rows="3" maxlength="500" placeholder="House / street, area, city, state, PIN"
                          class="{{ $editInput }}">{{ old('address', $user->address) }}</textarea>
            </div>
        </div>

        <div class="flex items-center justify-between pt-2">
            <a href="{{ route('profile.password.show') }}" class="text-sm text-brand-700 hover:text-brand-800 font-medium">Change password →</a>
            <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-sm font-semibold shadow-sm transition-colors">Save changes</button>
        </div>
    </form>

    {{-- ── Your data ────────────────────────────────────────────────────────── --}}
    @if($distributor)
    <div class="mt-6 bg-white rounded-2xl border border-gray-200 p-6">
        <h2 class="font-semibold text-gray-800 mb-1">Your data and consent</h2>
        <p class="text-sm text-gray-600 mb-4 leading-relaxed">
            You consented to four documents when you registered. Under §6 of the Digital Personal Data
            Protection Act, 2023 you can withdraw that consent at any time — it must be as easy to take
            back as it was to give.
        </p>
        <div class="flex flex-wrap items-center gap-4">
            <a href="{{ url('/p/privacy') }}" class="text-sm text-brand-700 hover:text-brand-800 font-medium">
                Read the Privacy Policy →
            </a>
            {{-- Deliberately a plain link and not a hidden menu item. A
                 withdrawal route that is hard to find is a withdrawal route
                 that does not satisfy §6(5). --}}
            <a href="{{ route('consent.withdraw') }}" class="text-sm text-red-700 hover:text-red-800 font-medium">
                Withdraw consent →
            </a>
        </div>
        <p class="mt-3 text-xs text-gray-600">
            Withdrawing consent closes your ADN — we cannot operate one without consent to process the
            KYC and payment data the law requires us to hold. The next screen explains exactly what happens.
        </p>
    </div>
    @endif

    {{-- ── Analytics preferences (DPDP §6 — consent must be withdrawable) ──── --}}
    @if(config('arovolife.analytics.google_id'))
    <div id="analytics-preferences" class="mt-6 bg-white rounded-2xl border border-gray-200 p-6">
        <h2 class="font-semibold text-gray-800 mb-1">Analytics cookie preference</h2>
        <p class="text-sm text-gray-600 mb-4 leading-relaxed">
            We use Google Analytics to understand how pages are used. Under the Digital Personal Data
            Protection Act, 2023 you can change or withdraw this consent at any time.
        </p>
        {{-- State is stored in localStorage, so JS reads and updates it without a page reload.
             The hidden form POSTs to /analytics-consent so the audit log is also updated. --}}
        <div id="ga-pref-granted" class="hidden">
            <p class="text-sm text-green-700 font-medium mb-3">
                <x-lucide-check-circle class="inline w-4 h-4 mr-1" />
                Analytics accepted — Google Analytics is active in your browser.
            </p>
            <form method="POST" action="{{ route('analytics.consent.store') }}" id="ga-revoke-form">
                @csrf
                <input type="hidden" name="decision" value="denied">
                <button type="submit"
                    class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-600 focus:ring-offset-2">
                    Withdraw analytics consent
                </button>
            </form>
        </div>
        <div id="ga-pref-denied" class="hidden">
            <p class="text-sm text-gray-600 font-medium mb-3">
                Analytics declined — Google Analytics is not active in your browser.
            </p>
            <form method="POST" action="{{ route('analytics.consent.store') }}" id="ga-grant-form">
                @csrf
                <input type="hidden" name="decision" value="granted">
                <button type="submit"
                    class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-600 focus:ring-offset-2">
                    Accept analytics
                </button>
            </form>
        </div>
        <div id="ga-pref-unknown" class="text-sm text-gray-500">
            Loading current preference…
        </div>
        <script>
        (function () {
            var CONSENT_KEY = 'arovolife_ga_consent';
            var current;
            try { current = window.localStorage.getItem(CONSENT_KEY); } catch (e) { current = null; }

            document.getElementById('ga-pref-unknown').classList.add('hidden');

            if (current === 'granted') {
                document.getElementById('ga-pref-granted').classList.remove('hidden');
            } else if (current === 'denied') {
                document.getElementById('ga-pref-denied').classList.remove('hidden');
            } else {
                // No choice recorded — show the deny option (most conservative default).
                document.getElementById('ga-pref-denied').classList.remove('hidden');
            }

            // Update localStorage on form submit so the change takes effect
            // immediately on the next page the user visits, without waiting for
            // a redirect back to this page.
            function wire(formId, decision) {
                var form = document.getElementById(formId);
                if (!form) { return; }
                form.addEventListener('submit', function () {
                    try { window.localStorage.setItem(CONSENT_KEY, decision); } catch (e) {}
                });
            }
            wire('ga-revoke-form', 'denied');
            wire('ga-grant-form', 'granted');
        })();
        </script>
    </div>
    @endif

    {{-- ── Arete Development Centre ─────────────────────────────────────────── --}}
    @if($distributor)
    <div class="mt-6 bg-white rounded-2xl border border-gray-200 p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1">Arete Development Centre <x-help-tip text="The Arete centre you are connected to for local support, training and events. You can change this with OTP verification." /></p>
                @if($areteCenter)
                    <p class="text-sm font-semibold text-gray-900">{{ $areteCenter->name }}</p>
                    @if($areteCenter->displayLocation() !== '')
                        <p class="text-xs text-gray-600 mt-0.5">{{ $areteCenter->displayLocation() }}</p>
                    @endif
                @else
                    <p class="text-sm text-gray-600">Not assigned yet</p>
                @endif
            </div>
            @if($availableCenters->count() > 1)
            <button type="button" onclick="document.getElementById('areteCentreModal').classList.remove('hidden')"
                    class="shrink-0 px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                Change centre
            </button>
            @endif
        </div>
    </div>
    @endif
</div>

{{-- ── Arete centre change modal ───────────────────────────────────────────── --}}
@if($distributor && $availableCenters->count() > 1)
<div id="areteCentreModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4"
    role="dialog" aria-modal="true" aria-label="Change Arete centre">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-200">
            <p class="text-base font-bold text-gray-900">Change Arete Development Centre</p>
            <p class="text-sm text-gray-600 mt-1">Select a new centre. A 6-digit code will be sent to your registered email to confirm the change.</p>
        </div>
        <form method="POST" action="{{ route('profile.arete.initiate') }}" class="px-6 py-5 space-y-3">
            @csrf
            @error('center_id')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="space-y-2 max-h-60 overflow-y-auto pr-1">
                @foreach($availableCenters as $center)
                <label class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer hover:border-brand-300 transition-colors
                    {{ $areteCenter?->id === $center->id ? 'border-brand-400 bg-brand-50' : 'border-gray-200' }}">
                    <input type="radio" name="center_id" value="{{ $center->id }}" class="mt-0.5 accent-brand-500"
                           {{ $areteCenter?->id === $center->id ? 'checked' : '' }}>
                    <div>
                        <p class="text-sm font-medium text-gray-900">{{ $center->name }}
                            @if($center->is_company_default)
                                <span class="ml-1 text-[10px] font-medium text-brand-700">(default)</span>
                            @endif
                        </p>
                        @if($center->location)
                            <p class="text-xs text-gray-600">{{ $center->location }}</p>
                        @endif
                    </div>
                </label>
                @endforeach
            </div>
            <div class="flex items-center justify-between gap-3 pt-2">
                <button type="button" onclick="document.getElementById('areteCentreModal').classList.add('hidden')"
                        class="text-sm text-gray-600 hover:text-gray-700">Cancel</button>
                <button type="submit" class="px-5 py-2 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-sm font-semibold transition-colors">Send verification code</button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- ── Arete centre OTP confirmation modal ────────────────────────────────
     Shown after "Send verification code" to confirm the centre change. --}}
@if(session('arete_otp'))
@php $areteOtpCtx = session('arete_otp'); @endphp
<div id="areteCentreOtpModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4"
    role="dialog" aria-modal="true" aria-label="Confirm Arete centre change">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-200">
            <p class="text-base font-bold text-gray-900">Verify your Arete centre change</p>
            <p class="text-sm text-gray-600 mt-1">We've emailed a 6-digit code to <strong>{{ $areteOtpCtx['email_masked'] ?? 'your email' }}</strong>. Enter it to confirm the centre change.</p>
        </div>
        <form method="POST" action="{{ route('profile.arete.confirm') }}" class="px-6 py-5 space-y-4">
            @csrf
            @error('arete_otp')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <div>
                <label for="arete_otp_code" class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5">6-digit code</label>
                <input type="text" id="arete_otp_code" name="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required autofocus
                       class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-center text-lg font-mono tracking-[0.4em] focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500"
                       placeholder="••••••">
            </div>
            <div class="flex items-center justify-between gap-3">
                <button type="submit" class="flex-1 px-5 py-2.5 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-sm font-semibold transition-colors">Confirm change</button>
            </div>
        </form>
        <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm">
            <form method="POST" action="{{ route('profile.arete.resend') }}">
                @csrf
                <button type="submit" id="areteOtpResendBtn" class="text-brand-700 hover:text-brand-800 font-medium disabled:opacity-50 disabled:cursor-not-allowed">Resend code</button>
            </form>
            <a href="{{ route('profile.show') }}" class="text-gray-600 hover:text-gray-700">Cancel</a>
        </div>
    </div>
</div>
<script>
    (function () {
        var btn = document.getElementById('areteOtpResendBtn');
        if (!btn) return;
        var label = btn.textContent;
        var secs = 30;
        btn.disabled = true;
        (function tick() {
            if (secs <= 0) { btn.disabled = false; btn.textContent = label; return; }
            btn.textContent = 'Resend code in ' + secs + 's';
            secs--;
            setTimeout(tick, 1000);
        })();
    })();
</script>
@endif

{{-- ── OTP confirmation modal ─────────────────────────────────────────────
     Shown after saving when the mobile/email changed: the change is held until
     the user confirms the 6-digit code emailed to them. --}}
@if(session('profile_otp'))
@php $otpCtx = session('profile_otp'); @endphp
<div id="otpModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4"
    role="dialog" aria-modal="true" aria-label="Confirm your code">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-200">
            <p class="text-base font-bold text-gray-900">Verify it's you</p>
            <p class="text-sm text-gray-600 mt-1">We've emailed a 6-digit code to <strong>{{ $otpCtx['email_masked'] ?? 'your email' }}</strong>. Enter it to confirm your mobile / email change. Your details aren't saved until you confirm.</p>
        </div>
        <form method="POST" action="{{ route('profile.otp.confirm') }}" class="px-6 py-5 space-y-4">
            @csrf
            @error('otp')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <div>
                <label for="otp" class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5">6-digit code</label>
                <input type="text" id="otp" name="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required autofocus
                       class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-center text-lg font-mono tracking-[0.4em] focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500"
                       placeholder="••••••">
            </div>
            <div class="flex items-center justify-between gap-3">
                <button type="submit" class="flex-1 px-5 py-2.5 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-sm font-semibold transition-colors">Confirm</button>
            </div>
        </form>
        <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm">
            <form method="POST" action="{{ route('profile.otp.resend') }}">
                @csrf
                <button type="submit" id="otpResendBtn" class="text-brand-700 hover:text-brand-800 font-medium disabled:opacity-50 disabled:cursor-not-allowed">Resend code</button>
            </form>
            <a href="{{ route('profile.show') }}" class="text-gray-600 hover:text-gray-700">Cancel</a>
        </div>
    </div>
</div>
<script>
    // Resend stays disabled for 30s after the modal opens, with a live countdown.
    (function () {
        var btn = document.getElementById('otpResendBtn');
        if (!btn) return;
        var label = btn.textContent;
        var secs = 30;
        btn.disabled = true;
        (function tick() {
            if (secs <= 0) { btn.disabled = false; btn.textContent = label; return; }
            btn.textContent = 'Resend code in ' + secs + 's';
            secs--;
            setTimeout(tick, 1000);
        })();
    })();
</script>
@endif
@endsection
