@extends('admin.layouts.admin')
@section('title', 'Dashboard')
@section('heading', 'Dashboard')

@section('content')

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
@endphp

<div class="space-y-6">

    {{-- Stat tiles. Two-up on a phone rather than one-up: the tiles are short
         enough now that a single column just wasted the fold. --}}
    <div class="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:grid-cols-4">
        @foreach($cards as $card)
            <x-ui.stat
                :label="$card['label']"
                :value="\App\Modules\Shared\Support\IndianNumber::format($card['value'])"
                :hint="$card['hint']"
                :icon="$card['icon']"
                :tone="$card['tone']"
                :href="$card['href']" />
        @endforeach

        {{-- Inventory was a full-width rounded-2xl band holding a single
             sentence — the worst density on the page, and it burned a whole
             row to say three numbers nobody could scan. It is the 8th tile
             now: same three figures, same destination, same inventory.view
             gate. It also squares off the 2x4 grid. --}}
        @if($inventoryCard)
        <a href="{{ route('admin.inventory.reports.index') }}"
           class="group col-span-2 flex flex-col rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition-all hover:border-gray-300 hover:shadow-md md:col-span-1">
            <div class="flex items-center gap-2">
                <span class="shrink-0 text-amber-600">{{ svg('lucide-boxes', 'w-[18px] h-[18px]') }}</span>
                <p class="min-w-0 truncate text-[13px] font-medium text-gray-600">Inventory alerts</p>
                <span class="ml-auto shrink-0 text-gray-300 transition-all group-hover:translate-x-0.5 group-hover:text-brand-600" aria-hidden="true">{{ svg('lucide-arrow-up-right', 'w-4 h-4') }}</span>
            </div>
            <div class="mt-3 grid grid-cols-3 gap-2">
                <div>
                    <p class="text-[11px] text-gray-500">Low stock</p>
                    <p class="mt-0.5 text-xl font-semibold leading-none tabular-nums text-gray-900">{{ $inventoryCard['low_stock'] }}</p>
                </div>
                <div>
                    <p class="text-[11px] text-gray-500">Expiring &le;{{ $inventoryCard['expiry_days'] }}d</p>
                    <p class="mt-0.5 text-xl font-semibold leading-none tabular-nums text-gray-900">{{ $inventoryCard['expiring'] }}</p>
                </div>
                <div>
                    <p class="text-[11px] text-gray-500">Expired</p>
                    <p class="mt-0.5 text-xl font-semibold leading-none tabular-nums text-gray-900">{{ $inventoryCard['expired'] }}</p>
                </div>
            </div>
        </a>
        @endif
    </div>

    {{-- Action Center. The whole card is absent when nothing is critical —
         silence means nothing to do. That is existing behaviour, not styling,
         so it stays, and it is why this block has no empty state. --}}
    @if($actionCenterItems->isNotEmpty())
    <x-ui.card flush>
        {{-- The "View all" <a> MUST remain the h3's immediate next sibling:
             action-center.spec.js selects it with
             xpath=following-sibling::a[1]. Do not wrap either in a div. --}}
        <div class="flex items-center gap-2 border-b border-gray-200 px-5 py-3.5">
            <span class="shrink-0 text-red-600">{{ svg('lucide-siren', 'w-4 h-4') }}</span>
            <h3 class="text-sm font-semibold text-gray-900">Action Center — oldest critical items</h3>
            <a href="{{ route('admin.action-center.index') }}"
               class="ml-auto inline-flex shrink-0 items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-brand-700 transition-colors hover:bg-brand-50 hover:text-brand-800">
                View all {{ svg('lucide-chevron-right', 'w-3 h-3') }}
            </a>
        </div>

        <ul class="divide-y divide-gray-100">
            @foreach($actionCenterItems as $item)
            <li class="flex items-center justify-between gap-3 px-5 py-3 transition-colors hover:bg-gray-50">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-gray-900">{{ $item->title }}</p>
                    <p class="truncate text-xs text-gray-500">{{ $item->subtitle }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    {{-- Age is the sort key of this list, so it is a column now
                         rather than a suffix buried in the subtitle string. --}}
                    <span class="text-xs tabular-nums text-gray-500">{{ $item->ageHours() }}h</span>
                    @if($item->url)
                    <x-ui.button :href="$item->url" variant="secondary" size="sm" icon-trailing="chevron-right">Fix</x-ui.button>
                    @endif
                </div>
            </li>
            @endforeach
        </ul>
    </x-ui.card>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">

        <x-ui.card flush title="Recent Registrations">
            <x-slot:actions>
                <x-ui.button :href="route('admin.distributors.index')" variant="ghost" size="sm" icon-trailing="chevron-right">View all</x-ui.button>
            </x-slot:actions>

            <div class="divide-y divide-gray-100">
                @forelse($recentDistributors as $d)
                <div class="flex items-center justify-between gap-3 px-5 py-2.5 transition-colors hover:bg-gray-50">
                    <div class="min-w-0">
                        <a href="{{ route('admin.distributors.show', $d->id) }}"
                           class="font-mono text-sm font-medium text-brand-700 transition-colors hover:text-brand-800">{{ $d->adn }}</a>
                        <p class="truncate text-xs text-gray-500">{{ $d->full_name ?? $d->email }}</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <x-ui.badge :tone="match($d->status) { 'active' => 'success', 'frozen' => 'danger', default => 'warning' }" dot>
                            {{ \App\Modules\Identity\Models\User::STATUS_LABELS[$d->status] ?? ucfirst((string) $d->status) }}
                        </x-ui.badge>
                        <p class="mt-1 text-[11px] tabular-nums text-gray-500">{{ \Carbon\Carbon::parse($d->effective_date)->format('d M Y, h:i A') }}</p>
                    </div>
                </div>
                @empty
                <x-ui.empty-state icon="user-plus" title="No distributors yet."
                                  description="New registrations appear here as they complete signup." />
                @endforelse
            </div>
        </x-ui.card>

        <x-ui.card flush title="Recent Audit Events">
            <x-slot:actions>
                <x-ui.button :href="route('admin.audit-log')" variant="ghost" size="sm" icon-trailing="chevron-right">View all</x-ui.button>
            </x-slot:actions>

            <div class="divide-y divide-gray-100">
                @forelse($recentAudit as $log)
                <div class="px-5 py-2.5 transition-colors hover:bg-gray-50">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-sm leading-snug text-gray-800">{{ $log->display_title }}</p>
                        <span class="shrink-0 whitespace-nowrap pt-0.5 text-[11px] tabular-nums text-gray-500">
                            {{ \Carbon\Carbon::parse($log->created_at)->diffForHumans() }}
                        </span>
                    </div>
                    @if($log->display_subtitle)
                        <p class="mt-0.5 text-xs text-gray-500">{{ $log->display_subtitle }}</p>
                    @endif
                </div>
                @empty
                <x-ui.empty-state icon="file-text" title="No audit events yet."
                                  description="Every privileged action is recorded here." />
                @endforelse
            </div>
        </x-ui.card>

    </div>
</div>

@endsection
