@extends('admin.layouts.admin')
@section('title', 'Distributors')
@section('heading', 'Distributors')

@section('content')

{{-- Status summary pills --}}
@php
    $statusStyles = [
        'active' => [
            'active'   => 'bg-green-600 text-white border-green-600',
            'inactive' => 'bg-green-50 text-green-700 border-green-200 hover:border-green-400',
        ],
        'pending' => [
            'active'   => 'bg-amber-500 text-white border-amber-500',
            'inactive' => 'bg-amber-50 text-amber-700 border-amber-200 hover:border-amber-400',
        ],
        'frozen' => [
            'active'   => 'bg-red-600 text-white border-red-600',
            'inactive' => 'bg-red-50 text-red-700 border-red-200 hover:border-red-400',
        ],
        'terminated' => [
            'active'   => 'bg-gray-700 text-white border-gray-700',
            'inactive' => 'bg-gray-100 text-gray-600 border-gray-200 hover:border-gray-400',
        ],
    ];
@endphp
<div class="flex items-center gap-3 mb-6 flex-wrap">
    @foreach($statusStyles as $s => $style)
        @php $isActive = request()->query('status') === $s; @endphp
        <a href="{{ route('admin.distributors.index', array_merge(request()->query(), ['status' => $s])) }}"
           class="px-3 py-1 rounded-full text-xs font-medium border transition-colors
                  {{ $isActive ? $style['active'] : $style['inactive'] }}">
            {{ \App\Modules\Identity\Models\User::STATUS_LABELS[$s] ?? ucfirst($s) }}
            @if(isset($statusCounts[$s])) ({{ $statusCounts[$s] }}) @endif
        </a>
    @endforeach
    @if(request()->query('status'))
    <a href="{{ route('admin.distributors.index') }}" class="text-xs text-gray-700 hover:text-gray-900">✕ Clear</a>
    @endif
    <div class="ml-auto flex items-center gap-2">
        <a href="{{ route('admin.distributors.create') }}"
           class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium transition-colors shadow-sm">
            + Add Distributor
        </a>
        <a href="{{ route('admin.distributors.export') }}"
           class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white border border-gray-200 text-sm text-gray-700 hover:bg-gray-50 hover:text-gray-900 transition-colors">
            ↓ Export CSV (DSR Register)
        </a>
    </div>
</div>

{{-- Search --}}
<form method="GET" action="{{ route('admin.distributors.index') }}" class="mb-6 flex gap-3">
    @if(request()->query('status'))
        <input type="hidden" name="status" value="{{ request()->query('status') }}">
    @endif
    <input name="q" type="text" value="{{ request()->query('q') }}"
        placeholder="Search ADN, email, name…"
        class="flex-1 max-w-sm rounded-lg bg-white border border-gray-200 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
    <button type="submit" class="px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium transition-colors">
        Search
    </button>
    @if(request()->query('q'))
    <a href="{{ route('admin.distributors.index', array_diff_key(request()->query(), ['q'=>''])) }}"
       class="px-4 py-2 rounded-lg bg-white border border-gray-200 text-sm text-gray-800 hover:text-white transition-colors">
        Clear
    </a>
    @endif
</form>

{{-- Table --}}
<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50/50">
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">#</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">ADN</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">Name / Contact</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">State</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">Depth</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">Effective Date</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">Cooling Off</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-700 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse($distributors as $d)
                <tr class="hover:bg-white/40 transition-colors">
                    <td class="px-4 py-3 text-gray-600 tabular-nums">{{ ($distributors->firstItem() ?? 1) + $loop->index }}</td>
                    <td class="px-4 py-3 font-mono font-medium">
                        <a href="{{ route('admin.distributors.show', $d->id) }}"
                           class="text-brand-600 hover:text-brand-700 hover:underline transition-colors">{{ $d->adn }}</a>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-gray-800">{{ $d->full_name ?: '—' }}</p>
                        <p class="text-xs text-gray-700">{{ $d->email }}</p>
                        @if(!empty($d->phone_e164))
                            <p class="text-xs text-gray-600 font-mono tracking-tight">{{ $d->phone_e164 }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-gray-800">{{ $d->state }}</td>
                    <td class="px-4 py-3 text-gray-800">{{ $d->depth }}</td>
                    <td class="px-4 py-3 text-gray-700 text-xs">
                        {{ \Carbon\Carbon::parse($d->effective_date)->format('d M Y, h:i A') }}
                    </td>
                    <td class="px-4 py-3 text-xs">
                        @php $daysLeft = now()->diffInDays($d->cooling_off_end_at, false); @endphp
                        @if($daysLeft > 0)
                        <span class="{{ $daysLeft <= 7 ? 'text-red-700' : 'text-amber-700' }}">
                            {{ (int)$daysLeft }}d left
                        </span>
                        @else
                        <span class="text-gray-600">Expired</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-0.5 rounded-full text-xs border
                            {{ $d->status === 'active'     ? 'bg-green-50 text-green-700 border-green-200'
                             : ($d->status === 'frozen'    ? 'bg-red-50 text-red-700 border-red-200'
                             : ($d->status === 'terminated'? 'bg-white text-gray-500 border-gray-200'
                             : 'bg-amber-50 text-amber-700 border-amber-200')) }}">
                            {{ \App\Modules\Identity\Models\User::STATUS_LABELS[$d->status] ?? ucfirst((string) $d->status) }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.distributors.show', $d->id) }}"
                           class="text-xs text-brand-600 hover:text-brand-500">View →</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" class="px-4 py-8 text-center text-sm text-gray-700">
                        No distributors found.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($distributors->hasPages())
    <div class="px-4 py-4 border-t border-gray-200">
        {{ $distributors->links() }}
    </div>
    @endif
</div>

@endsection
