@extends('admin.layouts.admin')
@section('title', 'Franchises')
@section('heading', 'Franchises')

@section('content')
@php
    $filters = [
        '' => 'All',
        \App\Modules\Commerce\Models\Franchise::STATUS_PENDING => 'Applications ('.$pendingCount.')',
        \App\Modules\Commerce\Models\Franchise::STATUS_ACTIVE => 'Active',
        \App\Modules\Commerce\Models\Franchise::STATUS_SUSPENDED => 'Suspended',
        \App\Modules\Commerce\Models\Franchise::STATUS_CLOSED => 'Closed',
    ];
    $badge = fn (string $state) => match ($state) {
        'active' => 'bg-emerald-100 text-emerald-800',
        'pending_approval' => 'bg-amber-100 text-amber-800',
        'suspended' => 'bg-rose-100 text-rose-800',
        default => 'bg-slate-200 text-slate-700',
    };
@endphp

<div class="mb-6 flex flex-wrap items-start justify-between gap-3">
    <p class="max-w-2xl text-sm leading-relaxed text-slate-600">
        Company-owned pickup points operated by distributors. Stock is company consignment and sales stay
        online and ADN-attributed — a franchise dispatches orders, it does not sell. The franchise code is
        separate from the ADN and holds no position in the Genos. Operators earn
        <strong>{{ number_format($planRateBp / 100, 2) }}%</strong> of the product value they fulfil each month.
    </p>
    <div class="flex gap-2">
        <a href="{{ route('admin.commerce.franchises.report') }}"
           class="rounded-lg border border-slate-600 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-800">
            Commission report
        </a>
        <a href="{{ route('admin.commerce.franchises.create') }}"
           class="rounded-lg bg-sunrise-800 px-4 py-2 text-sm font-semibold text-white hover:bg-sunrise-900">
            Add a franchise
        </a>
    </div>
</div>

@if (session('status'))
    <div class="mb-6 rounded-lg border border-emerald-600/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
        {{ session('status') }}
    </div>
@endif

<div class="mb-5 flex flex-wrap gap-2">
    @foreach ($filters as $value => $label)
        <a href="{{ route('admin.commerce.franchises.index', array_filter(['status' => $value, 'q' => $search])) }}"
           class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition
                  {{ $status === $value ? 'bg-sunrise-800 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700' }}">
            {{ $label }}
        </a>
    @endforeach
</div>

<form method="GET" action="{{ route('admin.commerce.franchises.index') }}" class="mb-5 flex flex-wrap gap-3">
    <input type="hidden" name="status" value="{{ $status }}">
    <input type="text" name="q" value="{{ $search }}" placeholder="Code, name, PIN code or district"
           class="min-w-64 flex-1 rounded-lg border-slate-600 bg-slate-800 text-sm text-slate-100 placeholder-slate-500">
    <button type="submit" class="rounded-lg border border-slate-600 px-4 py-2 text-sm text-slate-700 hover:bg-slate-800">
        Search
    </button>
</form>

@if ($franchises->isEmpty())
    <div class="rounded-xl border border-dashed border-slate-700 px-6 py-12 text-center text-slate-600">
        No franchises match this filter.
    </div>
@else
    <div class="overflow-x-auto rounded-xl border border-slate-700">
        <table class="min-w-full divide-y divide-slate-700 text-sm">
            <thead class="bg-slate-800 text-left text-xs uppercase tracking-wider text-slate-600">
                <tr>
                    <th class="px-4 py-3">Code</th>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Location</th>
                    <th class="px-4 py-3">Operator</th>
                    <th class="px-4 py-3">Rate</th>
                    <th class="px-4 py-3">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800">
                @foreach ($franchises as $franchise)
                    <tr class="hover:bg-slate-800/60">
                        <td class="px-4 py-3 font-mono text-xs">
                            <a href="{{ route('admin.commerce.franchises.edit', $franchise->id) }}"
                               class="font-semibold text-sunrise-400 underline">{{ $franchise->code }}</a>
                        </td>
                        <td class="px-4 py-3 text-slate-200">
                            {{ $franchise->name }}
                            @if ($franchise->is_company_primary)
                                <span class="ml-1.5 rounded bg-slate-700 px-1.5 py-0.5 text-[10px] uppercase text-slate-300">company</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $franchise->displayLocation() ?: '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">
                            @if ($franchise->operator)
                                <span class="font-mono text-xs">{{ $franchise->operator->adn }}</span>
                                <span class="block text-xs">{{ $franchise->operator->user?->full_name }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            {{ number_format(($franchise->commission_rate_bp ?? $planRateBp) / 100, 2) }}%
                            @unless ($franchise->commission_rate_bp === null)
                                <span class="block text-xs text-amber-300">override</span>
                            @endunless
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $badge($franchise->status) }}">
                                {{ ucfirst(str_replace('_', ' ', $franchise->status)) }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $franchises->links() }}</div>
@endif
@endsection
