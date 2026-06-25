@extends('admin.layouts.admin')
@section('title', 'GBB — '.$date->format('F Y'))
@section('heading', 'Growth Booster Bonus — '.$date->format('F Y'))

@section('content')

{{-- Summary cards --}}
@if($summary && $summary->distributor_count > 0)
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
        <p class="text-xs text-gray-500 mb-1">Company Turnover</p>
        <p class="text-lg font-bold text-gray-900">₹{{ number_format($summary->company_turnover_paise / 100, 0) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
        <p class="text-xs text-gray-500 mb-1">
            GBB Pool (5%) <x-help-tip text="5% of company monthly turnover allocated to the Growth Booster Bonus pool." />
        </p>
        <p class="text-lg font-bold text-indigo-700">₹{{ number_format($summary->pool_paise / 100, 0) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
        <p class="text-xs text-gray-500 mb-1">
            Total AGP <x-help-tip text="Sum of all AGP earned across eligible distributors. Point value = Pool ÷ Total AGP." />
        </p>
        <p class="text-lg font-bold text-gray-900">{{ number_format($summary->total_pool_agp) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
        <p class="text-xs text-gray-500 mb-1">Net Credited</p>
        <p class="text-lg font-bold text-green-700">₹{{ number_format($summary->total_net_paise / 100, 0) }}</p>
    </div>
</div>

@php
$pointValuePaise = $summary->total_pool_agp > 0 ? (int) ($summary->pool_paise / $summary->total_pool_agp) : 0;
@endphp
<div class="mb-6 rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-gray-600">
    Point value for this month: <strong>₹{{ number_format($pointValuePaise / 100, 4) }} per AGP</strong>
    (Pool ₹{{ number_format($summary->pool_paise / 100, 2) }} ÷ {{ number_format($summary->total_pool_agp) }} AGP)
    · {{ $summary->distributor_count }} distributors credited
</div>
@endif

{{-- Per-distributor table --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rows->isEmpty())
        <p class="px-6 py-10 text-sm text-gray-400 text-center">No GBB results for this month.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-500">ADN</th>
                    <th class="px-4 py-2 text-right text-gray-500">
                        AGP <x-help-tip text="Arovolife Growth Points earned: Slab 1 = 12 AGP, Slab 2 = 5 AGP, Slab 3 = 2 AGP. Capped at 120." />
                    </th>
                    <th class="px-4 py-2 text-right text-gray-500">Gross GBB</th>
                    <th class="px-4 py-2 text-right text-gray-500">
                        TDS (5%) <x-help-tip text="Income Tax deduction at source. No admin charge applies to GBB." />
                    </th>
                    <th class="px-4 py-2 text-right text-gray-500">Net GBB</th>
                    <th class="px-4 py-2 text-center text-gray-500">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($rows as $row)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2">
                        <a href="{{ route('admin.compensation.distributors.show', $row->distributor_id) }}"
                           class="text-brand-600 hover:underline font-mono">{{ $row->distributor?->adn ?? '—' }}</a>
                    </td>
                    <td class="px-4 py-2 text-right">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 font-medium">
                            {{ $row->agp_earned }} AGP
                        </span>
                    </td>
                    <td class="px-4 py-2 text-right">₹{{ number_format($row->gbb_gross_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right text-gray-500">₹{{ number_format($row->tds_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right font-semibold text-green-700">₹{{ number_format($row->gbb_net_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-center">
                        @php
                        $sc = [
                            'credited' => 'bg-green-100 text-green-700',
                            'reversed' => 'bg-red-100 text-red-700',
                            'pending'  => 'bg-gray-100 text-gray-600',
                        ];
                        @endphp
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
