@extends('admin.layouts.admin')
@section('title', 'Payout Settings')
@section('heading', 'Payout Settings')

@section('content')

@include('partials._toast-container')

@php
    $razorpaySelected = $gateway === 'razorpay';
    $credentialLabels = [
        'key_id'         => ['RAZORPAYX_KEY_ID', 'The RazorpayX API key. Its prefix decides test vs live.'],
        'key_secret'     => ['RAZORPAYX_KEY_SECRET', 'The matching secret. Never displayed, only checked for presence.'],
        'webhook_secret' => ['RAZORPAYX_WEBHOOK_SECRET', 'Verifies every payout webhook. Without it no transfer is ever confirmed.'],
        'account_number' => ['RAZORPAYX_ACCOUNT_NUMBER', 'The company current account each transfer is debited from.'],
    ];
    $missingCredentials = collect($credentials)->filter(fn ($present) => ! $present)->keys();
@endphp

<div class="mb-4">
    <a href="{{ route('admin.compensation.weekly-payouts.index') }}"
       class="text-sm text-brand-700 hover:underline">← Back to payout batches</a>
</div>

{{-- What this page is for --}}
<div class="mb-5 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    How approved payout batches reach distributors' banks. Changing the gateway applies from the
    <strong>next batch approved</strong> — batches already approved continue on the route they were approved under.
    See <a href="{{ route('admin.help.show', 'payout-operations') }}" class="underline font-medium">Payout Operations</a>
    for the full runbook.
</div>

