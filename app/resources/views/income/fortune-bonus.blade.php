@extends('layouts.app')
@section('title', 'My Income — Fortune Bonus')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My Income</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        The Fortune Bonus is a monthly matrix reward. Eligible distributors are placed in a 3×9 matrix in order of GSB activity. Your bonus depends on your matrix level. No admin charge applies; 5% TDS is deducted. Credited on the 9th of the following month.
    </div>

    {{-- Summary cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1">Net Fortune Bonus earned (page)</p>
            <p class="text-2xl font-bold text-gray-900">
                {{ $rows->isEmpty() ? '—' : '₹'.number_format($totalNet / 100, 0) }}
            </p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1">Months participated</p>
            <p class="text-2xl font-bold text-gray-900">
                {{ $rows instanceof \Illuminate\Pagination\LengthAwarePaginator ? number_format($rows->total()) : count($rows) }}
            </p>
        </div>
    </div>

    {{-- Filter --}}
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
            <a href="{{ route('income.fortune-bonus') }}" class="px-4 py-1.5 text-sm text-gray-600 hover:text-gray-800">Clear</a>
        @endif
    </form>

    @if($rows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-500 font-medium">No Fortune Bonus yet.</p>
            <p class="text-sm text-gray-400 mt-1">Earn GSB slabs and meet the BV repurchase requirement to participate in the monthly matrix.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[600px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Month</th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">Position</th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">Matrix Level</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Gross</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">TDS (5%)</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Net</th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $row)
                    @php
                    $sc = ['credited' => 'bg-green-100 text-green-700', 'skipped' => 'bg-gray-100 text-gray-500', 'pending' => 'bg-amber-100 text-amber-700'];
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-800">
                            {{ \Illuminate\Support\Carbon::parse($row->month_start)->format('F Y') }}
                        </td>
                        <td class="px-4 py-3 text-center font-mono text-gray-600">
                            {{ number_format($row->position) }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700">
                                Level {{ $row->matrix_level }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">₹{{ number_format($row->gross_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-gray-500">₹{{ number_format($row->tds_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold {{ $row->net_paise > 0 ? 'text-green-700' : 'text-gray-400' }}">
                            {{ $row->net_paise > 0 ? '₹'.number_format($row->net_paise / 100, 2) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium {{ $sc[$row->status] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ ucfirst($row->status) }}
                            </span>
                        </td>
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
