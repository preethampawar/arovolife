@extends('layouts.app')
@section('title', 'My Income — Fortune Bonus')

@section('content')
<div class="max-w-5xl">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">Fortune Bonus</h1>

    @include('income._tabs')

    {{-- Page note --}}
    @developer
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        The Fortune Bonus is a monthly matrix reward funded entirely by the month's pool (5% of company BV). Eligible distributors are placed in a 3×9 matrix in order of GSB activity, and each month you earn FB points from the enrolled distributors below you in that matrix. A ₹30 minimum per qualifier is set aside from the pool first; if a month's pool cannot cover it, the pool is divided equally instead and that share may be ₹0. On top of the minimum, your FB points are multiplied by your matrix level's point value for the month — a value that varies with company BV and everyone's points, and may be ₹0 — up to your level's maximum (₹30,000 at levels 0–3, ₹20,000 at level 4, ₹10,000 at level 5, ₹5,000 at level 6, including the ₹30; levels 7–8 share the remaining pool by points; level 9 receives the minimum only). A 3% admin charge (Group B, capped) and 5% TDS are deducted at payout. Credited on the 9th of the following month. Every figure below is a record of a completed month — nothing here is a projection of future income.
    </div>
    @enddeveloper

    {{-- Summary cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-600 mb-1">Net Fortune Bonus earned (page)</p>
            <p class="text-2xl font-bold text-gray-900">
                {{ $rows->isEmpty() ? '—' : '₹'.\App\Modules\Shared\Support\IndianNumber::format($totalNet / 100, 0) }}
            </p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-600 mb-1">Months participated</p>
            <p class="text-2xl font-bold text-gray-900">
                {{ $rows instanceof \Illuminate\Pagination\LengthAwarePaginator ? \App\Modules\Shared\Support\IndianNumber::format($rows->total()) : count($rows) }}
            </p>
        </div>
    </div>

    {{-- Filter --}}
    <form method="GET" class="flex flex-wrap gap-3 mb-6 items-end">
        <div>
            <label class="block text-xs text-gray-600 mb-1">From (YYYY-MM)</label>
            <input type="month" name="from" value="{{ request('from') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-600 mb-1">To (YYYY-MM)</label>
            <input type="month" name="to" value="{{ request('to') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <button type="submit" class="px-4 py-1.5 bg-brand-700 text-white text-sm rounded-lg hover:bg-brand-800 transition-colors">Filter</button>
        @if(request('from') || request('to'))
            <a href="{{ route('income.fortune-bonus') }}" class="px-4 py-1.5 text-sm text-gray-600 hover:text-gray-800">Clear</a>
        @endif
    </form>

    @if($rows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-600 font-medium">No Fortune Bonus yet.</p>
            <p class="text-sm text-gray-600 mt-1">Earn GSB slabs and meet the BV repurchase requirement to participate in the monthly matrix.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[760px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Month</th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">Position</th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">Matrix Level</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            FB points × value
                            <x-help-tip text="The whole-rupee point value applied at your matrix level for the month, funded by the fortune pool — 5% of company BV. It varies month to month and may be ₹0. Your gross includes the ₹30 minimum (paid from the pool, pro-rated if the pool cannot cover it) and is limited by your level's maximum." />
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Gross</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">TDS (5%)</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Net</th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $row)
                    @php
                    $sc = ['credited' => 'bg-green-100 text-green-700', 'skipped' => 'bg-gray-100 text-gray-600', 'pending' => 'bg-amber-100 text-amber-700'];
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-800">
                            {{ \Illuminate\Support\Carbon::parse($row->month_start)->format('F Y') }}
                        </td>
                        <td class="px-4 py-3 text-center font-mono text-gray-600">
                            {{ \App\Modules\Shared\Support\IndianNumber::format($row->position) }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700">
                                Level {{ $row->matrix_level }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-gray-700">
                            @if($row->points !== null && $row->point_value_paise !== null)
                                {{ \App\Modules\Shared\Support\IndianNumber::format($row->points) }} × ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->point_value_paise / 100, 2) }}
                            @else
                                <span class="text-gray-600">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-mono">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gross_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-gray-600">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->tds_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold {{ $row->net_paise > 0 ? 'text-green-700' : 'text-gray-600' }}">
                            {{ $row->net_paise > 0 ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($row->net_paise / 100, 2) : '—' }}
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
