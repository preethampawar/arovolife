@extends('admin.layouts.admin')
@section('title', 'Delete a partner')
@section('heading', 'Delete a partner')

@section('content')

@php
    $members = collect($preview->members);
    $children = $members->count();
    $leftAdns = $preview->leftAdns;
    $rightAdns = $preview->rightAdns;
    $couple = $members->where('leg', null);
    $short = fn (array $adns): string => count($adns) === 0
        ? 'none'
        : implode(', ', array_slice($adns, 0, 20)).(count($adns) > 20 ? ' (+'.(count($adns) - 20).' more)' : '');
    $confirmMessage = "APN {$preview->targetAdn} and its {$children} children will be permanently deleted. "
        .'Left Genos: '.$short($leftAdns).'. Right Genos: '.$short($rightAdns).'.'
        .($couple->isNotEmpty() ? ' Couple partner: '.$short($couple->pluck('adn')->all()).'.' : '');
    $confirmImpact = 'Personal data, KYC, BV and ranks are removed. Orders, invoices and wallet rows stay without personal details. This cannot be undone.';
@endphp

@if(!empty($refusals))
    <div class="mb-5 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 max-w-3xl">
        <x-lucide-circle-alert class="w-4 h-4 shrink-0 mt-px" />
        <div class="space-y-1">
            <p class="font-semibold">Nothing was deleted.</p>
            @foreach($refusals as $message)
                <p>{{ $message }}</p>
            @endforeach
            <p>The list below is the current state. Review it again before deleting.</p>
        </div>
    </div>
@endif

<div class="mb-5 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 max-w-3xl">
    <x-lucide-triangle-alert class="w-4 h-4 shrink-0 mt-px" />
    <div>This permanently deletes the partners listed below. There is no undo, and no record of the deletion is kept.</div>
</div>

<x-ui.card class="mb-5">
    <p class="text-xs font-medium uppercase tracking-wider text-gray-700">Partner to delete</p>
    <p class="mt-1 font-mono text-lg font-semibold text-gray-900">{{ $preview->targetAdn }}</p>
    <p class="text-sm text-gray-800">{{ $preview->targetName ?: 'No name on record' }}</p>
    <p class="mt-3 text-sm text-gray-800">
        {{ $children === 0 ? 'No one is placed below this partner.' : '1 partner plus '.$children.' below them, '.($children + 1).' in total, will be deleted.' }}
    </p>
</x-ui.card>

<div class="grid gap-5 md:grid-cols-2 mb-5">
    @foreach(['L' => ['Left Genos', $leftAdns], 'R' => ['Right Genos', $rightAdns]] as $leg => [$label, $adns])
        <x-ui.card flush>
            <div class="px-4 py-3 border-b border-gray-200">
                <h2 class="text-sm font-semibold text-gray-900">{{ $label }} ({{ count($adns) }})</h2>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 bg-gray-50/50">
                        <th class="text-left px-4 py-2 text-xs font-medium text-gray-700 uppercase tracking-wider">APN</th>
                        <th class="text-left px-4 py-2 text-xs font-medium text-gray-700 uppercase tracking-wider">Depth</th>
                        <th class="text-left px-4 py-2 text-xs font-medium text-gray-700 uppercase tracking-wider">Side</th>
                        <th class="text-left px-4 py-2 text-xs font-medium text-gray-700 uppercase tracking-wider">Parent APN</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse($members->where('leg', $leg) as $m)
                        <tr>
                            <td class="px-4 py-2 font-mono">{{ $m['adn'] }}@if($m['isSpouse']) <span class="text-xs text-gray-600">(couple partner)</span>@endif</td>
                            <td class="px-4 py-2 text-gray-800">{{ $m['depth'] ?? '—' }}</td>
                            <td class="px-4 py-2 text-gray-800">{{ $m['side'] === 'L' ? 'Left' : ($m['side'] === 'R' ? 'Right' : '—') }}</td>
                            <td class="px-4 py-2 font-mono text-gray-800">{{ $m['parentAdn'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <x-ui.empty-state colspan="4" title="No partners on this side." />
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    @endforeach
</div>

@if($couple->isNotEmpty())
    <x-ui.card class="mb-5">
        <h2 class="text-sm font-semibold text-gray-900 mb-2">Couple partner ({{ $couple->count() }})</h2>
        <p class="text-xs text-gray-700 mb-2">Couples are deleted together, so these partners are included even though they are not in the Genos below.</p>
        <ul class="space-y-1 text-sm font-mono">
            @foreach($couple as $m)
                <li>{{ $m['adn'] }}</li>
            @endforeach
        </ul>
    </x-ui.card>
@endif

<x-ui.card class="mb-5">
    <h2 class="text-sm font-semibold text-gray-900 mb-2">Records that stay, without personal details</h2>
    <ul class="grid gap-1 text-sm text-gray-800 sm:grid-cols-2">
        <li>Orders: {{ $preview->counts['orders'] }}</li>
        <li>Invoices: {{ $preview->counts['invoices'] }}</li>
        <li>Wallet entries: {{ $preview->counts['wallet_entries'] }}</li>
        <li>KYC documents deleted: {{ $preview->counts['kyc_documents'] }}</li>
    </ul>
</x-ui.card>

@if(! $preview->canErase())
    <div class="mb-5 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 max-w-3xl">
        <x-lucide-circle-alert class="w-4 h-4 shrink-0 mt-px" />
        <div class="space-y-1">
            <p class="font-semibold">This partner cannot be deleted yet.</p>
            <ul class="list-disc pl-4 space-y-0.5">
                @foreach($preview->blockers as $blocker)
                    <li>{{ $blocker['message'] }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

<form method="POST" action="{{ route('admin.distributors.erase.destroy') }}" class="max-w-3xl space-y-4"
      data-confirm="{{ $confirmMessage }}"
      data-confirm-title="Permanently delete APN {{ $preview->targetAdn }}?"
      data-confirm-impact="{{ $confirmImpact }}">
    @csrf
    <input type="hidden" name="adn" value="{{ $preview->targetAdn }}">
    <input type="hidden" name="fingerprint" value="{{ $preview->fingerprint }}">
    <label class="block text-xs font-medium text-gray-700">
        Type the APN again to confirm
        <input type="text" name="confirm_adn" required inputmode="numeric" maxlength="16" autocomplete="off" @disabled(! $preview->canErase())
            class="mt-1 block w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm disabled:opacity-50">
    </label>
    @error('confirm_adn')
        <p class="text-xs text-red-700">{{ $message }}</p>
    @enderror
    <div class="flex items-center gap-3">
        <x-ui.button variant="danger" icon="trash-2" :disabled="! $preview->canErase()">Delete permanently</x-ui.button>
        <a href="{{ route('admin.distributors.erase.form') }}" class="text-sm text-gray-700 hover:text-gray-900">Start over</a>
    </div>
</form>

@endsection
