@extends('admin.layouts.admin')
@section('title', 'Grievances')
@section('heading', 'Grievances')

@section('content')
@php
    $filters = [
        'unsettled' => 'Open ('.$counts['unsettled'].')',
        'unacknowledged' => 'Unacknowledged ('.$counts['unacknowledged'].')',
        'breached' => 'SLA breached ('.$counts['breached'].')',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
        'all' => 'All',
    ];
@endphp

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-gray-600">
        Every intake channel — web, contact form, email, phone, post and walk-in — lands here.
        Sorted by the resolution deadline, soonest first.
    </p>
    <div class="flex gap-2">
        <a href="{{ route('admin.grievances.report') }}"
           class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100">
            Compliance report
        </a>
        <a href="{{ route('admin.grievances.create') }}"
           class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-800">
            Record a complaint
        </a>
    </div>
</div>

<div class="mb-5 flex flex-wrap gap-2">
    @foreach ($filters as $value => $label)
        <a href="{{ route('admin.grievances.index', array_filter(['status' => $value, 'category' => $category, 'level' => $level, 'q' => $search])) }}"
           class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition
                  {{ $status === $value ? 'bg-slate-800 text-white' : 'bg-white border border-gray-200 text-gray-700 hover:bg-gray-100' }}">
            {{ $label }}
        </a>
    @endforeach
</div>

<form method="GET" action="{{ route('admin.grievances.index') }}" class="mb-5 flex flex-wrap gap-3">
    <input type="hidden" name="status" value="{{ $status }}">
    {{-- The three controls are labelled for assistive technology. A visible
         label would crowd a one-line filter bar, but a placeholder is not a
         label and a bare <select> is announced only as "combo box" — the
         operator hears nothing about what it filters (WCAG 4.1.2). --}}
    <input type="text" name="q" value="{{ $search }}" placeholder="Complaint number, subject, email or phone"
           aria-label="Search grievances by complaint number, subject, email or phone"
           class="min-w-64 flex-1 rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900 placeholder-gray-400">
    <select name="category" aria-label="Filter by category" class="rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900">
        <option value="">All categories</option>
        @foreach ($categories as $option)
            <option value="{{ $option->value }}" @selected($category === $option->value)>{{ $option->label() }}</option>
        @endforeach
    </select>
    <select name="level" aria-label="Filter by escalation step" class="rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900">
        <option value="">All escalation steps</option>
        @foreach ($levels as $option)
            <option value="{{ $option->value }}" @selected($level === (string) $option->value)>
                Step {{ $option->value }} — {{ $option->label() }}
            </option>
        @endforeach
    </select>
    <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
        Filter
    </button>
</form>

@if ($tickets->isEmpty())
    <div class="rounded-xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center text-gray-600">
        No grievances match this filter.
    </div>
@else
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-600">
                <tr>
                    <th class="px-4 py-3">Number</th>
                    <th class="px-4 py-3">Subject</th>
                    <th class="px-4 py-3">Category</th>
                    <th class="px-4 py-3">Step</th>
                    <th class="px-4 py-3">Received</th>
                    <th class="px-4 py-3">Resolve by</th>
                    <th class="px-4 py-3">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($tickets as $ticket)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.grievances.show', $ticket->id) }}"
                               class="font-mono text-xs font-semibold text-brand-700 underline hover:text-brand-800">{{ $ticket->ticket_no }}</a>
                            @if ($ticket->hasEverBreached())
                                <span class="ml-1.5 rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-700">SLA</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-800">{{ \Illuminate\Support\Str::limit($ticket->subject, 44) }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $ticket->category->label() }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $ticket->escalation_level->value }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $ticket->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3 {{ $ticket->isSlaBreached() ? 'font-semibold text-red-700' : 'text-gray-600' }}">
                            {{ $ticket->sla_resolution_at?->format('d M Y') ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $ticket->status->badgeClasses() }}">
                                {{ $ticket->status->label() }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $tickets->links() }}</div>
@endif
@endsection
