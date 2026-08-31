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
            'icon'     => 'users',
        ],
        [
            'label'    => 'Active Distributors',
            'value'    => $stats['active_distributors'],
            'hint'     => 'With issued ADN',
            'tone'     => 'green',
            'href'     => route('admin.distributors.index', ['status' => 'active']),
            'icon'     => 'check',
        ],
        [
            'label'    => 'Pending Registration',
            'value'    => $stats['pending_users'],
            'hint'     => 'Incomplete signups',
            'tone'     => 'amber',
            'href'     => route('admin.distributors.index', ['status' => 'pending']),
            'icon'     => 'clock',
        ],
        [
            'label'    => 'Cooling-Off Active',
            'value'    => $stats['cooling_off_active'],
            'hint'     => 'Within 30-day window',
            'tone'     => 'sky',
            'href'     => route('admin.distributors.index', ['cooling_off' => 'active']),
            'icon'     => 'cloud',
        ],
        [
            'label'    => 'Expiring (7 days)',
            'value'    => $stats['cooling_off_expiring'],
            'hint'     => 'Cooling-off ending soon',
            'tone'     => 'red',
            'href'     => route('admin.distributors.index', ['cooling_off' => 'expiring']),
            'icon'     => 'triangle-alert',
        ],
        [
            'label'    => 'Blocked Accounts',
            'value'    => $stats['frozen_users'],
            'hint'     => 'Blocked by compliance',
            'tone'     => 'slate',
            'href'     => route('admin.distributors.index', ['status' => 'frozen']),
            'icon'     => 'lock',
        ],
        [
            'label'    => 'Audit Events Today',
            'value'    => $stats['audit_entries_today'],
            'hint'     => 'System activity',
            'tone'     => 'violet',
            'href'     => route('admin.audit-log', ['from' => $todayIso, 'to' => $todayIso]),
            'icon'     => 'file-text',
        ],
    ];

    $tones = [
        'brand'  => ['bg' => 'bg-brand-50',  'fg' => 'text-brand-700'],
        'green'  => ['bg' => 'bg-green-50',  'fg' => 'text-green-600'],
        'amber'  => ['bg' => 'bg-amber-50',  'fg' => 'text-amber-700'],
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
                {{ svg('lucide-'.$card['icon'], 'w-5 h-5') }}
            </div>
            <span class="text-gray-600 group-hover:text-brand-800 transition-colors text-lg leading-none" aria-hidden="true">→</span>
        </div>
        <p class="text-xs uppercase tracking-wider text-gray-700 font-medium mb-1">{{ $card['label'] }}</p>
        <p class="text-3xl font-bold text-gray-900 leading-none mb-2">{{ \App\Modules\Shared\Support\IndianNumber::format($card['value']) }}</p>
        <p class="text-xs text-gray-600">{{ $card['hint'] }}</p>
    </a>
    @endforeach
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    {{-- Recent Distributors --}}
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="font-semibold text-gray-800">Recent Registrations</h3>
            <a href="{{ route('admin.distributors.index') }}" class="text-xs text-brand-700 hover:text-brand-800 font-medium">View all →</a>
        </div>
        <div class="divide-y divide-gray-100">
            @forelse($recentDistributors as $d)
            <div class="px-6 py-3 flex items-center justify-between hover:bg-gray-50 transition-colors">
                <div>
                    <a href="{{ route('admin.distributors.show', $d->id) }}"
                       class="text-sm font-mono font-medium text-brand-700 hover:text-brand-800">{{ $d->adn }}</a>
                    <p class="text-xs text-gray-700">{{ $d->full_name ?? $d->email }}</p>
                </div>
                <div class="text-right">
                    <span class="text-xs px-2 py-0.5 rounded-full
                        {{ $d->status === 'active' ? 'bg-green-50 text-green-700 border border-green-200'
                         : ($d->status === 'frozen' ? 'bg-red-50 text-red-700 border border-red-200'
                         : 'bg-amber-50 text-amber-700 border border-amber-200') }}">
                        {{ \App\Modules\Identity\Models\User::STATUS_LABELS[$d->status] ?? ucfirst((string) $d->status) }}
                    </span>
                    <p class="text-xs text-gray-600 mt-0.5">{{ \Carbon\Carbon::parse($d->effective_date)->format('d M Y, h:i A') }}</p>
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
            <a href="{{ route('admin.audit-log') }}" class="text-xs text-brand-700 hover:text-brand-800 font-medium">View all →</a>
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
