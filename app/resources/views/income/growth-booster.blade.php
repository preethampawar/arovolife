@extends('layouts.app')
@section('title', 'My Income — Growth Booster Bonus')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My Income</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        The Growth Booster Bonus is 5% of the company's monthly turnover, distributed proportionally via Arovolife Growth Points (AGP). You earn AGP each time you achieve a GSB slab — Slab 1 earns 12 AGP, Slab 2 earns 5 AGP, Slab 3 earns 2 AGP. Your AGP is capped at 120 per month. Credited on the 2nd of the following month.
    </div>

    {{-- Summary cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1">GBB Earned (page)</p>
            <p class="text-2xl font-bold text-gray-900">₹{{ $rows->isEmpty() ? '—' : number_format($totalNet / 100, 0) }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1">Total AGP (page)</p>
            <p class="text-2xl font-bold text-gray-900">{{ $rows->isEmpty() ? '—' : number_format($totalAgp) }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1">Months credited</p>
            <p class="text-2xl font-bold text-gray-900">{{ $rows instanceof \Illuminate\Pagination\LengthAwarePaginator ? number_format($rows->total()) : count($rows) }}</p>
        </div>
    </div>

    {{-- Filter form --}}
    <form method="GET" class="flex flex-wrap gap-3 mb-6 items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">From (YYYY-MM)</label>
            <input type="month" name="from" value="{{ request('from') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To (YYYY-MM)</label>
            <input type="month" name="to" value="{{ request('to') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <button type="submit" class="px-4 py-1.5 bg-brand-500 text-white text-sm rounded-lg hover:bg-brand-600 transition-colors">Filter</button>
        @if(request('from') || request('to'))
            <a href="{{ route('income.growth-booster') }}" class="px-4 py-1.5 text-sm text-gray-600 hover:text-gray-800">Clear</a>
        @endif
    </form>

    @if($rows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-500 font-medium">No Growth Booster Bonus yet.</p>
            <p class="text-sm text-gray-400 mt-1">Your GBB will appear here once you earn AGP from Slab 1, 2, or 3 GSB in a calendar month.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[600px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Month</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">
                                AGP earned
                                <x-help-tip text="Your Arovolife Growth Points for this month (Slab 1 = 12, Slab 2 = 5, Slab 3 = 2). Capped at 120." />
                            </span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">
                                Point value
                                <x-help-tip text="GBB pool ÷ total AGP of all eligible distributors that month." />
                            </span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Gross GBB</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">TDS (5%)</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Net GBB</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $row)
                    @php
                        $pointValuePaise = $row->total_pool_agp > 0 ? (int) ($row->pool_paise / $row->total_pool_agp) : 0;
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-800">
                            {{ \Illuminate\Support\Carbon::parse($row->year_month)->format('F Y') }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700">
                                {{ $row->agp_earned }} AGP
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-gray-600">
                            ₹{{ number_format($pointValuePaise / 100, 4) }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono">₹{{ number_format($row->gbb_gross_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-gray-500">₹{{ number_format($row->tds_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold text-green-700">₹{{ number_format($row->gbb_net_paise / 100, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if(method_exists($rows, 'links'))
            <div class="mt-4">{{ $rows->links() }}</div>
        @endif
    @endif
</div>
@endsection