{{-- ── Current gateway ─────────────────────────────────────────────── --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-5">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900">Current gateway</span>
        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium
                     {{ $razorpaySelected ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-700' }}">
            @if($razorpaySelected)
                <x-lucide-zap class="w-3 h-3" /> Razorpay Payouts
            @else
                <x-lucide-file-spreadsheet class="w-3 h-3" /> Manual NEFT
            @endif
        </span>
    </div>
    <div class="p-5 text-sm text-gray-700">
        @if($razorpaySelected)
            <p>
                Approving a batch hands every payable line item to the RazorpayX API and
                <strong>initiates real bank transfers</strong>. Each line is marked transferred only when Razorpay's
                payout webhook confirms it, and carries the bank's UTR from that same event.
            </p>
        @else
            <p>
                Approving a batch moves it to <strong>Approved</strong> and nothing else. Finance downloads the NEFT CSV,
                uploads it to the company bank, and imports the bank's response file back on the batch page —
                that import is what marks each line transferred or failed.
            </p>
        @endif
    </div>
</div>

{{-- ── RazorpayX credentials (env-derived, never editable here) ─────── --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-5">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between gap-3 flex-wrap">
        <span class="text-sm font-semibold text-gray-900 flex items-center gap-1">
            RazorpayX credentials
            <x-help-tip text="Read from the server environment, not the database. They cannot be set or viewed from this screen — only whether each one is present." />
        </span>
        <div class="flex items-center gap-2">
            @if($razorpayMode === 'live')
                <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-semibold bg-red-100 text-red-700">LIVE KEYS</span>
            @elseif($razorpayMode === 'test')
                <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-700">TEST KEYS</span>
            @else
                <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-600">NO KEY</span>
            @endif
            <button type="button" id="payout-test-connection"
                    data-url="{{ route('admin.compensation.payout-settings.test-connection') }}"
                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs font-medium text-gray-700 hover:bg-gray-50 transition-colors disabled:opacity-50">
                <x-lucide-plug-zap class="w-3.5 h-3.5" /> Test connection
            </button>
        </div>
    </div>

    @if($missingCredentials->isNotEmpty())
    <div class="mx-5 mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        Razorpay Payouts API credentials are not fully configured in <code>.env</code>. Contact your DevOps team.
        @if($razorpaySelected)
            <strong class="block mt-1">The gateway is set to Razorpay — batch approval is blocked until this is fixed.</strong>
        @endif
    </div>
    @endif

    <div class="p-5 grid gap-2">
        @foreach($credentialLabels as $key => [$envName, $note])
        <div class="flex items-start gap-3 text-sm">
            @if($credentials[$key])
                <x-lucide-check-circle-2 class="w-4 h-4 text-green-600 mt-0.5 shrink-0" />
            @else
                <x-lucide-x-circle class="w-4 h-4 text-red-500 mt-0.5 shrink-0" />
            @endif
            <div>
                <p class="font-mono text-xs font-medium text-gray-900">{{ $envName }}</p>
                <p class="text-xs text-gray-600">{{ $note }}</p>
            </div>
        </div>
        @endforeach
    </div>

    <div class="px-5 pb-5">
        <p class="text-xs text-gray-600">
            Webhook endpoint to register in the RazorpayX dashboard (events:
            <code>payout.processed</code>, <code>payout.failed</code>, <code>payout.reversed</code>):
        </p>
        <p class="mt-1 font-mono text-xs text-gray-900 break-all bg-gray-50 rounded px-2 py-1.5 border border-gray-200">{{ $webhookUrl }}</p>
    </div>

    <div id="payout-test-result" class="hidden px-5 pb-5 text-sm"></div>
</div>

{{-- ── The five levers ─────────────────────────────────────────────── --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm">
    <div class="px-5 py-3 border-b border-gray-100">
        <span class="text-sm font-semibold text-gray-900">Payout configuration</span>
    </div>

    @if($errors->any())
    <div class="mx-5 mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ $errors->first() }}
    </div>
    @endif

    <div class="divide-y divide-gray-100">
        @foreach($rows as $key => $row)
        @php $meta = $row['meta']; @endphp
        <div class="p-5 grid gap-3 lg:grid-cols-[1fr_auto] lg:items-start">
            <div class="max-w-3xl">
                <p class="text-sm font-semibold text-gray-900">{{ $meta['label'] }}</p>
                {{-- The registry writes emphasis as **…**; strip the markers
                     rather than rendering markdown, so this stays an escaped
                     string like every other description in the admin UI. --}}
                <p class="mt-1 text-xs text-gray-600 leading-relaxed">{{ str_replace('**', '', $meta['description']) }}</p>
                @isset($meta['impact'])
                <p class="mt-1 text-xs text-amber-700"><strong>Impact:</strong> {{ $meta['impact'] }}</p>
                @endisset
                <p class="mt-2 font-mono text-[11px] text-gray-500">{{ $key }}</p>
            </div>

            <div class="lg:w-72">
                @developer
                <form method="POST" action="{{ route('admin.settings.update', $key) }}"
                      data-confirm-title="Change payout configuration"
                      data-confirm="Change “{{ $meta['label'] }}”?"
                      data-confirm-impact="{{ $meta['impact'] ?? 'Applies from the next payout batch.' }}">
                    @csrf
                    @if($meta['type'] === 'enum')
                    <select name="value"
                            class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                        @foreach($meta['options'] as $option)
                        <option value="{{ $option['value'] }}" @selected($row['value'] === $option['value'])>
                            {{ $option['label'] }}
                        </option>
                        @endforeach
                    </select>
                    <ul class="mt-2 space-y-1">
                        @foreach($meta['options'] as $option)
                        @isset($option['note'])
                        <li class="text-[11px] text-gray-500"><strong>{{ $option['label'] }}:</strong> {{ $option['note'] }}</li>
                        @endisset
                        @endforeach
                    </ul>
                    @elseif($meta['type'] === 'int')
                    <input type="number" name="value" value="{{ $row['value'] }}"
                           min="{{ $meta['min'] ?? 0 }}" max="{{ $meta['max'] ?? 999999 }}" required
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                    @else
                    <input type="text" name="value" value="{{ $row['value'] }}"
                           maxlength="{{ $meta['max'] ?? 255 }}" required
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                    @endif
                    <button type="submit"
                            class="mt-2 w-full inline-flex items-center justify-center gap-1 px-3 py-1.5 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-sm font-semibold transition-colors">
                        Save
                    </button>
                </form>
                @else
                <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                    <p class="text-[11px] font-medium text-gray-500 uppercase tracking-wider">Current value</p>
                    <p class="mt-0.5 text-sm font-semibold text-gray-900 break-words">{{ $row['value'] !== '' ? $row['value'] : '—' }}</p>
                </div>
                @enddeveloper
            </div>
        </div>
        @endforeach
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const button = document.getElementById('payout-test-connection');
    const result = document.getElementById('payout-test-result');
    if (!button || !result) {
        return;
    }

    button.addEventListener('click', async function () {
        button.disabled = true;
        result.classList.remove('hidden', 'text-green-700', 'text-red-700');
        result.classList.add('text-gray-600');
        result.textContent = 'Contacting RazorpayX…';

        try {
            const response = await fetch(button.dataset.url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'Accept': 'application/json',
                },
            });
            const data = await response.json();
            result.classList.remove('text-gray-600');
            result.classList.add(data.status === 'ok' ? 'text-green-700' : 'text-red-700');
            result.textContent = data.message ?? 'No response from the server.';
        } catch (error) {
            result.classList.remove('text-gray-600');
            result.classList.add('text-red-700');
            result.textContent = 'The connection test could not be completed. Try again.';
        } finally {
            button.disabled = false;
        }
    });
});
</script>
@endpush

@endsection
