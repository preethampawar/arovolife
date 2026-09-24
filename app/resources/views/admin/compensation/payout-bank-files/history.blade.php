@extends('admin.layouts.admin')
@section('title', 'All bank imports')
@section('heading', 'All bank response files — batch '.$batch->batch_date->format('d M Y'))

@section('content')

@php
    $imports = $history->imports;
    $resultCls = [
        'marked_paid' => 'text-green-700',
        'marked_failed' => 'text-red-700',
        'already_settled' => 'text-gray-500',
        'unmatched' => 'text-amber-700',
        'rejected_amount' => 'text-red-700',
        'rejected_utr' => 'text-red-700',
        'unrecognised_status' => 'text-amber-700',
    ];
    $resultLabels = [
        'marked_paid' => 'marked paid',
        'marked_failed' => 'marked failed',
        'already_settled' => 'already settled',
        'unmatched' => 'not in batch',
        'rejected_amount' => 'amount mismatch',
        'rejected_utr' => 'UTR already used',
        'unrecognised_status' => 'unrecognised status',
    ];
@endphp

<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <a href="{{ route($routeBase.'show', $batch) }}" class="text-sm text-brand-700 hover:underline">
        {{ svg('lucide-arrow-left', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }} Back to the batch
    </a>
    @if(count($imports) >= 2)
    <a href="{{ route($routeBase.'bank-files.compare', $batch) }}" class="text-sm text-brand-700 hover:underline">Compare two imports</a>
    @endif
</div>

<div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-sm text-gray-700">
    <p>
        One row per ADN, one column per imported bank response file (oldest first).
        @if($showAll)
            Showing all {{ $history->totalAdns }} ADN(s).
        @else
            Showing only the {{ count($history->rows) }} of {{ $history->totalAdns }} ADN(s) that two imports report differently. An ADN missing from a later file is not counted as a change.
        @endif
    </p>
    <a href="{{ route($routeBase.'bank-files.history', [$batch, 'all' => $showAll ? 0 : 1]) }}"
       class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
        {{ $showAll ? 'Show only differences' : 'Show all ADNs' }}
    </a>
</div>

<x-ui.card flush>
    @if($history->rows === [])
    <x-ui.empty-state :title="$history->totalAdns === 0 ? 'No bank response file has been imported for this batch yet.' : 'Every import says the same thing for every ADN.'" />
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-600 sticky left-0 bg-gray-50">ADN</th>
                    @foreach($imports as $import)
                    <th class="px-3 py-2 text-left text-gray-600 whitespace-nowrap">
                        Import #{{ $ordinals[$import->id] ?? '?' }}
                        <span class="block font-normal text-gray-500">{{ $import->created_at->format('d M Y H:i') }} · {{ $import->actor?->full_name ?? 'unknown' }}</span>
                    </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($history->rows as $adn => $cells)
                <tr class="align-top">
                    <td class="px-3 py-2 font-mono font-medium sticky left-0 bg-white">{{ $adn }}</td>
                    @foreach($imports as $import)
                    @php $row = $cells[$import->id] ?? null; @endphp
                    <td class="px-3 py-2 whitespace-nowrap">
                        @if($row === null)
                            <span class="text-gray-400">not in file</span>
                        @else
                            <div class="font-medium text-gray-900">{{ $row->bank_status ?? '—' }}</div>
                            @if($row->utr)<div class="font-mono text-gray-600">{{ $row->utr }}</div>@endif
                            @if($row->reason)<div class="text-gray-600 max-w-[180px] truncate" title="{{ $row->reason }}">{{ $row->reason }}</div>@endif
                            <div class="{{ $resultCls[$row->result] ?? 'text-gray-500' }}">{{ $resultLabels[$row->result] ?? $row->result }}</div>
                        @endif
                    </td>
                    @endforeach
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</x-ui.card>

@endsection
