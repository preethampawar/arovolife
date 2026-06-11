@extends('layouts.app')
@section('title', 'My profile')

@section('content')
@php
    // Shared classes for the locked (read-only) identity fields.
    $lockedInput = 'w-full rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-500';
    $editLabel = 'block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5';
    $editInput = 'w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500';
    $lockTip = 'this is part of your verified identity and can only be changed by arovolife after KYC review.';
@endphp
<div class="max-w-2xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 mb-1">My profile</h1>
    <p class="text-sm text-gray-500 mb-6">Your verified identity is shown for reference; update the contact details we use to reach you below.</p>

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
            <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold shadow-sm transition-colors">Save changes</button>
        </div>
    </form>
</div>

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
                <button type="submit" class="flex-1 px-5 py-2.5 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition-colors">Confirm</button>
            </div>
        </form>
        <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm">
            <form method="POST" action="{{ route('profile.otp.resend') }}">
                @csrf
                <button type="submit" id="otpResendBtn" class="text-brand-700 hover:text-brand-800 font-medium disabled:opacity-50 disabled:cursor-not-allowed">Resend code</button>
            </form>
            <a href="{{ route('profile.show') }}" class="text-gray-500 hover:text-gray-700">Cancel</a>
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
