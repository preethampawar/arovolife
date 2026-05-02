@extends('admin.layouts.admin')
@section('title', 'Contact inbox')
@section('heading', 'Contact inbox')

@section('content')

<p class="text-sm text-gray-600 mb-5">
    Submissions from the public <code class="bg-gray-100 px-1 rounded text-[11px]">/contact-us</code> form.
    Personal data is bound by DPDP §6 — it's deleted automatically by the daily retention sweep
    (90 days for unhandled, 365 days from handled date for handled).
</p>

{{-- Filter chips + search --}}
<form method="GET" action="{{ route('admin.contact-inquiries.index') }}" class="mb-4 flex flex-wrap items-center gap-2">
    @php
        $chips = [
            'unhandled' => ['label' => 'Unhandled', 'count' => $unhandledCount, 'tone' => 'sunrise'],
            'handled'   => ['label' => 'Handled',   'count' => $handledCount,   'tone' => 'leaf'],
            'all'       => ['label' => 'All',       'count' => $totalCount,     'tone' => 'gray'],
        ];
    @endphp
    @foreach($chips as $key => $cfg)
        @php
            $active = $filter === $key;
            $toneCls = match($cfg['tone']) {
                'sunrise' => $active ? 'bg-sunrise-500 text-white border-sunrise-500' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50',
                'leaf'    => $active ? 'bg-leaf-500 text-white border-leaf-500'       : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50',
                default   => $active ? 'bg-gray-700 text-white border-gray-700'       : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50',
            };
        @endphp
        <a href="{{ route('admin.contact-inquiries.index', array_filter(['filter' => $key, 'purpose' => $purpose ?: null, 'q' => $search ?: null])) }}"
           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border text-xs font-semibold transition-colors {{ $toneCls }}">
            {{ $cfg['label'] }}
            <span class="inline-flex items-center justify-center min-w-[22px] px-1.5 rounded-full {{ $active ? 'bg-white/25' : 'bg-gray-100 text-gray-600' }} text-[10px]">{{ $cfg['count'] }}</span>
        </a>
    @endforeach

    <select name="purpose" onchange="this.form.submit()"
        class="ml-2 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500">
        <option value="" {{ $purpose === '' ? 'selected' : '' }}>All purposes</option>
        @foreach(['become_distributor' => 'Become a Direct Seller', 'support' => 'Support', 'compliance' => 'Compliance', 'partnership' => 'Partnership', 'other' => 'Other'] as $val => $label)
            <option value="{{ $val }}" {{ $purpose === $val ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
    </select>
    <input type="hidden" name="filter" value="{{ $filter }}">

    <div class="flex-1 min-w-[200px] flex items-center gap-2">
        <input type="search" name="q" value="{{ $search }}" placeholder="Search by name, email, phone…"
            class="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500">
        <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-xs font-semibold transition-colors">Search</button>
    </div>
</form>

{{-- Table --}}
<div class="rounded-2xl border border-gray-200 bg-white overflow-hidden">
    @if($inquiries->isEmpty())
        <div class="p-8 text-center text-sm text-gray-500">No contact inquiries match this filter.</div>
    @else
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-[10px] uppercase tracking-wider text-gray-500 border-b border-gray-200">
            <tr>
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
                    <a href="mailto:{{ $row->email }}" class="hover:text-brand-600 underline-offset-2 hover:underline">{{ $row->email }}</a>
                </td>
                <td class="px-4 py-3 text-gray-700 text-xs">{{ str_replace('_', ' ', $row->purpose) }}</td>
                <td class="px-4 py-3 text-gray-500 text-xs">{{ $row->reason ?? '—' }}</td>
                <td class="px-4 py-3 text-right">
                    <a href="{{ route('admin.contact-inquiries.show', $row->id) }}"
                        class="inline-flex items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-3 py-1.5 text-xs transition-colors">
                        View →
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
