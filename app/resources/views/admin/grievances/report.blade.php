@extends('admin.layouts.admin')
@section('title', 'Grievance compliance report')
@section('heading', 'Grievance compliance report')

@section('content')
<a href="{{ route('admin.grievances.index') }}" class="text-sm text-brand-700 underline hover:text-brand-800">← Grievance queue</a>

<div class="mt-4 mb-6 flex flex-wrap items-end justify-between gap-4">
    <div class="max-w-2xl text-sm leading-relaxed text-gray-600">
        <p>
            The monthly summary required by the Direct Seller Agreement §11 and read by the Compliance
            Committee each quarter. Breach counts come from stamps written the moment a published clock
            lapsed — editing an SLA setting today does not rewrite last month's figures.
        </p>
        @if ($scoped)
            <p class="mt-2 text-gray-500">
                Ethics and Privacy complaints are excluded from these figures — they are visible only to
                the compliance side. The copy filed with the Compliance Committee is run by an officer
                who can see them.
            </p>
        @endif
    </div>
    <div class="flex items-end gap-2">
        <form method="GET" action="{{ route('admin.grievances.report') }}" class="flex items-end gap-2">
            <div>
                <label for="month" class="block text-xs font-medium text-gray-600 mb-1">Month</label>
                <input id="month" name="month" type="month" value="{{ $month->format('Y-m') }}"
                       class="rounded-lg border-gray-300 bg-white focus:border-brand-500 focus:ring-brand-500 text-sm text-gray-900">
            </div>
            <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                View
            </button>
        </form>
        <a href="{{ route('admin.grievances.report.export', ['month' => $month->format('Y-m')]) }}"
           class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-800">
            Export 12 months (CSV)
        </a>
    </div>
</div>

@php
    $tiles = [
        ['label' => 'Received',              'value' => $summary['received'],                 'tone' => 'text-gray-900'],
        ['label' => 'Resolved',              'value' => $summary['resolved'],                 'tone' => 'text-green-700'],
        ['label' => 'Closed',                'value' => $summary['closed'],                   'tone' => 'text-gray-700'],
        ['label' => 'Still open',            'value' => $summary['still_open'],               'tone' => 'text-amber-700'],
        ['label' => 'Acknowledged in time',  'value' => $summary['acknowledged_in_time'].' / '.$summary['acknowledgement_owed'], 'tone' => 'text-green-700'],
        ['label' => 'No acknowledgement owed', 'value' => $summary['acknowledgement_not_owed'], 'tone' => 'text-gray-600'],
        ['label' => 'Ack. breaches',         'value' => $summary['acknowledgement_breaches'], 'tone' => 'text-red-700'],
        ['label' => 'First-response breaches','value' => $summary['first_response_breaches'], 'tone' => 'text-red-700'],
        ['label' => 'Resolution breaches',   'value' => $summary['resolution_breaches'],      'tone' => 'text-red-700'],
        ['label' => '60-day extensions',     'value' => $summary['third_party_extensions'],   'tone' => 'text-gray-700'],
        ['label' => 'Anonymous',             'value' => $summary['anonymous'],                'tone' => 'text-gray-700'],
        ['label' => 'Median days to resolve','value' => $summary['median_resolution_days'] ?? '—', 'tone' => 'text-gray-900'],
    ];
@endphp

<div class="mb-8 grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
    @foreach ($tiles as $tile)
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm px-4 py-3">
            <p class="text-xs uppercase tracking-wider text-gray-500">{{ $tile['label'] }}</p>
            <p class="mt-1 text-2xl font-bold {{ $tile['tone'] }}">{{ $tile['value'] }}</p>
        </div>
    @endforeach
</div>

<div class="grid gap-6 lg:grid-cols-2">
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-5">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-600">By category</h2>
        @if (empty($summary['by_category']))
            <p class="text-sm text-gray-500">No grievances received in {{ $month->format('F Y') }}.</p>
        @else
            <dl class="space-y-2 text-sm">
                @foreach ($summary['by_category'] as $label => $count)
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-600">{{ $label }}</dt>
                        <dd class="font-semibold text-gray-800">{{ $count }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </div>

    <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-5">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-gray-600">By intake channel</h2>
        @if (empty($summary['by_channel']))
            <p class="text-sm text-gray-500">No grievances received in {{ $month->format('F Y') }}.</p>
        @else
            <dl class="space-y-2 text-sm">
                @foreach ($summary['by_channel'] as $label => $count)
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-600">{{ $label }}</dt>
                        <dd class="font-semibold text-gray-800">{{ $count }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </div>
</div>

<h2 class="mt-8 mb-3 text-sm font-semibold uppercase tracking-wider text-gray-600">Trailing 12 months</h2>
<div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-600">
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
        <tbody class="divide-y divide-gray-100">
            @foreach ($trailing as $row)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium text-gray-800">{{ $row['month'] }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $row['received'] }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $row['resolved'] }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $row['still_open'] }}</td>
                    <td class="px-4 py-3 {{ $row['acknowledgement_breaches'] > 0 ? 'font-semibold text-red-700' : 'text-gray-700' }}">
                        {{ $row['acknowledgement_breaches'] }}
                    </td>
                    <td class="px-4 py-3 {{ $row['first_response_breaches'] > 0 ? 'font-semibold text-red-700' : 'text-gray-700' }}">
                        {{ $row['first_response_breaches'] }}
                    </td>
                    <td class="px-4 py-3 {{ $row['resolution_breaches'] > 0 ? 'font-semibold text-red-700' : 'text-gray-700' }}">
                        {{ $row['resolution_breaches'] }}
                    </td>
                    <td class="px-4 py-3 text-gray-700">{{ $row['median_resolution_days'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
