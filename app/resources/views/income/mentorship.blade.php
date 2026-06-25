@extends('layouts.app')
@section('title', 'My Income — Mentorship Bonus')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My Income</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        You earn a Mentorship Bonus on the Genos Sales Bonus (GSB) earned by each distributor you directly sponsored. The rate starts at 10% of their GSB and steps down by 1% for every ₹30,000 of cumulative GSB they earn, stabilising at 1% for life. This bonus applies only to directly sponsored distributors' GSB — not to any other income type.
    </div>

    {{-- Summary cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1">MB Earned This Month</p>
            <p class="text-2xl font-bold text-gray-900">₹—</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1">MB Earned Lifetime</p>
            <p class="text-2xl font-bold text-gray-900">₹—</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1">Active Sponsees Contributing</p>
            <p class="text-2xl font-bold text-gray-900">—</p>
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
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Their GSB <x-help-tip text="Net GSB earned by this sponsee for the cut-off date." /></span>
                        </th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-center gap-1">MB % <x-help-tip text="Starts at 10%. Steps down 1% per ₹30,000 cumulative GSB they earn, stabilising at 1% for life." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">MB earned</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Their cumulative GSB <x-help-tip text="Total GSB earned by this sponsee since joining. Determines your current MB % rate for them." /></span>
                        </th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">Slab step</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $row)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono text-gray-700">
                            {{ $row->sponsee_adn }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono">₹{{ number_format($row->sponsee_gsb_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-700">{{ $row->mb_rate_pct }}%</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono font-semibold text-green-700">₹{{ number_format($row->mb_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-gray-600">₹{{ number_format($row->sponsee_cumulative_gsb_paise / 100, 0) }}</td>
                        <td class="px-4 py-3 text-center">
                            @php $step = 11 - (int) $row->mb_rate_pct; @endphp
                            <span class="text-xs text-gray-500">Step {{ $step }} / 10</span>
                            <div class="w-20 mx-auto mt-1 bg-gray-100 rounded-full h-1.5">
                                <div class="bg-purple-500 h-1.5 rounded-full" style="width: {{ ($step / 10) * 100 }}%"></div>
                            </div>
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
