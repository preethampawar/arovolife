@extends('layouts.app')
@section('title', 'My Income — Growth Booster Bonus')

@section('content')
@php
    $pageRows = $rows instanceof \Illuminate\Pagination\LengthAwarePaginator ? collect($rows->items()) : collect($rows);
    $creditedRows = $pageRows->where('status', 'credited');
    $creditedNetPaise = (int) $creditedRows->sum('gbb_net_paise');
    $creditedAgp = (int) $creditedRows->sum('agp_earned');
    // Frozen point value for the month; months recorded before the snapshot
    // existed fall back to the pool figures stored on the row itself.
    $pointValuePaiseFor = function ($row): ?int {
        if ($row->point_value_paise !== null) {
            return (int) $row->point_value_paise;
        }

        return $row->total_pool_agp > 0 ? intdiv((int) $row->pool_paise, (int) $row->total_pool_agp) : null;
    };
    $statusLabels = [
        'credited' => 'Credited',
        'pending' => 'Pending',
        'reversed' => 'Reversed',
        'repurchase_held' => 'Held',
        'repurchase_suspended' => 'Not payable',
    ];
    $statusBadges = [
        'credited' => 'bg-green-100 text-green-700',
        'pending' => 'bg-amber-100 text-amber-700',
        'reversed' => 'bg-red-100 text-red-700',
        'repurchase_held' => 'bg-orange-100 text-orange-700',
        'repurchase_suspended' => 'bg-red-100 text-red-700',
    ];
    $statusNotes = [
        'repurchase_held' => 'Held until your repurchase is complete.',
        'repurchase_suspended' => 'Not payable for this month.',
    ];
@endphp
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">Growth Booster Bonus</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        The Growth Booster Bonus comes from a monthly pool set at 5% of the company's business volume for that month, shared out through arovolife Growth Points (AGP). It is for distributors who held no rank in the previous month — achieving your first rank this month keeps you eligible for this month. AGP is recorded each time you match a Genos Sales Bonus slab — Slab 1 records 12 AGP, Slab 2 records 5 AGP, Slab 3 records 2 AGP, up to 120 AGP in a month. Each month's point value is that month's pool divided by the AGP of everyone eligible in it, and your bonus for the month is your AGP multiplied by that point value.
    </div>

    {{-- Summary cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-600 mb-1">
                Net credited (this page)
                <x-help-tip text="Total Growth Booster Bonus credited to you across the months listed on this page. Held and non-payable months are excluded." />
            </p>
            <p class="text-2xl font-bold text-gray-900">{{ $creditedRows->isEmpty() ? '—' : '₹'.\App\Modules\Shared\Support\IndianNumber::format($creditedNetPaise / 100, 0) }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-600 mb-1">
                AGP behind it
                <x-help-tip text="The AGP you recorded in the months that were credited on this page." />
            </p>
            <p class="text-2xl font-bold text-gray-900">{{ $creditedRows->isEmpty() ? '—' : \App\Modules\Shared\Support\IndianNumber::format($creditedAgp) }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-600 mb-1">Months listed</p>
            <p class="text-2xl font-bold text-gray-900">{{ $rows instanceof \Illuminate\Pagination\LengthAwarePaginator ? \App\Modules\Shared\Support\IndianNumber::format($rows->total()) : \App\Modules\Shared\Support\IndianNumber::format(count($rows)) }}</p>
        </div>
    </div>

    {{-- Filter form --}}
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
            <a href="{{ route('income.growth-booster') }}" class="px-4 py-1.5 text-sm text-gray-600 hover:text-gray-800">Clear</a>
        @endif
    </form>

    @if($rows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-600 font-medium">No Growth Booster Bonus recorded yet.</p>
            <p class="text-sm text-gray-600 mt-1">Months in which you recorded AGP from a Slab 1, 2 or 3 Genos Sales Bonus match appear here.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[760px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Month</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">
                                AGP earned
                                <x-help-tip text="Your arovolife Growth Points for this month (Slab 1 = 12, Slab 2 = 5, Slab 3 = 2). Capped at 120." />
                            </span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">
                                Point value
                                <x-help-tip text="That month's Growth Booster pool divided by the AGP of every distributor eligible in it, floored to the rupee. It is fixed once the month is calculated." />
                            </span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">
                                Gross GBB
                                <x-help-tip text="Your AGP for the month multiplied by that month's point value." />
                            </span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">TDS (5%)</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Net GBB</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $row)
                    @php
                        $pointValuePaise = $pointValuePaiseFor($row);
                        $isPayable = $row->status !== 'repurchase_suspended';
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
                            {{ $pointValuePaise !== null ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($pointValuePaise / 100, 2) : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono">
                            ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gbb_gross_paise / 100, 2) }}
                            @if($isPayable && $pointValuePaise !== null && $row->agp_earned > 0)
                            <span class="block text-[11px] text-gray-600 font-sans">
                                {{ \App\Modules\Shared\Support\IndianNumber::format($row->agp_earned) }} AGP × ₹{{ \App\Modules\Shared\Support\IndianNumber::format($pointValuePaise / 100, 2) }}
                            </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-gray-600">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->tds_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold {{ $row->status === 'credited' ? 'text-green-700' : 'text-gray-600' }}">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gbb_net_paise / 100, 2) }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium {{ $statusBadges[$row->status] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ $statusLabels[$row->status] ?? ucfirst(str_replace('_', ' ', $row->status)) }}
                            </span>
                            @isset($statusNotes[$row->status])
                            <span class="block text-[11px] text-gray-600 mt-1">{{ $statusNotes[$row->status] }}</span>
                            @endisset
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
