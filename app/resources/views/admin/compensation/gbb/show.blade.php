@extends('admin.layouts.admin')
@section('title', 'GBB — '.$date->format('F Y'))
@section('heading', 'Growth Booster Bonus — '.$date->format('F Y'))

@section('content')

{{-- Frozen month economics (gbb_monthly_pools) --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6">
    <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex flex-wrap items-center gap-x-6 gap-y-1 text-xs">
        <span class="font-semibold text-gray-800">
            Frozen month economics
            <x-help-tip text="Written once, before any credit, and never recomputed — a re-run prices against this snapshot so the month's economics never move under a distributor who was already paid." />
        </span>
        <span class="text-gray-500">Company BV
            <strong class="text-gray-700">{{ $pool ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($pool->company_bv_paise / 100, 2) : '—' }}</strong></span>
        <span class="text-gray-500">Pool rate
            <strong class="text-gray-700">{{ $pool ? rtrim(rtrim(number_format($pool->pool_rate_bp / 100, 2), '0'), '.').'%' : '—' }}</strong></span>
        <span class="text-gray-500">Pool
            <strong class="text-indigo-700">{{ $pool ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($pool->pool_paise / 100, 2) : '—' }}</strong></span>
        <span class="text-gray-500">Total AGP
            <x-help-tip text="The month's payable AGP — the denominator of the point value. Repurchase-suspended AGP is excluded because it can never be paid." />
            <strong class="text-gray-700">{{ $pool ? \App\Modules\Shared\Support\IndianNumber::format($pool->total_agp) : '—' }}</strong></span>
        <span class="text-gray-500">Point value
            <x-help-tip text="Pool ÷ total payable AGP, floored to the whole rupee." />
            <strong class="text-gray-700">{{ $pool ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($pool->point_value_paise / 100, 2) : '—' }}</strong></span>
        <span class="text-gray-500">Payout
            <strong class="text-green-700">{{ $pool ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($pool->payout_paise / 100, 2) : '—' }}</strong></span>
        <span class="text-gray-500">Leftover
            <x-help-tip text="The flooring remainder. Normally small and positive; it can go negative when a later release credits AGP that was not in the frozen denominator." />
            <strong class="{{ $pool && $pool->leftover_paise < 0 ? 'text-red-600' : 'text-gray-700' }}">{{ $pool ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($pool->leftover_paise / 100, 2) : '—' }}</strong></span>
    </div>
    @unless($pool)
    <p class="px-4 py-3 text-xs text-gray-400">
        No frozen pool row for this month — it was run before the monthly pool snapshot existed.
    </p>
    @endunless
</div>

{{-- Result totals --}}
@if($summary && $summary->distributor_count > 0)
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
        <p class="text-xs text-gray-500 mb-1">Distributors</p>
        <p class="text-lg font-bold text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::format($summary->distributor_count) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
        <p class="text-xs text-gray-500 mb-1">
            AGP Recorded <x-help-tip text="Sum of the AGP on every row for this month, including repurchase-held and repurchase-suspended rows." />
        </p>
        <p class="text-lg font-bold text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::format($summary->total_agp) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
        <p class="text-xs text-gray-500 mb-1">Gross</p>
        <p class="text-lg font-bold text-indigo-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($summary->total_gross_paise / 100, 0) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
        <p class="text-xs text-gray-500 mb-1">Net</p>
        <p class="text-lg font-bold text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($summary->total_net_paise / 100, 0) }}</p>
    </div>
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
                        TDS (5%) <x-help-tip text="Income Tax deduction at source. A 3% admin charge (Group B, capped) is also deducted at payout." />
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
                    <td class="px-4 py-2 text-right">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gbb_gross_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right text-gray-500">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->tds_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right font-semibold text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gbb_net_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-center">
                        @php
                        $sc = [
                            'credited' => 'bg-green-100 text-green-700',
                            'reversed' => 'bg-red-100 text-red-700',
                            'pending'  => 'bg-gray-100 text-gray-600',
                            'repurchase_held' => 'bg-orange-100 text-orange-700',
                            'repurchase_suspended' => 'bg-red-100 text-red-700',
                        ];
                        @endphp
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $sc[$row->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ ucfirst(str_replace('_', ' ', $row->status)) }}
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
