@extends('admin.layouts.admin')
@section('title', 'Grievance compliance report')
@section('heading', 'Grievance compliance report')

@section('content')
<a href="{{ route('admin.grievances.index') }}" class="text-sm text-sunrise-400 underline">← Grievance queue</a>

<div class="mt-4 mb-6 flex flex-wrap items-end justify-between gap-4">
    <div class="max-w-2xl text-sm leading-relaxed text-slate-600">
        <p>
            The monthly summary required by the Direct Seller Agreement §11 and read by the Compliance
            Committee each quarter. Breach counts come from stamps written the moment a published clock
            lapsed — editing an SLA setting today does not rewrite last month's figures.
        </p>
        @if ($scoped)
            <p class="mt-2 text-slate-500">
                Ethics and Privacy complaints are excluded from these figures — they are visible only to
                the compliance side. The copy filed with the Compliance Committee is run by an officer
                who can see them.
            </p>
        @endif
    </div>
    <div class="flex items-end gap-2">
        <form method="GET" action="{{ route('admin.grievances.report') }}" class="flex items-end gap-2">
            <div>
                <label for="month" class="block text-xs font-medium text-slate-600 mb-1">Month</label>
                <input id="month" name="month" type="month" value="{{ $month->format('Y-m') }}"
                       class="rounded-lg border-slate-600 bg-slate-800 text-sm text-slate-100">
            </div>
            <button type="submit" class="rounded-lg border border-slate-600 px-4 py-2 text-sm text-slate-700 hover:bg-slate-800">
                View
            </button>
        </form>
        <a href="{{ route('admin.grievances.report.export', ['month' => $month->format('Y-m')]) }}"
           class="rounded-lg bg-sunrise-800 px-4 py-2 text-sm font-semibold text-white hover:bg-sunrise-900">
            Export 12 months (CSV)
        </a>
    </div>
</div>

@php
    $tiles = [
        ['label' => 'Received',              'value' => $summary['received'],                 'tone' => 'text-slate-100'],
        ['label' => 'Resolved',              'value' => $summary['resolved'],                 'tone' => 'text-emerald-300'],
        ['label' => 'Closed',                'value' => $summary['closed'],                   'tone' => 'text-slate-300'],
        ['label' => 'Still open',            'value' => $summary['still_open'],               'tone' => 'text-amber-300'],
        ['label' => 'Acknowledged in time',  'value' => $summary['acknowledged_in_time'].' / '.$summary['acknowledgement_owed'], 'tone' => 'text-emerald-300'],
        ['label' => 'No acknowledgement owed', 'value' => $summary['acknowledgement_not_owed'], 'tone' => 'text-slate-600'],
        ['label' => 'Ack. breaches',         'value' => $summary['acknowledgement_breaches'], 'tone' => 'text-rose-300'],
        ['label' => 'First-response breaches','value' => $summary['first_response_breaches'], 'tone' => 'text-rose-300'],
        ['label' => 'Resolution breaches',   'value' => $summary['resolution_breaches'],      'tone' => 'text-rose-300'],
        ['label' => '60-day extensions',     'value' => $summary['third_party_extensions'],   'tone' => 'text-slate-300'],
        ['label' => 'Anonymous',             'value' => $summary['anonymous'],                'tone' => 'text-slate-300'],
        ['label' => 'Median days to resolve','value' => $summary['median_resolution_days'] ?? '—', 'tone' => 'text-slate-100'],
    ];
@endphp

<div class="mb-8 grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
    @foreach ($tiles as $tile)
        <div class="rounded-xl border border-slate-700 bg-slate-900/40 px-4 py-3">
            <p class="text-xs uppercase tracking-wider text-slate-500">{{ $tile['label'] }}</p>
            <p class="mt-1 text-2xl font-bold {{ $tile['tone'] }}">{{ $tile['value'] }}</p>
        </div>
    @endforeach
</div>

<div class="grid gap-6 lg:grid-cols-2">
    <div class="rounded-xl border border-slate-700 bg-slate-900/40 p-5">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-slate-600">By category</h2>
        @if (empty($summary['by_category']))
            <p class="text-sm text-slate-500">No grievances received in {{ $month->format('F Y') }}.</p>
        @else
            <dl class="space-y-2 text-sm">
                @foreach ($summary['by_category'] as $label => $count)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-600">{{ $label }}</dt>
                        <dd class="font-semibold text-slate-200">{{ $count }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </div>

    <div class="rounded-xl border border-slate-700 bg-slate-900/40 p-5">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-slate-600">By intake channel</h2>
        @if (empty($summary['by_channel']))
            <p class="text-sm text-slate-500">No grievances received in {{ $month->format('F Y') }}.</p>
        @else
            <dl class="space-y-2 text-sm">
                @foreach ($summary['by_channel'] as $label => $count)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-600">{{ $label }}</dt>
                        <dd class="font-semibold text-slate-200">{{ $count }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </div>
</div>

<h2 class="mt-8 mb-3 text-sm font-semibold uppercase tracking-wider text-slate-600">Trailing 12 months</h2>
<div class="overflow-x-auto rounded-xl border border-slate-700">
    <table class="min-w-full divide-y divide-slate-700 text-sm">
        <thead class="bg-slate-800 text-left text-xs uppercase tracking-wider text-slate-600">
            <tr>
                <th class="px-4 py-3">Month</th>
                <th class="px-4 py-3">Received</th>
                <th class="px-4 py-3">Resolved</th>
                <th class="px-4 py-3">Still open</th>
                <th class="px-4 py-3">Ack. breaches</th>
                <th class="px-4 py-3">Response breaches</th>
                <th class="px-4 py-3">Resolution breaches</th>
                <th class="px-4 py-3">Median days</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-800">
            @foreach ($trailing as $row)
                <tr class="hover:bg-slate-800/60">
                    <td class="px-4 py-3 font-medium text-slate-200">{{ $row['month'] }}</td>
                    <td class="px-4 py-3 text-slate-300">{{ $row['received'] }}</td>
                    <td class="px-4 py-3 text-slate-300">{{ $row['resolved'] }}</td>
                    <td class="px-4 py-3 text-slate-300">{{ $row['still_open'] }}</td>
                    <td class="px-4 py-3 {{ $row['acknowledgement_breaches'] > 0 ? 'font-semibold text-rose-300' : 'text-slate-300' }}">
                        {{ $row['acknowledgement_breaches'] }}
                    </td>
                    <td class="px-4 py-3 {{ $row['first_response_breaches'] > 0 ? 'font-semibold text-rose-300' : 'text-slate-300' }}">
                        {{ $row['first_response_breaches'] }}
                    </td>
                    <td class="px-4 py-3 {{ $row['resolution_breaches'] > 0 ? 'font-semibold text-rose-300' : 'text-slate-300' }}">
                        {{ $row['resolution_breaches'] }}
                    </td>
                    <td class="px-4 py-3 text-slate-300">{{ $row['median_resolution_days'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
