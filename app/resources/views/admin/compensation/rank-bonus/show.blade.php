@extends('admin.layouts.admin')
@section('title', 'Rank Bonus — '.$date->format('F Y'))
@section('heading', 'Rank Bonus — '.$date->format('F Y'))

@section('content')

{{-- Per-rank summary cards --}}
@if($rankSummaries->isNotEmpty())
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-6">
    @foreach($rankSummaries as $rankNum => $summary)
    <div class="bg-white rounded-xl border border-gray-200 p-3 text-center">
        <p class="text-[10px] text-gray-500 mb-1 font-medium uppercase tracking-wide">{{ $rankNames[$rankNum] ?? 'Rank '.$rankNum }}</p>
        <p class="text-sm font-bold text-indigo-700">₹{{ number_format($summary->pool_paise / 100, 0) }}</p>
        <p class="text-[10px] text-gray-400">pool · {{ $summary->qualifier_count }} qualifiers</p>
        <p class="text-xs font-semibold text-green-700 mt-1">₹{{ number_format($summary->total_net_paise / 100, 0) }} net</p>
    </div>
    @endforeach
</div>
@endif

{{-- Per-distributor table --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rows->isEmpty())
        <p class="px-6 py-10 text-sm text-gray-400 text-center">No Rank Bonus results for this month.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-500">ADN</th>
                    <th class="px-4 py-2 text-left text-gray-500">Rank</th>
                    <th class="px-4 py-2 text-right text-gray-500">Gross</th>
                    <th class="px-4 py-2 text-right text-gray-500">
                        Admin <x-help-tip text="min(3% of gross, ₹30,000)" />
                    </th>
                    <th class="px-4 py-2 text-right text-gray-500">TDS (5%)</th>
                    <th class="px-4 py-2 text-right text-gray-500">Net</th>
                    <th class="px-4 py-2 text-center text-gray-500">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($rows as $row)
                @php
                $sc = ['credited' => 'bg-green-100 text-green-700', 'reversed' => 'bg-red-100 text-red-700', 'pending' => 'bg-gray-100 text-gray-600'];
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2">
                        <a href="{{ route('admin.compensation.distributors.show', $row->distributor_id) }}"
                           class="text-brand-600 hover:underline font-mono">{{ $row->distributor?->adn ?? '—' }}</a>
                    </td>
                    <td class="px-4 py-2">
                        <span class="inline-flex px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-medium">
                            {{ $rankNames[$row->rank_number] ?? 'Rank '.$row->rank_number }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-right font-mono">₹{{ number_format($row->gross_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right font-mono text-gray-500">₹{{ number_format($row->admin_charge_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right font-mono text-gray-500">₹{{ number_format($row->tds_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold text-green-700">₹{{ number_format($row->net_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $sc[$row->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ ucfirst($row->status) }}
                        </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>

@endsection
