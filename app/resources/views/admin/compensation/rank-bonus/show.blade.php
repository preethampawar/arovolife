@extends('admin.layouts.admin')
@section('title', 'Rank Bonus — '.$date->format('F Y'))
@section('heading', 'Rank Bonus — '.$date->format('F Y'))

@section('content')

@include('admin.compensation._formulas.rb-month', ['rank1' => $rank1, 'date' => $date, 'rankNames' => $rankNames, 'open' => true])

@if(!empty($lateQualifiers))
<div class="mb-5 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
    <p class="font-semibold mb-1">Qualified after the pool was frozen</p>
    <p class="text-xs mb-2">
        This month's pools and qualifier roster were frozen before these distributors reached their rank.
        They were not paid from this month's pool: a pool that has already been divided is never re-divided,
        or the month would pay out more than it collected. Nothing on this page pays them, and there is no
        admin action here that will. Whether they are owed anything for this month is a plan decision, not an
        operational one — record it and escalate it.
    </p>
    <ul class="text-xs space-y-0.5">
        @foreach($lateQualifiers as $rankNum => $distributorIds)
        <li>
            <span class="font-medium">{{ $rankNames[$rankNum] ?? 'Rank '.$rankNum }}:</span>
            @foreach($distributorIds as $distributorId)
                <span class="font-mono">{{ $lateAdns[$distributorId] ?? ('#'.$distributorId) }}</span>{{ $loop->last ? '' : ',' }}
            @endforeach
        </li>
        @endforeach
    </ul>
</div>
@endif

{{-- Per-rank summary cards --}}
@if($rankSummaries->isNotEmpty())
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-6">
    @foreach($rankSummaries as $rankNum => $summary)
    <div class="bg-white rounded-xl border border-gray-200 p-3 text-center">
        <p class="text-[10px] text-gray-600 mb-1 font-medium uppercase tracking-wide">{{ $rankNames[$rankNum] ?? 'Rank '.$rankNum }}</p>
        <p class="text-sm font-bold text-indigo-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($summary->pool_paise / 100, 0) }}</p>
        <p class="text-[10px] text-gray-600">pool · {{ $summary->qualifier_count }} qualifiers</p>
        <p class="text-xs font-semibold text-green-700 mt-1">₹{{ \App\Modules\Shared\Support\IndianNumber::format($summary->total_net_paise / 100, 0) }} credited to wallets</p>
    </div>
    @endforeach
</div>
@endif

{{-- Per-distributor table --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rows->isEmpty())
        <p class="px-6 py-10 text-sm text-gray-600 text-center">No Rank Bonus results for this month.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-600 w-12">S.No.</th>
                    <th class="px-4 py-2 text-left text-gray-600">ADN</th>
                    <th class="px-4 py-2 text-left text-gray-600">Rank</th>
                    <x-bonus-credit-head />
                    <th class="px-4 py-2 text-center text-gray-600">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($rows as $row)
                @php
                $sc = ['credited' => 'bg-green-100 text-green-700', 'reversed' => 'bg-red-100 text-red-700', 'pending' => 'bg-gray-100 text-gray-600'];
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 text-gray-500 tabular-nums">{{ $rows->firstItem() + $loop->index }}</td>
                    <td class="px-4 py-2">
                        <a href="{{ route('admin.compensation.distributors.show', $row->distributor_id) }}"
                           class="text-brand-700 hover:underline font-mono">{{ $row->distributor?->adn ?? '—' }}</a>
                    </td>
                    <td class="px-4 py-2">
                        <span class="inline-flex px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-medium">
                            {{ $rankNames[$row->rank_number] ?? 'Rank '.$row->rank_number }}
                        </span>
                    </td>
                    <x-bonus-credit-cells :gross="$row->gross_paise" :deduction="$row->repurchase_deduction_paise" :credited="$row->net_paise" />
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
