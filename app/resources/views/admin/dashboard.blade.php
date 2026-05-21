@extends('admin.layouts.admin')
@section('title', 'Dashboard')
@section('heading', 'Dashboard')

@section('content')

{{-- Stats Grid --}}
@php
    // Each tile click-throughs to the distributors list with the filter
    // pre-applied that matches the row count shown. "Audit Events Today"
    // is the odd one out — it isn't a distributors filter; it links to
    // the audit-log page scoped to today's window via from/to.
    $todayIso = now()->toDateString();

    $cards = [
        [
            'label'    => 'Total Users',
            'value'    => $stats['total_users'],
            'hint'     => 'All registered accounts',
            'tone'     => 'brand',
            'href'     => route('admin.distributors.index'),
            'icon'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />',
        ],
        [
            'label'    => 'Active Distributors',
            'value'    => $stats['active_distributors'],
            'hint'     => 'With issued ADN',
            'tone'     => 'green',
            'href'     => route('admin.distributors.index', ['status' => 'active']),
            'icon'     => '<path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />',
        ],
        [
            'label'    => 'Pending Registration',
            'value'    => $stats['pending_users'],
            'hint'     => 'Incomplete signups',
            'tone'     => 'amber',
            'href'     => route('admin.distributors.index', ['status' => 'pending']),
            'icon'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />',
        ],
        [
            'label'    => 'Cooling-Off Active',
            'value'    => $stats['cooling_off_active'],
            'hint'     => 'Within 30-day window',
            'tone'     => 'sky',
            'href'     => route('admin.distributors.index', ['cooling_off' => 'active']),
            'icon'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15a4.5 4.5 0 0 0 4.5 4.5H18a3.75 3.75 0 0 0 1.332-7.257 3 3 0 0 0-3.758-3.848 5.25 5.25 0 0 0-10.233 2.33A4.502 4.502 0 0 0 2.25 15Z" />',
        ],
        [
            'label'    => 'Expiring (7 days)',
            'value'    => $stats['cooling_off_expiring'],
            'hint'     => 'Cooling-off ending soon',
            'tone'     => 'red',
            'href'     => route('admin.distributors.index', ['cooling_off' => 'expiring']),
            'icon'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />',
        ],
        [
            'label'    => 'Frozen Accounts',
            'value'    => $stats['frozen_users'],
            'hint'     => 'Suspended by compliance',
            'tone'     => 'slate',
            'href'     => route('admin.distributors.index', ['status' => 'frozen']),
            'icon'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />',
        ],
        [
            'label'    => 'Audit Events Today',
            'value'    => $stats['audit_entries_today'],
            'hint'     => 'System activity',
            'tone'     => 'violet',
            'href'     => route('admin.audit-log', ['from' => $todayIso, 'to' => $todayIso]),
            'icon'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />',
        ],
    ];

    $tones = [
        'brand'  => ['bg' => 'bg-brand-50',  'fg' => 'text-brand-600'],
        'green'  => ['bg' => 'bg-green-50',  'fg' => 'text-green-600'],
        'amber'  => ['bg' => 'bg-amber-50',  'fg' => 'text-amber-600'],
        'sky'    => ['bg' => 'bg-sky-50',    'fg' => 'text-sky-600'],
        'red'    => ['bg' => 'bg-red-50',    'fg' => 'text-red-600'],
        'slate'  => ['bg' => 'bg-slate-100', 'fg' => 'text-slate-600'],
        'violet' => ['bg' => 'bg-violet-50', 'fg' => 'text-violet-600'],
    ];
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    @foreach($cards as $card)
    @php $t = $tones[$card['tone']]; @endphp
    <a href="{{ $card['href'] }}"
       class="group block bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md hover:border-gray-300 transition-all cursor-pointer">
        <div class="flex items-start justify-between mb-4">
            <div class="flex h-10 w-10 items-center justify-center rounded-lg {{ $t['bg'] }} {{ $t['fg'] }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-5 h-5">
                    {!! $card['icon'] !!}
                </svg>
            </div>
            <span class="text-gray-500 group-hover:text-brand-500 transition-colors text-lg leading-none" aria-hidden="true">→</span>
        </div>
        <p class="text-xs uppercase tracking-wider text-gray-700 font-medium mb-1">{{ $card['label'] }}</p>
        <p class="text-3xl font-bold text-gray-900 leading-none mb-2">{{ number_format($card['value']) }}</p>
        <p class="text-xs text-gray-600">{{ $card['hint'] }}</p>
    </a>
    @endforeach
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    {{-- Recent Distributors --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="font-semibold text-gray-800">Recent Registrations</h3>
            <a href="{{ route('admin.distributors.index') }}" class="text-xs text-brand-600 hover:text-brand-700 font-medium">View all →</a>
        </div>
        <div class="divide-y divide-gray-100">
            @forelse($recentDistributors as $d)
            <div class="px-6 py-3 flex items-center justify-between hover:bg-gray-50 transition-colors">
                <div>
                    <a href="{{ route('admin.distributors.show', $d->id) }}"
                       class="text-sm font-mono font-medium text-brand-600 hover:text-brand-700">{{ $d->adn }}</a>
                    <p class="text-xs text-gray-700">{{ $d->full_name ?? $d->email }}</p>
                </div>
                <div class="text-right">
                    <span class="text-xs px-2 py-0.5 rounded-full
                        {{ $d->status === 'active' ? 'bg-green-50 text-green-700 border border-green-200'
                         : ($d->status === 'frozen' ? 'bg-red-50 text-red-700 border border-red-200'
                         : 'bg-amber-50 text-amber-700 border border-amber-200') }}">
                        {{ $d->status }}
                    </span>
                    <p class="text-xs text-gray-600 mt-0.5">{{ \Carbon\Carbon::parse($d->effective_date)->format('d M Y') }}</p>
                </div>
            </div>
            @empty
            <p class="px-6 py-6 text-sm text-gray-700 text-center">No distributors yet.</p>
            @endforelse
        </div>
    </div>

    {{-- Recent Audit Log --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="font-semibold text-gray-800">Recent Audit Events</h3>
            <a href="{{ route('admin.audit-log') }}" class="text-xs text-brand-600 hover:text-brand-700 font-medium">View all →</a>
        </div>
        <div class="divide-y divide-gray-100">
            @forelse($recentAudit as $log)
            <div class="px-6 py-3 hover:bg-gray-50 transition-colors">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-sm text-gray-800 leading-snug">{{ $log->display_title }}</p>
                    <span class="text-[11px] text-gray-600 whitespace-nowrap shrink-0 pt-0.5">
                        {{ \Carbon\Carbon::parse($log->created_at)->diffForHumans() }}
                    </span>
                </div>
                @if($log->display_subtitle)
                    <p class="text-xs text-gray-700 mt-1">{{ $log->display_subtitle }}</p>
                @endif
            </div>
            @empty
            <p class="px-6 py-6 text-sm text-gray-700 text-center">No audit events yet.</p>
            @endforelse
        </div>
    </div>

</div>

@endsection
