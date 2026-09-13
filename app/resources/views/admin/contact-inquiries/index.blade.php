@extends('admin.layouts.admin')
@section('title', 'Contact inbox')
@section('heading', 'Contact inbox')

@section('content')

<p class="text-sm text-gray-600 mb-5">
    Submissions from the public <code class="bg-gray-100 px-1 rounded text-[11px]">/contact-us</code> form.
    Personal data is bound by DPDP §6 — it's deleted automatically by the daily retention sweep
    (90 days for unhandled, 365 days from handled date for handled).
</p>

{{-- Handled/unhandled facets. These drive the same `filter` key as the
     toolbar's select, so chip, dropdown and Clear stay in agreement — and the
     queue size stays visible before you click, which is the whole point of
     this screen. --}}
@php
    $inquiryFacets = [
        'unhandled' => ['label' => 'Unhandled', 'count' => $unhandledCount, 'active' => 'bg-sunrise-800 text-white border-sunrise-500'],
        'handled'   => ['label' => 'Handled',   'count' => $handledCount,   'active' => 'bg-leaf-500 text-white border-leaf-500'],
        'all'       => ['label' => 'All',       'count' => $totalCount,     'active' => 'bg-gray-700 text-white border-gray-700'],
    ];
    $activeFilter = $filters->value('filter') ?? 'unhandled';
@endphp
<div class="mb-4 flex flex-wrap items-center gap-2">
    @foreach($inquiryFacets as $key => $facet)
        @php $isActive = $activeFilter === $key; @endphp
        <a href="{{ request()->fullUrlWithQuery(['filter' => $key, 'page' => null]) }}"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border text-xs font-semibold transition-colors {{ $isActive ? $facet['active'] : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">
            {{ $facet['label'] }}
            <span class="inline-flex items-center justify-center min-w-[22px] px-1.5 rounded-full {{ $isActive ? 'bg-white/25' : 'bg-gray-100 text-gray-600' }} text-[10px]">{{ $facet['count'] }}</span>
        </a>
    @endforeach
</div>

<x-filter-bar :filters="$filters" />

{{-- Table --}}
<div class="rounded-2xl border border-gray-200 bg-white overflow-hidden">
    @if($inquiries->isEmpty())
        <div class="p-8 text-center text-sm text-gray-600">No contact inquiries match this filter.</div>
    @else
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-[10px] uppercase tracking-wider text-gray-600 border-b border-gray-200">
            <tr>
                <th class="px-4 py-3 w-12">S.No</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Submitted</th>
                <th class="px-4 py-3">Name</th>
                <th class="px-4 py-3">Email</th>
                <th class="px-4 py-3">Purpose</th>
                <th class="px-4 py-3">Reason</th>
                <th class="px-4 py-3 text-right">Action</th>
            </tr>
        </thead>
        <tbody>
            @foreach($inquiries as $row)
            <tr class="border-b border-gray-100 last:border-0 hover:bg-gray-50/50">
                <td class="px-4 py-3 text-gray-600">{{ $loop->iteration }}</td>
                <td class="px-4 py-3">
                    @if($row->handled_at)
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-leaf-50 text-leaf-700 text-[10px] font-semibold">
                            <span class="w-1.5 h-1.5 rounded-full bg-leaf-500"></span>Handled
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-sunrise-50 text-sunrise-700 text-[10px] font-semibold">
                            <span class="w-1.5 h-1.5 rounded-full bg-sunrise-500"></span>Unhandled
                        </span>
                    @endif
                </td>
                <td class="px-4 py-3 text-gray-600 text-xs whitespace-nowrap">{{ $row->created_at->format('d M Y H:i') }}</td>
                <td class="px-4 py-3 text-gray-800 font-medium">{{ $row->name }}</td>
                <td class="px-4 py-3 text-gray-700 text-xs">
                    <a href="mailto:{{ $row->email }}" class="hover:text-brand-800 underline-offset-2 hover:underline">{{ $row->email }}</a>
                </td>
                <td class="px-4 py-3 text-gray-700 text-xs">{{ str_replace('_', ' ', $row->purpose) }}</td>
                <td class="px-4 py-3 text-gray-600 text-xs">{{ $row->reason ?? '—' }}</td>
                <td class="px-4 py-3 text-right">
                    <a href="{{ route('admin.contact-inquiries.show', $row->id) }}"
                        class="inline-flex items-center rounded-lg bg-brand-700 hover:bg-brand-800 text-white font-medium px-3 py-1.5 text-xs transition-colors">
                        View {{ svg('lucide-chevron-right', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }}
                    </a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    </div>
    @endif
</div>

<div class="mt-4">{{ $inquiries->links() }}</div>

@endsection
