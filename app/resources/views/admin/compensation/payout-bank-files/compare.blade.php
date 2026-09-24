@extends('admin.layouts.admin')
@section('title', 'Compare bank files')
@section('heading', 'Compare bank response files — batch '.$batch->batch_date->format('d M Y'))

@section('content')

@php
    $rupees = fn (?int $paise) => $paise === null ? '—' : '₹'.\App\Modules\Shared\Support\IndianNumber::format($paise / 100, 2);
    $from = $comparison->from;
    $to = $comparison->to;
    $labelOf = fn ($file) => 'Import #'.($ordinals[$file->id] ?? '?').' · '.$file->created_at->format('d M Y H:i').' · '.($file->actor?->full_name ?? 'unknown');
    $firstId = $imports->first()->id;
    $toIndex = $imports->search(fn ($f) => $f->id === $to->id);
    $previousId = $toIndex > 0 ? $imports[$toIndex - 1]->id : null;
    $kindLabels = [
        'new' => 'New in this file',
        'missing' => 'Missing from this file',
        'status' => 'Status changed',
        'utr' => 'UTR changed',
        'amount' => 'Amount changed',
        'reason' => 'Reason changed',
    ];
    $resultLabels = [
        'marked_paid' => 'marked paid',
        'marked_failed' => 'marked failed',
        'already_settled' => 'skipped — already settled',
        'unmatched' => 'not in this batch',
        'rejected_amount' => 'rejected — amount mismatch',
        'rejected_utr' => 'rejected — UTR already used',
        'unrecognised_status' => 'skipped — unrecognised status',
    ];
    $tabCls = fn (bool $active) => $active
        ? 'px-3 py-1.5 rounded-lg bg-brand-700 text-white text-sm font-medium'
        : 'px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50';
    $cell = function ($row, string $kind) use ($rupees, $resultLabels) {
        if ($row === null) {
            return '—';
        }

        return match ($kind) {
            'utr' => $row->utr ?? '—',
            'amount' => $rupees($row->amount_paise),
            'reason' => $row->reason ?? '—',
            default => ($row->bank_status ?? '—').' ('.($resultLabels[$row->result] ?? $row->result).')',
        };
    };
@endphp

<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <a href="{{ route($routeBase.'show', $batch) }}" class="text-sm text-brand-700 hover:underline">
        {{ svg('lucide-arrow-left', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }} Back to the batch
    </a>
    <a href="{{ route($routeBase.'bank-files.history', $batch) }}" class="text-sm text-brand-700 hover:underline">All imports side by side</a>
</div>

<x-ui.card class="mb-4">
    <div class="flex flex-wrap items-center gap-2 mb-4">
        @if($previousId !== null)
        <a href="{{ route($routeBase.'bank-files.compare', [$batch, 'from' => $previousId, 'to' => $to->id]) }}" class="{{ $tabCls($from->id === $previousId) }}">
            vs previous import
        </a>
        @endif
        @if($to->id !== $firstId)
        <a href="{{ route($routeBase.'bank-files.compare', [$batch, 'from' => $firstId, 'to' => $to->id]) }}" class="{{ $tabCls($from->id === $firstId) }}">
            vs first import (Import #1)
        </a>
        @endif
    </div>

    <form method="GET" action="{{ route($routeBase.'bank-files.compare', $batch) }}" class="flex flex-wrap items-end gap-3 text-sm">
        <div>
            <label for="from" class="block text-xs font-medium text-gray-700 mb-1">
                Earlier file <x-help-tip text="The file you are comparing from — usually the older one." />
            </label>
            <select id="from" name="from" class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                @foreach($imports as $import)
                <option value="{{ $import->id }}" @selected($import->id === $from->id)>{{ $labelOf($import) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="to" class="block text-xs font-medium text-gray-700 mb-1">
                Later file <x-help-tip text="The file you are comparing to. Differences read as earlier → later." />
            </label>
            <select id="to" name="to" class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                @foreach($imports as $import)
                <option value="{{ $import->id }}" @selected($import->id === $to->id)>{{ $labelOf($import) }}</option>
                @endforeach
            </select>
        </div>
        <x-ui.button><x-lucide-git-compare class="w-4 h-4" /> Compare</x-ui.button>
    </form>
</x-ui.card>

<p class="mb-3 text-sm text-gray-700">
    Comparing <strong>{{ $labelOf($from) }}</strong> → <strong>{{ $labelOf($to) }}</strong>.
    Rows are matched on ADN. {{ $comparison->unchanged }} ADN(s) are the same in both files.
</p>

@if($comparison->duplicates !== [])
<div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
    Listed more than once in a file (compared on their first row): {{ implode(', ', $comparison->duplicates) }}.
</div>
@endif

@if(($comparison->counts['missing'] ?? 0) > 0)
<p class="mb-3 text-xs text-gray-600">
    {{ $comparison->counts['missing'] }} ADN(s) are missing from the later file. That is normal when the later file covers only the payments sent again; they are listed last.
</p>
@endif

@if(! $comparison->hasDifferences())
<x-ui.card>
    <x-ui.empty-state title="No differences — both files say the same thing for every ADN." />
</x-ui.card>
@else
<div class="flex flex-wrap items-center gap-2 mb-3 text-xs">
    @foreach($comparison->counts as $kind => $count)
        @if($count > 0)
        <span class="inline-flex px-2 py-0.5 rounded bg-gray-100 font-medium text-gray-700">{{ $kindLabels[$kind] }}: {{ $count }}</span>
        @endif
    @endforeach
</div>

<x-ui.card flush>
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-600">ADN</th>
                    <th class="px-3 py-2 text-left text-gray-600">What changed</th>
                    <th class="px-3 py-2 text-left text-gray-600">Earlier file</th>
                    <th class="px-3 py-2 text-left text-gray-600">Later file</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($comparison->changes as $change)
                    @php
                        $fields = in_array('new', $change->kinds, true) || in_array('missing', $change->kinds, true)
                            ? ['status', 'utr', 'amount', 'reason']
                            : $change->kinds;
                    @endphp
                    <tr class="align-top">
                        <td class="px-3 py-2 font-mono font-medium">{{ $change->adn }}</td>
                        <td class="px-3 py-2">
                            @foreach($change->kinds as $kind)
                            <span class="inline-flex px-2 py-0.5 mr-1 mb-1 rounded text-[10px] font-medium {{ in_array($kind, ['new', 'missing'], true) ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700' }}">{{ $kindLabels[$kind] }}</span>
                            @endforeach
                        </td>
                        <td class="px-3 py-2 text-gray-700">
                            @foreach($fields as $field)
                            <div><span class="text-gray-500">{{ ucfirst($field === 'utr' ? 'UTR' : $field) }}:</span> {{ $cell($change->before, $field) }}</div>
                            @endforeach
                        </td>
                        <td class="px-3 py-2 text-gray-900">
                            @foreach($fields as $field)
                            <div><span class="text-gray-500">{{ ucfirst($field === 'utr' ? 'UTR' : $field) }}:</span> {{ $cell($change->after, $field) }}</div>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-ui.card>
@endif

@endsection
