<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Contact arovolife</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.public-topnav')

    <div class="max-w-2xl mx-auto px-6 py-12 sm:py-16">

        <div class="text-center mb-8 lift-in" style="animation-delay: 60ms;">
            <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">Get in touch</p>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 leading-tight">
                Talk to <span class="text-brand-600">arovolife</span>.
            </h1>
        </div>

        @if($reason === 'referral_link_required')
            <div class="card-refined p-5 sm:p-6 mb-6 bg-amber-50 border border-amber-200 lift-in" style="animation-delay: 120ms;">
                <p class="text-sm font-semibold text-amber-900 mb-1.5">A referral link is required to register</p>
                <p class="text-sm text-amber-800 leading-relaxed">
                    Joining arovolife as a Direct Seller requires a personal invite from an existing distributor.
                    Leave your details below and our team will reach out within one business day to help you get started.
                </p>
            </div>
        @elseif($reason === 'invalid_referral_link')
            <div class="card-refined p-5 sm:p-6 mb-6 bg-red-50 border border-red-200 lift-in" style="animation-delay: 120ms;">
                <p class="text-sm font-semibold text-red-900 mb-1.5">That referral link couldn't be verified</p>
                <p class="text-sm text-red-800 leading-relaxed">
                    The link may have expired, the placement target may be full, or the link may be malformed.
                    Leave your details below and we'll help you complete your registration.
                </p>
            </div>
        @elseif($reason === 'join_us')
            <div class="card-refined p-5 sm:p-6 mb-6 bg-leaf-50 border border-leaf-200 lift-in" style="animation-delay: 120ms;">
                <p class="text-sm font-semibold text-leaf-800 mb-1.5">Welcome — let's get you started</p>
                <p class="text-sm text-leaf-800 leading-relaxed">
                    Joining arovolife as a Direct Seller is free and protected by India's Direct Selling Rules, 2021.
                    Tell us a bit about yourself and we'll connect you with a sponsor within one business day.
                </p>
            </div>
        @else
            <div class="text-center mb-6 lift-in" style="animation-delay: 120ms;">
                <p class="text-base text-gray-600 max-w-prose mx-auto">
                    Drop us a line. We typically respond within one business day.
                </p>
            </div>
        @endif

        <div class="card-refined p-7 sm:p-8 lift-in" style="animation-delay: 200ms;">

            @if(session('status'))
            <div class="mb-5 rounded-lg border border-leaf-200 bg-leaf-50 p-3.5 text-sm text-leaf-800">
                {{ session('status') }}
            </div>
            @endif

            @if($errors->any())
            <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-3.5">
                <ul class="text-sm text-red-700 space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            <form method="POST" action="{{ route('contact.submit') }}" class="space-y-5">
                @csrf
                <input type="hidden" name="reason" value="{{ $reason }}">

                <div class="lift-in" style="animation-delay: 320ms;">
                    <label for="name" class="flex items-baseline justify-between mb-1.5">
                        <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">Full name</span>
                        <span class="text-[10px] text-sunrise-600 font-semibold uppercase tracking-wider">Required</span>
                    </label>
                    <input id="name" name="name" type="text" required maxlength="120"
                        value="{{ old('name') }}" autocomplete="name"
                        placeholder="Enter your full name"
                        class="input-refined">
                </div>

                <div class="lift-in" style="animation-delay: 380ms;">
                    <label for="email" class="flex items-baseline justify-between mb-1.5">
                        <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">Email address</span>
                        <span class="text-[10px] text-sunrise-600 font-semibold uppercase tracking-wider">Required</span>
                    </label>
                    <input id="email" name="email" type="email" required maxlength="255"
                        value="{{ old('email') }}" autocomplete="email"
                        placeholder="you@example.com"
                        class="input-refined">
                </div>

                <div class="lift-in" style="animation-delay: 440ms;">
                    <label for="phone_e164" class="flex items-baseline justify-between mb-1.5">
                        <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">Mobile number</span>
                        <span class="text-[10px] text-sunrise-600 font-semibold uppercase tracking-wider">Required</span>
                    </label>
                    <div class="flex">
                        <span class="inline-flex items-center px-3.5 rounded-l-[0.65rem] border border-r-0 border-brand-200/50 bg-gradient-to-b from-brand-50 to-white text-slate-700 text-sm font-medium select-none">+91</span>
                        <input id="phone_e164" name="phone_e164" type="tel" required autocomplete="tel"
                            value="{{ preg_replace('/^\+?91/', '', old('phone_e164', '')) }}"
                            placeholder="9876543210"
                            maxlength="10"
                            pattern="[6-9][0-9]{9}"
                            class="input-refined flex-1 !rounded-l-none">
                    </div>
                </div>

                <div class="lift-in" style="animation-delay: 500ms;">
                    <label for="address" class="flex items-baseline justify-between mb-1.5">
                        <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">Address</span>
                        <span class="text-[10px] text-sunrise-600 font-semibold uppercase tracking-wider">Required</span>
                    </label>
                    <textarea id="address" name="address" required maxlength="500" rows="2"
                        autocomplete="street-address"
                        placeholder="Building name / street / locality"
                        class="input-refined resize-y">{{ old('address') }}</textarea>
                </div>

                <div class="lift-in grid grid-cols-1 sm:grid-cols-2 gap-4" style="animation-delay: 520ms;">
                    <div>
                        <label for="city" class="flex items-baseline justify-between mb-1.5">
                            <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">City</span>
                            <span class="text-[10px] text-sunrise-600 font-semibold uppercase tracking-wider">Required</span>
                        </label>
                        <input id="city" name="city" type="text" required maxlength="120"
                            value="{{ old('city') }}"
                            autocomplete="address-level2"
                            class="input-refined">
                    </div>
                    <div>
                        <label for="district" class="flex items-baseline justify-between mb-1.5">
                            <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">District</span>
                            <span class="text-[10px] text-sunrise-600 font-semibold uppercase tracking-wider">Required</span>
                        </label>
                        <input id="district" name="district" type="text" required maxlength="120"
                            value="{{ old('district') }}"
                            class="input-refined">
                    </div>
                </div>

                <div class="lift-in grid grid-cols-1 sm:grid-cols-2 gap-4" style="animation-delay: 540ms;">
                    <div>
                        <label for="state" class="flex items-baseline justify-between mb-1.5">
                            <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">State</span>
                            <span class="text-[10px] text-sunrise-600 font-semibold uppercase tracking-wider">Required</span>
                        </label>
                        <select id="state" name="state" required class="input-refined">
                            <option value="" disabled {{ old('state') === '' || old('state') === null ? 'selected' : '' }}>Pick your state…</option>
                            @foreach($states as $code => $name)
                                <option value="{{ $code }}" {{ old('state') === $code ? 'selected' : '' }}>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="pin_code" class="flex items-baseline justify-between mb-1.5">
                            <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">PIN code</span>
                            <span class="text-[10px] text-sunrise-600 font-semibold uppercase tracking-wider">Required</span>
                        </label>
                        <input id="pin_code" name="pin_code" type="text" required maxlength="6"
                            inputmode="numeric" pattern="^[1-9][0-9]{5}$"
                            value="{{ old('pin_code') }}"
                            placeholder="e.g. 500032"
                            autocomplete="postal-code"
                            class="input-refined font-mono">
                    </div>
                </div>

                <div class="lift-in" style="animation-delay: 560ms;">
                    <label for="purpose" class="flex items-baseline justify-between mb-1.5">
                        <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">Purpose</span>
                        <span class="text-[10px] text-sunrise-600 font-semibold uppercase tracking-wider">Required</span>
                    </label>
                    <select id="purpose" name="purpose" required class="input-refined">
                        @php
                            $autoPurposeReasons = ['referral_link_required', 'invalid_referral_link', 'join_us'];
                            $oldPurpose = old('purpose', in_array($reason, $autoPurposeReasons, true) ? 'become_distributor' : '');
                        @endphp
                        <option value="" disabled {{ $oldPurpose === '' ? 'selected' : '' }}>Pick one…</option>
                        <option value="become_distributor" {{ $oldPurpose === 'become_distributor' ? 'selected' : '' }}>Become a Direct Seller</option>
                        <option value="support" {{ $oldPurpose === 'support' ? 'selected' : '' }}>Account or KYC support</option>
                        <option value="compliance" {{ $oldPurpose === 'compliance' ? 'selected' : '' }}>Compliance / grievance</option>
                        <option value="partnership" {{ $oldPurpose === 'partnership' ? 'selected' : '' }}>Corporate or institutional enquiry — e.g. media, corporate gifting (not distribution or resale)</option>
                        <option value="other" {{ $oldPurpose === 'other' ? 'selected' : '' }}>Other</option>
                    </select>
                </div>

                <div class="lift-in" style="animation-delay: 620ms;">
                    <label for="message" class="flex items-baseline justify-between mb-1.5">
                        <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">Message</span>
                        <span class="text-[10px] text-sunrise-600 font-semibold uppercase tracking-wider">Required</span>
                    </label>
                    <textarea id="message" name="message" required maxlength="2000" rows="5"
                        placeholder="Tell us what you'd like to discuss…"
                        class="input-refined resize-y">{{ old('message') }}</textarea>
                    <p class="mt-1.5 text-xs text-slate-500">Up to 2,000 characters.</p>
                    @error('message')<p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>@enderror
                </div>

                {{-- DPDP Act 2023 §6 — informed, specific, free consent before
                     processing personal data. Server-side rule on submit
                     enforces 'accepted'. --}}
                <div class="lift-in pt-2 border-t border-slate-200/60" style="animation-delay: 660ms;">
                    <label class="flex items-start gap-2.5 text-[12px] text-slate-600 cursor-pointer leading-relaxed">
                        <input type="checkbox" name="consent_privacy" value="1" required
                            {{ old('consent_privacy') ? 'checked' : '' }}
                            class="mt-0.5 rounded border-slate-300 text-brand-500 focus:ring-brand-500/40">
                        <span>
                            I agree to arovolife processing the details I've shared above for the sole purpose of responding to this enquiry.
                            Records are deleted within 90 days if no further action is taken.
                            See our
                            <a href="{{ route('content.show', 'privacy') }}" class="text-brand-600 hover:text-brand-700 font-medium underline-offset-4 hover:underline" target="_blank" rel="noopener">Privacy Policy</a>
                            for details on how we handle your data and how to withdraw consent.
                        </span>
                    </label>
                    @error('consent_privacy')<p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>@enderror
                </div>

                <button type="submit"
                    class="btn-cta group w-full rounded-full bg-brand-500 hover:bg-brand-600 text-white py-3.5 text-sm font-semibold transition-all duration-200 focus:outline-none focus:ring-4 focus:ring-brand-300/40 lift-in shadow-lg shadow-brand-500/30 hover:shadow-xl hover:shadow-brand-500/40"
                    style="animation-delay: 720ms;">
                    <span class="inline-flex items-center justify-center gap-2.5">
                        Send message
                        <svg class="btn-arrow w-4 h-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2 8h11M9 4l4 4-4 4"/>
                        </svg>
                    </span>
                </button>
            </form>

            <div class="mt-7 flex items-center gap-3 lift-in" style="animation-delay: 760ms;">
                <span class="h-px flex-1 bg-gradient-to-r from-transparent via-slate-300 to-transparent"></span>
                <p class="text-[12px] text-slate-500">
                    Already a distributor?
                    <a href="{{ route('login') }}" class="text-brand-600 hover:text-brand-700 font-medium underline-offset-4 hover:underline">Sign in →</a>
                </p>
                <span class="h-px flex-1 bg-gradient-to-r from-transparent via-slate-300 to-transparent"></span>
            </div>
        </div>

        <p class="mt-8 text-center text-[11px] text-slate-400 lift-in" style="animation-delay: 820ms;">
            Arovolife Private Limited &mdash; CIN U46909TS2026PTC210896
        </p>
    </div>

</body>
</html>
