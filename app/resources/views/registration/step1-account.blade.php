@extends('layouts.wizard')
@section('title', 'Step 2 — Create Account')
@php $currentStep = 2; @endphp

@section('content')
<div class="max-w-xl">

    {{-- Heading uses the same Outfit-bold cadence as the homepage hero
         slider: small-caps eyebrow + bold sans headline + accent in solid
         brand colour. No italic, no serif. --}}
    <div class="mb-7 lift-in" style="animation-delay: 80ms;">
        <p class="text-sm font-medium text-brand-600 uppercase tracking-wider mb-3">A new arovolife distributor</p>
        <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 leading-tight mb-4">
            Create your <span class="text-brand-600">Direct Seller</span> account.
        </h1>
        <p class="text-lg text-gray-600 max-w-lg">
            Registration is <strong class="text-gray-800 font-semibold">free of charge</strong> and backed by India's
            Direct Selling Rules, 2021.
        </p>
    </div>

    {{-- Referral-link badge — sponsor + placement are locked at link time
         (ADR-0003). The user cannot edit them from inside the wizard. --}}
    @if(!empty($sponsorAdn))
    <div class="mb-6 lift-in flex items-center gap-3 rounded-xl border border-brand-200/70 bg-gradient-to-r from-brand-50 to-white px-4 py-3" style="animation-delay: 140ms;">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-brand-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 11H5a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h4"/>
            <path d="M15 11h4a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-4"/>
            <path d="M9 13h6"/>
            <circle cx="12" cy="6" r="3"/>
        </svg>
        <div class="text-[13px] leading-snug flex-1">
            <p class="text-slate-600">You were referred by</p>
            @if(!empty($sponsorName))
                <p class="text-slate-900 font-semibold text-sm">{{ $sponsorName }}</p>
                <p class="font-mono text-brand-700 text-[12px] tracking-wider mt-0.5">
                    ADN {{ $sponsorAdn }}
                </p>
            @else
                <p class="font-mono text-brand-700 font-semibold tracking-wider">{{ $sponsorAdn }}</p>
            @endif
        </div>
        @if($sideOpt)
            <span class="ml-auto text-[11px] uppercase tracking-wider text-brand-700/70 font-semibold whitespace-nowrap">Placed on the {{ $sideOpt === 'L' ? 'left' : 'right' }} group</span>
        @endif
    </div>
    @endif

    <form method="POST" action="{{ route('register.post') }}" class="card-refined p-7 sm:p-9 space-y-5 lift-in" style="animation-delay: 200ms;">
        @csrf

        @if ($existingUser)
            <div class="rounded-md bg-blue-50 border border-blue-200 p-4 mb-6">
                <p class="text-sm text-blue-800">
                    Welcome back. Enter your password to continue your registration.
                </p>
            </div>
        @endif

        {{-- Field grid — small caps eyebrow + refined input --}}
        <div class="lift-in" style="animation-delay: 320ms;">
            <label class="flex items-baseline justify-between mb-1.5">
                <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">Full name</span>
                <span class="text-[10px] text-sunrise-600 font-semibold uppercase tracking-wider">Required</span>
            </label>
            <input name="full_name" type="text" required autocomplete="name"
                value="{{ old('full_name', $existingUser?->full_name ?? '') }}"
                placeholder="Enter your full name"
                class="input-refined">
            @error('full_name')<p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <div class="lift-in" style="animation-delay: 380ms;">
            <label class="flex items-baseline justify-between mb-1.5">
                <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">Email address</span>
                <span class="text-[10px] text-sunrise-600 font-semibold uppercase tracking-wider">Required</span>
            </label>
            <input name="email" type="email" required autocomplete="email"
                value="{{ old('email', $existingUser?->email ?? '') }}"
                placeholder="you@example.com"
                maxlength="255"
                class="input-refined">
            <p id="email-availability" class="mt-1.5 text-xs hidden"></p>
            @error('email')<p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <div class="lift-in" style="animation-delay: 440ms;">
            <label class="flex items-baseline justify-between mb-1.5">
                <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">Mobile number</span>
                <span class="text-[10px] text-sunrise-600 font-semibold uppercase tracking-wider">Required</span>
            </label>
            <div class="flex">
                <span class="inline-flex items-center px-3.5 rounded-l-[0.65rem] border border-r-0 border-brand-200/50 bg-gradient-to-b from-brand-50 to-white text-slate-700 text-sm font-medium select-none">+91</span>
                <input name="phone_e164" type="tel" required autocomplete="tel"
                    value="{{ preg_replace('/^\+?91/', '', old('phone_e164', $existingUser?->phone_e164 ?? '')) }}"
                    placeholder="9876543210"
                    maxlength="10"
                    pattern="[6-9][0-9]{9}"
                    class="input-refined flex-1 !rounded-l-none">
            </div>
            <p class="mt-1.5 text-xs text-slate-500">10 digits, starting with 6–9.</p>
            <p id="phone-availability" class="mt-1.5 text-xs hidden"></p>
            @error('phone_e164')<p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div class="lift-in" style="animation-delay: 500ms;">
                <label class="flex items-baseline justify-between mb-1.5">
                    <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">Password</span>
                    <span class="text-[10px] text-sunrise-600 font-semibold uppercase tracking-wider">Required</span>
                </label>
                <input name="password" type="password" required autocomplete="new-password"
                    minlength="8" placeholder="••••••••"
                    class="input-refined font-mono tracking-widest">
                @error('password')<p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>@enderror
            </div>

            <div class="lift-in" style="animation-delay: 560ms;">
                <label class="flex items-baseline justify-between mb-1.5">
                    <span class="text-[11px] uppercase tracking-[0.18em] text-slate-500 font-semibold">Confirm</span>
                    <span class="text-[10px] text-sunrise-600 font-semibold uppercase tracking-wider">Required</span>
                </label>
                <input name="password_confirmation" type="password" required autocomplete="new-password"
                    minlength="8" placeholder="••••••••"
                    class="input-refined font-mono tracking-widest">
            </div>
        </div>

        <p class="text-[12px] text-slate-500 leading-relaxed lift-in" style="animation-delay: 580ms;">
            At least 8 characters. Long phrases of unrelated words work best. Common or breached passwords are rejected.
        </p>

        <div class="flex items-center justify-between gap-3 pt-2">
            <a href="{{ route('join.show') }}"
               class="inline-flex items-center px-5 py-3 rounded-full border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold transition-colors">
                ← Back
            </a>
            <button type="submit"
                class="btn-cta group flex-1 rounded-full bg-brand-500 hover:bg-brand-600 text-white py-3.5 text-sm font-semibold transition-all duration-200 focus:outline-none focus:ring-4 focus:ring-brand-300/40 lift-in shadow-lg shadow-brand-500/30 hover:shadow-xl hover:shadow-brand-500/40"
                style="animation-delay: 720ms;">
                <span class="inline-flex items-center justify-center gap-2.5">
                    Create account & continue
                    <svg class="btn-arrow w-4 h-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2 8h11M9 4l4 4-4 4"/>
                    </svg>
                </span>
            </button>
        </div>
    </form>

    {{-- Sign-in row + decorative tricolour rule (subtle nod to brand-leaf-sunrise = Indian palette) --}}
    <div class="mt-8 flex items-center gap-4 lift-in" style="animation-delay: 820ms;">
        <span class="h-px flex-1 bg-gradient-to-r from-transparent via-slate-300 to-transparent"></span>
        <p class="text-[12px] text-slate-500">
            Already have an account?
            <a href="{{ route('login') }}" class="text-brand-600 hover:text-brand-700 font-medium underline-offset-4 hover:underline">Sign in →</a>
        </p>
        <span class="h-px flex-1 bg-gradient-to-r from-transparent via-slate-300 to-transparent"></span>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    if (!form) return;

    const fields = form.querySelectorAll('input[name="full_name"], input[name="email"], input[name="phone_e164"], input[name="password"], input[name="password_confirmation"]');

    fields.forEach(field => {
        field.addEventListener('input', function() {
            const errorElement = this.parentElement.nextElementSibling;
            if (errorElement && errorElement.tagName === 'P' && errorElement.classList.contains('text-red-700')) {
                errorElement.style.display = 'none';
            }
        });
    });

    // Real-time availability check for email + phone. Fires on blur (when the
    // user moves off the field) and on debounced input. Catches duplicates
    // before the user invests time finishing the rest of the wizard.
    const checkUrl = @json(route('register.check-availability'));

    function renderStatus(el, state, message) {
        if (!el) return;
        el.classList.remove('hidden', 'text-red-700', 'text-leaf-700', 'text-slate-500');
        if (state === 'available') {
            el.classList.add('text-leaf-700');
            el.textContent = '✓ ' + message;
        } else if (state === 'taken') {
            el.classList.add('text-red-700');
            el.textContent = '✗ ' + message;
        } else if (state === 'checking') {
            el.classList.add('text-slate-500');
            el.textContent = message;
        } else {
            el.classList.add('hidden');
            el.textContent = '';
        }
    }

    async function checkAvailability(field, value, statusEl) {
        if (!value) {
            renderStatus(statusEl, 'hidden', '');
            return;
        }
        renderStatus(statusEl, 'checking', 'Checking…');
        try {
            const url = checkUrl + '?field=' + encodeURIComponent(field) + '&value=' + encodeURIComponent(value);
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) {
                renderStatus(statusEl, 'hidden', '');
                return;
            }
            const data = await res.json();
            if (data.available === true) {
                renderStatus(statusEl, 'available',
                    field === 'email' ? 'Email is available' : 'Mobile number is available');
            } else if (data.available === false) {
                renderStatus(statusEl, 'taken',
                    field === 'email'
                        ? 'An account with this email already exists. Please sign in instead.'
                        : 'An account already exists with this mobile number. Please sign in instead.');
            } else {
                renderStatus(statusEl, 'hidden', '');
            }
        } catch (e) {
            renderStatus(statusEl, 'hidden', '');
        }
    }

    function debounce(fn, ms) {
        let timer = null;
        return function(...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), ms);
        };
    }

    const emailInput = form.querySelector('input[name="email"]');
    const emailStatus = document.getElementById('email-availability');
    const phoneInput = form.querySelector('input[name="phone_e164"]');
    const phoneStatus = document.getElementById('phone-availability');

    if (emailInput && emailStatus) {
        const debouncedEmailCheck = debounce(() => {
            checkAvailability('email', emailInput.value.trim().toLowerCase(), emailStatus);
        }, 500);
        emailInput.addEventListener('input', debouncedEmailCheck);
        emailInput.addEventListener('blur', () => {
            checkAvailability('email', emailInput.value.trim().toLowerCase(), emailStatus);
        });
    }

    if (phoneInput && phoneStatus) {
        const debouncedPhoneCheck = debounce(() => {
            checkAvailability('phone', phoneInput.value.trim(), phoneStatus);
        }, 500);
        phoneInput.addEventListener('input', debouncedPhoneCheck);
        phoneInput.addEventListener('blur', () => {
            checkAvailability('phone', phoneInput.value.trim(), phoneStatus);
        });
    }
});
</script>
@endsection
