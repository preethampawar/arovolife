@extends('layouts.app')
@section('title', 'My Income — Mentorship Bonus')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My Income</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        You earn Mentorship Bonus points when a distributor you directly sponsored achieves a Genos Sales Bonus (GSB) slab. Each slab carries a fixed number of MSB points, and your bonus is those points × the slab's point value. This bonus applies only to directly sponsored distributors' GSB slab achievements — not to any other income type.
    </div>

    {{-- Summary cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1">MB Earned This Month</p>
            <p class="text-2xl font-bold text-gray-900">₹{{ \Illuminate\Support\Number::format(($mbThisMonthPaise ?? 0) / 100, 2) }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1">MB Earned Lifetime</p>
            <p class="text-2xl font-bold text-gray-900">₹{{ \Illuminate\Support\Number::format(($mbLifetimePaise ?? 0) / 100, 2) }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1">Active Sponsees Contributing</p>
            <p class="text-2xl font-bold text-gray-900">{{ $activeSponsees ?? 0 }}</p>
        </div>
    </div>

    {{-- Filter form --}}
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
            <a href="{{ route('income.mentorship') }}" class="px-4 py-1.5 text-sm text-gray-600 hover:text-gray-800">Clear</a>
        @endif
    </form>

    @if($rows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-500 font-medium">No Mentorship Bonus yet.</p>
            <p class="text-sm text-gray-400 mt-1">Your bonus will appear here once one of the distributors you directly sponsored earns their first Genos Sales Bonus.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[700px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center gap-1">Sponsee ADN <x-help-tip text="Your directly sponsored distributor's ADN, partially masked for privacy." /></span>
                        </th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-center gap-1">Their slab <x-help-tip text="The GSB slab this sponsee achieved on the cut-off date." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">MSB points <x-help-tip text="Points you earned for this slab achievement. Your bonus is points × the point value." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Point value <x-help-tip text="Rupee value of one MSB point at the time it was credited." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">MB earned</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $row)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono text-gray-700">
                            {{ $row->sponsee_adn }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($row->slab !== null)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-700">Slab {{ $row->slab }}</span>
                            @else
                            <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-mono font-semibold">
                            @if($row->msb_points !== null){{ $row->msb_points }}@else<span class="text-gray-400 font-normal">—</span>@endif
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-gray-600">
                            @if($row->msb_point_value_paise !== null)₹{{ \Illuminate\Support\Number::format($row->msb_point_value_paise / 100, 0) }}@else<span class="text-gray-400">—</span>@endif
                        </td>
                        <td class="px-4 py-3 text-right font-mono font-semibold text-green-700">₹{{ \Illuminate\Support\Number::format($row->mb_gross_paise / 100, 0) }}</td>
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
