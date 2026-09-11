@extends('layouts.app')
@section('title', 'Bank details')

@section('content')
@php
    $editLabel = 'block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5';
    $editInput = 'w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500';
    $hasBank = filled($distributor->bank_account_enc) && filled($distributor->bank_ifsc);
@endphp
<div class="max-w-2xl">
    <div class="flex items-center gap-2 mb-1">
        <x-lucide-landmark class="w-5 h-5 text-brand-700" />
        <h1 class="text-2xl font-bold text-gray-900">Bank details</h1>
    </div>
    <p class="text-sm text-gray-600 mb-6">The account arovolife transfers your income into.</p>

    {{-- Form-purpose note (platform convention). --}}
    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-6 text-sm text-blue-900">
        <p class="font-semibold mb-1">What this form does</p>
        <p class="leading-relaxed">
            It records the bank account your payouts are sent to. Type the account number twice so a
            mistyped digit cannot send your money to someone else, then confirm the 6-digit code we
            email you — nothing is saved until you do. Your account number is encrypted and only its
            last 4 digits are ever shown again.
        </p>
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

    {{-- What is on file today (masked). --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
        <h2 class="font-semibold text-gray-800 mb-3">On file now</h2>
        @if($hasBank)
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-y-3 gap-x-6 text-sm">
                <div>
                    <dt class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Account number</dt>
                    <dd class="font-mono text-gray-800">••••{{ $bankLast4 ?? '' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-gray-500 uppercase tracking-wider">IFSC</dt>
                    <dd class="font-mono text-gray-800">{{ $distributor->bank_ifsc }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Bank</dt>
                    <dd class="text-gray-800">{{ $distributor->bank_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Account holder</dt>
                    <dd class="text-gray-800">{{ $beneficiaryName ?? $user->full_name }}</dd>
                </div>
            </dl>
        @else
            <div class="flex items-start gap-2 text-sm text-amber-800">
                <x-lucide-triangle-alert class="w-4 h-4 mt-0.5 shrink-0" />
                <p>No bank account on file. Your income keeps accruing in your wallet, but it cannot be transferred until you add an account here.</p>
            </div>
        @endif
    </div>

    <form method="POST" action="{{ route('profile.bank.update') }}" class="bg-white rounded-2xl border border-gray-200 p-6 space-y-5"
        data-confirm="Send the 6-digit code and update these bank details?"
        data-confirm-title="Update bank details"
        data-confirm-impact="Your future payouts will be transferred to this account. Nothing is saved until you confirm the code we email you.">
        @csrf

        <div>
            <label for="account_number" class="{{ $editLabel }}">Bank account number <x-help-tip text="digits only, 9 to 18 of them — check it against your passbook or cheque leaf." /></label>
            <input type="text" id="account_number" name="account_number" inputmode="numeric" autocomplete="off" required
                   minlength="9" maxlength="18" pattern="\d{9,18}"
                   class="{{ $editInput }} font-mono">
        </div>

        <div>
            <label for="account_number_confirmation" class="{{ $editLabel }}">Re-type account number <x-help-tip text="typed twice on purpose: a wrong digit sends your payout to a stranger's account and the bank cannot reverse it." /></label>
            <input type="text" id="account_number_confirmation" name="account_number_confirmation" inputmode="numeric" autocomplete="off" required
                   minlength="9" maxlength="18" pattern="\d{9,18}"
                   onpaste="return false;"
                   class="{{ $editInput }} font-mono">
        </div>

        <div>
            <label for="ifsc" class="{{ $editLabel }}">IFSC code <x-help-tip text="11 characters printed on your cheque leaf — 4 letters, a zero, then 6 more characters (e.g. HDFC0001234)." /></label>
            <input type="text" id="ifsc" name="ifsc" required maxlength="11" pattern="[A-Za-z]{4}0[A-Za-z0-9]{6}"
                   value="{{ old('ifsc', $distributor->bank_ifsc) }}"
                   class="{{ $editInput }} font-mono uppercase">
        </div>

        <div>
            <label for="bank_name" class="{{ $editLabel }}">Bank name <span class="text-gray-400 normal-case font-normal">(optional)</span> <x-help-tip text="the bank and branch, if you want it on record — the IFSC already identifies your branch." /></label>
            <input type="text" id="bank_name" name="bank_name" maxlength="120"
                   value="{{ old('bank_name', $distributor->bank_name) }}"
                   class="{{ $editInput }}">
        </div>

        <div>
            <label for="beneficiary_name" class="{{ $editLabel }}">Account holder name <x-help-tip text="the name your bank holds against this account. If it does not match, the bank may reject the transfer." /></label>
            <input type="text" id="beneficiary_name" name="beneficiary_name" required maxlength="120"
                   value="{{ old('beneficiary_name', $beneficiaryName ?? $user->full_name) }}"
                   class="{{ $editInput }}">
        </div>

        <div class="flex items-center justify-between pt-2">
            <a href="{{ route('profile.show') }}" class="text-sm text-gray-600 hover:text-gray-700">← Back to my profile</a>
            <button type="submit" class="px-5 py-2.5 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-sm font-semibold shadow-sm transition-colors">
                {{ $hasBank ? 'Update bank details' : 'Add bank details' }}
            </button>
        </div>
    </form>
</div>

{{-- ── OTP confirmation modal ─────────────────────────────────────────────
     Same gate as a mobile/email change: the details are held until the code
     emailed to the distributor is confirmed. --}}
@if(session('bank_otp'))
@php $bankOtpCtx = session('bank_otp'); @endphp
<div id="bankOtpModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4"
    role="dialog" aria-modal="true" aria-label="Confirm your code">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-200">
            <p class="text-base font-bold text-gray-900">Verify it's you</p>
            <p class="text-sm text-gray-600 mt-1">We've emailed a 6-digit code to <strong>{{ $bankOtpCtx['email_masked'] ?? 'your email' }}</strong>. Enter it to save your bank details. Nothing is saved until you confirm.</p>
        </div>
        <form method="POST" action="{{ route('profile.bank.otp.confirm') }}" class="px-6 py-5 space-y-4">
            @csrf
            @error('otp')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <div>
                <label for="bank_otp" class="block text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1.5">6-digit code</label>
                <input type="text" id="bank_otp" name="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required autofocus
                       class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-center text-lg font-mono tracking-[0.4em] focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500"
                       placeholder="••••••">
            </div>
            <button type="submit" class="w-full px-5 py-2.5 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-sm font-semibold transition-colors">Confirm</button>
        </form>
        <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-between text-sm">
            <form method="POST" action="{{ route('profile.bank.otp.resend') }}">
                @csrf
                <button type="submit" id="bankOtpResendBtn" class="text-brand-700 hover:text-brand-800 font-medium disabled:opacity-50 disabled:cursor-not-allowed">Resend code</button>
            </form>
            <a href="{{ route('profile.bank.show') }}" class="text-gray-600 hover:text-gray-700">Cancel</a>
        </div>
    </div>
</div>
<script>
    // Resend stays disabled for 30s after the modal opens, with a live countdown.
    (function () {
        var btn = document.getElementById('bankOtpResendBtn');
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
