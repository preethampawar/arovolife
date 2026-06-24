@extends('layouts.app')
@section('title', 'My Income — GSB History')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My Income</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        Your Genos Sales Bonus (GSB) is calculated at 23:59 every day based on the BV your Genos groups generated. The gross amount is reduced by a 3% admin charge (max ₹30,000), 5% TDS (Tax Deducted at Source), and a repurchase deduction before reaching your wallet. Each row below is one daily cut-off result.
    </div>

    {{-- Filter + CSV --}}
    <form method="GET" class="flex flex-wrap gap-3 mb-6 items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" name="from" value="{{ request('from') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" name="to" value="{{ request('to') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <button type="submit" class="px-4 py-1.5 bg-brand-500 text-white text-sm rounded-lg hover:bg-brand-600 transition-colors">Filter</button>
        @if(request('from') || request('to'))
            <a href="{{ route('income.gsb-history') }}" class="px-4 py-1.5 text-sm text-gray-600 hover:text-gray-800">Clear</a>
        @endif
        <a href="{{ route('income.gsb-history.export', request()->query()) }}" class="ml-auto px-4 py-1.5 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200 transition-colors font-medium">&#11015; CSV</a>
    </form>

    @if($rows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-500 font-medium">No GSB history yet.</p>
            <p class="text-sm text-gray-400 mt-1">Your Genos Sales Bonus will appear here after the first 23:59 cut-off calculates a match in your Genos groups.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[800px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Date</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Left BV matched</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Right BV matched</th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-center gap-1">Slab <x-help-tip text="Slab 1: 15K BV = ₹1,000. Slab 2: 30K = ₹3,000. Slab 3: 90K = ₹6,000. Slab 4: 2.7L = ₹12,000. Slab 5: 8L = ₹24,000. Slab 6: 24L = ₹40,000. Slab 7: 72L = ₹60,000." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Gross GSB <x-help-tip text="The Genos Sales Bonus before any deductions." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Admin 3% <x-help-tip text="3% of gross GSB or ₹30,000 — whichever is lower." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">TDS 5% <x-help-tip text="Tax Deducted at Source at 5% of gross minus admin charge." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Net GSB <x-help-tip text="Amount credited to your wallet after the admin charge and TDS deductions." /></span>
                        </th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @php $currentMonth = null; $monthGross = 0; $monthNet = 0; @endphp
                    @foreach($rows as $row)
                    @php
                        $rowMonth = $row->cutoff_date->format('Y-m');
                        if ($currentMonth !== null && $rowMonth !== $currentMonth) {
                    @endphp
                    <tr class="bg-indigo-50 font-semibold text-xs text-indigo-700">
                        <td colspan="4" class="px-4 py-2">Month total</td>
                        <td class="px-4 py-2 text-right">₹{{ number_format($monthGross / 100, 0) }}</td>
                        <td colspan="2"></td>
                        <td class="px-4 py-2 text-right">₹{{ number_format($monthNet / 100, 0) }}</td>
                        <td></td>
                    </tr>
                    @php $monthGross = 0; $monthNet = 0; } $currentMonth = $rowMonth; $monthGross += $row->gross_gsb_paise; $monthNet += $row->net_gsb_paise; @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-700">{{ $row->cutoff_date->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ number_format($row->left_bv_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ number_format($row->right_bv_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700">Slab {{ $row->slab }}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">₹{{ number_format($row->gross_gsb_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-red-600">-₹{{ number_format($row->admin_charge_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-red-600">-₹{{ number_format($row->tds_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold text-green-700">₹{{ number_format($row->net_gsb_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($row->status === 'credited')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Credited</span>
                            @elseif($row->status === 'failed')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">Failed</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">{{ ucfirst($row->status) }}</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                    @if($rows->isNotEmpty())
                    <tr class="bg-indigo-50 font-semibold text-xs text-indigo-700">
                        <td colspan="4" class="px-4 py-2">Month total</td>
                        <td class="px-4 py-2 text-right">₹{{ number_format($monthGross / 100, 0) }}</td>
                        <td colspan="2"></td>
                        <td class="px-4 py-2 text-right">₹{{ number_format($monthNet / 100, 0) }}</td>
                        <td></td>
                    </tr>
                    @endif
                </tbody>
            </table>
        </div>
        @if(method_exists($rows, 'links'))
            <div class="mt-4">{{ $rows->links() }}</div>
        @endif
    @endif
</div>
@endsection
