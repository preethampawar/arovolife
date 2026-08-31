@extends('layouts.wizard')
@section('title', 'Step 2 — Create Account')
@php $currentStep = 2; @endphp

@section('content')
<div class="max-w-2xl mx-auto">

    <h2 class="text-2xl font-bold mb-2">Create Your Account</h2>
    <p class="text-gray-600 text-sm mb-6">
        Registration is <strong class="text-gray-800 font-semibold">free of charge</strong> and backed by India's
        Direct Selling Rules, 2021.
    </p>

    {{-- Referral-link badge — sponsor + placement are locked at link time
         (ADR-0003). The user cannot edit them from inside the wizard. --}}
    @if(!empty($sponsorAdn))
    <div class="mb-6 flex items-center gap-3 rounded-xl border border-brand-200/70 bg-gradient-to-r from-brand-50 to-white px-4 py-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-brand-700 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
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

    <form method="POST" action="{{ route('register.post') }}" class="space-y-5 bg-white rounded-2xl border border-gray-200 p-8">
        @csrf

        @if ($existingUser)
            <div class="rounded-md bg-blue-50 border border-blue-200 p-4 mb-6">
                <p class="text-sm text-blue-800">
                    Welcome back. Enter your password to continue your registration.
                </p>
            </div>
        @endif

        {{-- Fields --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Full name <span class="text-red-700">*</span></label>
            <input name="full_name" type="text" required autocomplete="name"
                value="{{ old('full_name', $existingUser?->full_name ?? '') }}"
                placeholder="Enter your full name"
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            @error('full_name')<p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Email address <span class="text-red-700">*</span></label>
            <input name="email" type="email" required autocomplete="email"
                value="{{ old('email', $existingUser?->email ?? '') }}"
                placeholder="you@example.com"
                maxlength="255"
                class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            <p id="email-availability" class="mt-1.5 text-xs hidden"></p>
            @error('email')<p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Mobile number <span class="text-red-700">*</span></label>
            <div class="flex">
                <span class="inline-flex items-center px-3.5 rounded-l-lg border border-r-0 border-gray-200 bg-gray-50 text-gray-700 text-sm font-medium select-none">+91</span>
                <input name="phone_e164" type="tel" required autocomplete="tel"
                    value="{{ preg_replace('/^\+?91/', '', old('phone_e164', $existingUser?->phone_e164 ?? '')) }}"
                    placeholder="9876543210"
                    maxlength="10"
                    pattern="[6-9][0-9]{9}"
                    class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent flex-1 rounded-l-none">
            </div>
            <p class="mt-1.5 text-xs text-gray-600">10 digits, starting with 6–9.</p>
            <p id="phone-availability" class="mt-1.5 text-xs hidden"></p>
            @error('phone_e164')<p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Password <span class="text-red-700">*</span></label>
                <input name="password" type="password" required autocomplete="new-password"
                    minlength="8" placeholder="••••••••"
                    class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent font-mono tracking-widest">
                @error('password')<p class="mt-1.5 text-xs text-red-700">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Confirm <span class="text-red-700">*</span></label>
                <input name="password_confirmation" type="password" required autocomplete="new-password"
                    minlength="8" placeholder="••••••••"
                    class="w-full rounded-lg bg-white border border-gray-200 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent font-mono tracking-widest">
            </div>
        </div>

        <p class="text-xs text-gray-600 leading-relaxed">
            At least 8 characters. Long phrases of unrelated words work best. Common or breached passwords are rejected.
        </p>

        <div class="flex items-center justify-between gap-3 pt-2">
            <a href="{{ route('join.show') }}"
               class="inline-flex items-center px-5 py-3 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold transition-colors">
                ← Back
            </a>
            <button type="submit"
                class="flex-1 rounded-lg bg-brand-700 hover:bg-brand-800 text-white font-semibold py-3 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500">
                Create Account &amp; Continue →
            </button>
        </div>
    </form>

    {{-- Sign-in row + decorative tricolour rule (subtle nod to brand-leaf-sunrise = Indian palette) --}}
    <div class="mt-8 flex items-center gap-4">
        <span class="h-px flex-1 bg-gradient-to-r from-transparent via-slate-300 to-transparent"></span>
        <p class="text-[12px] text-slate-500">
            Already have an account?
            <a href="{{ route('login') }}" class="text-brand-700 hover:text-brand-800 font-medium underline-offset-4 hover:underline">Sign in →</a>
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
