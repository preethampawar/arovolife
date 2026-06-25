@extends('admin.layouts.admin')
@section('title', 'ADC Bonus — '.$date->format('F Y'))
@section('heading', 'ADC Bonus — '.$date->format('F Y'))

@section('content')

<div class="flex items-center gap-3 mb-6">
    <a href="{{ route('admin.compensation.adc-bonus.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← All months</a>
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100">
        <span class="text-sm font-semibold text-gray-900">Center results for {{ $date->format('F Y') }}</span>
    </div>
    @if($results->isEmpty())
        <p class="px-6 py-10 text-sm text-gray-400 text-center">No ADC Bonus results for this month.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-500">Center</th>
                    <th class="px-4 py-2 text-left text-gray-500">Assigned distributor</th>
                    <th class="px-4 py-2 text-right text-gray-500">Members</th>
                    <th class="px-4 py-2 text-right text-gray-500">Total member BV</th>
                    <th class="px-4 py-2 text-right text-gray-500">Gross (3%)</th>
                    <th class="px-4 py-2 text-right text-gray-500">TDS (5%)</th>
                    <th class="px-4 py-2 text-right text-gray-500">Net</th>
                    <th class="px-4 py-2 text-center text-gray-500">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($results as $row)
                @php
                    $sc = ['credited' => 'bg-green-100 text-green-700', 'reversed' => 'bg-red-100 text-red-700', 'pending' => 'bg-amber-100 text-amber-700'];
                @endphp
                <tr>
                    <td class="px-4 py-2 font-medium">{{ $row->center->name ?? '—' }}</td>
                    <td class="px-4 py-2 font-mono">{{ $row->distributor->adn ?? '—' }}</td>
                    <td class="px-4 py-2 text-right">{{ number_format($row->member_count) }}</td>
                    <td class="px-4 py-2 text-right">@bv($row->total_member_bv_paise)</td>
                    <td class="px-4 py-2 text-right font-mono">₹{{ number_format($row->gross_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right font-mono text-gray-500">₹{{ number_format($row->tds_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold {{ $row->net_paise > 0 ? 'text-green-700' : 'text-gray-400' }}">
                        ₹{{ number_format($row->net_paise / 100, 2) }}
                    </td>
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
    <div class="px-4 py-3 border-t border-gray-100">{{ $results->links() }}</div>
    @endif
</div>

@endsection
