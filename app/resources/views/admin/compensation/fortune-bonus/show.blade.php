@extends('admin.layouts.admin')
@section('title', 'Fortune Bonus — '.$date->format('F Y'))
@section('heading', 'Fortune Bonus — '.$date->format('F Y'))

@section('content')

{{-- Level summary cards --}}
@if($levelSummaries->isNotEmpty())
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-6">
    @foreach($levelSummaries as $level => $summary)
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm text-center">
        <p class="text-xs font-medium text-gray-500 mb-1">Level {{ $level }}</p>
        <p class="text-sm font-bold text-gray-900">{{ number_format($summary->participant_count) }} participants</p>
        <p class="text-xs text-green-700 font-medium mt-0.5">₹{{ number_format($levelBonusPaise[$level] / 100, 2) }} each</p>
        <p class="text-xs text-gray-500 mt-0.5">Net total: ₹{{ number_format($summary->total_net_paise / 100, 2) }}</p>
    </div>
    @endforeach
</div>
@endif

{{-- Participants table --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100">
        <span class="text-sm font-semibold text-gray-900">Matrix participants</span>
    </div>
    @if($rows->isEmpty())
        <p class="px-6 py-10 text-sm text-gray-400 text-center">No participants enrolled for this month.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-right text-gray-500">#</th>
                    <th class="px-4 py-2 text-left text-gray-500">ADN</th>
                    <th class="px-4 py-2 text-center text-gray-500">Level</th>
                    <th class="px-4 py-2 text-center text-gray-500">Tier</th>
                    <th class="px-4 py-2 text-left text-gray-500">First GSB date</th>
                    <th class="px-4 py-2 text-right text-gray-500">Gross</th>
                    <th class="px-4 py-2 text-right text-gray-500">TDS</th>
                    <th class="px-4 py-2 text-right text-gray-500">Net</th>
                    <th class="px-4 py-2 text-center text-gray-500">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($rows as $participant)
                @php
                    $result = $resultsByDistributor[$participant->distributor_id] ?? null;
                    $sc = ['credited' => 'bg-green-100 text-green-700', 'skipped' => 'bg-gray-100 text-gray-500', 'pending' => 'bg-amber-100 text-amber-700'];
                @endphp
                <tr>
                    <td class="px-4 py-2 text-right font-mono text-gray-400">{{ number_format($participant->position) }}</td>
                    <td class="px-4 py-2 font-mono">{{ $participant->distributor->adn ?? '—' }}</td>
                    <td class="px-4 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded bg-indigo-100 text-indigo-700 text-[10px] font-medium">L{{ $participant->matrix_level }}</span>
                    </td>
                    <td class="px-4 py-2 text-center text-gray-600">{{ str_replace('_', ' ', $participant->eligibility_tier) }}</td>
                    <td class="px-4 py-2 text-gray-600">{{ $participant->first_gsb_date ?? '—' }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ $result ? '₹'.number_format($result->gross_paise / 100, 2) : '—' }}</td>
                    <td class="px-4 py-2 text-right font-mono text-gray-500">{{ $result ? '₹'.number_format($result->tds_paise / 100, 2) : '—' }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold {{ ($result?->net_paise ?? 0) > 0 ? 'text-green-700' : 'text-gray-400' }}">
                        {{ $result ? '₹'.number_format($result->net_paise / 100, 2) : '—' }}
                    </td>
                    <td class="px-4 py-2 text-center">
                        @if($result)
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $sc[$result->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ ucfirst($result->status) }}
                        </span>
                        @else
                        <span class="text-gray-400">—</span>
                        @endif
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
