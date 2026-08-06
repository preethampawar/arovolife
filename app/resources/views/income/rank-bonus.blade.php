@extends('layouts.app')
@section('title', 'My Income — Rank Bonus')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My Income</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        The Rank Bonus is paid monthly from each rank's pool (a share of the company's business volume for that month).
        Rank 1 (Silver) is points-based: each achiever earned 10 RAP and each AO-GO grantee 5 points that month; the pool was divided by the month's total points and your income is your points × the point value.
        Ranks 2–9 pools are split equally among that rank's achievers.
        Re-qualifying a rank you already achieved requires that month's repurchase BV and a cleared repurchase wallet.
        Admin charge (min of 3%, max ₹25,000 per monthly batch) and 5% TDS are deducted. Credited on the 8th of the following month.
    </div>

    {{-- Summary cards --}}
    <div class="grid grid-cols-1 {{ ($aogoUsed ?? 0) > 0 ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }} gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1">Net Rank Bonus earned (page)</p>
            <p class="text-2xl font-bold text-gray-900">
                {{ $rows->isEmpty() ? '—' : '₹'.\App\Modules\Shared\Support\IndianNumber::format($totalNet / 100, 0) }}
            </p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1">Months credited</p>
            <p class="text-2xl font-bold text-gray-900">
                {{ $rows instanceof \Illuminate\Pagination\LengthAwarePaginator ? \App\Modules\Shared\Support\IndianNumber::format($rows->total()) : count($rows) }}
            </p>
        </div>
        @if(($aogoUsed ?? 0) > 0)
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1 flex items-center justify-center gap-1">
                AO-GO offer used
                <x-help-tip text="Achieve Once – Get Once: if you lose your rank, you can earn 5 points in the Rank-1 pool up to {{ $aogoMax }} times in your lifetime — never in consecutive months, and only after re-achieving a rank between uses." />
            </p>
            <p class="text-2xl font-bold text-gray-900">{{ $aogoUsed }} of {{ $aogoMax }}</p>
        </div>
        @endif
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
            <a href="{{ route('income.rank-bonus') }}" class="px-4 py-1.5 text-sm text-gray-600 hover:text-gray-800">Clear</a>
        @endif
    </form>

    @if($rows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-500 font-medium">No Rank Bonus yet.</p>
            <p class="text-sm text-gray-400 mt-1">Your Rank Bonus will appear here once you qualify for a rank in a calendar month.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[600px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Month</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Rank</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">
                                Points × Value
                                <x-help-tip text="Rank 1 only: RAP (Rank Achievement Points, 10 per achiever) or AO-GO offer points (5), multiplied by the month's point value (Rank-1 pool ÷ total points). Ranks 2–9 are an equal split, shown as —." />
                            </span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Gross</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">
                                Admin <x-help-tip text="min(3% of gross, ₹25,000 per monthly batch)" />
                            </span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">TDS (5%)</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Net</th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $row)
                    @php
                    $rankNames = app(\App\Modules\Compensation\Services\CompensationPlanSettingsService::class)->rankNames();
                    $sc = ['credited' => 'bg-green-100 text-green-700', 'reversed' => 'bg-red-100 text-red-700', 'pending' => 'bg-gray-100 text-gray-600'];
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-800">
                            {{ \Illuminate\Support\Carbon::parse($row->month_start)->format('F Y') }}
                        </td>
                        <td class="px-4 py-3">
                            @if($row->aogo_points !== null)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-teal-100 text-teal-700">
                                AO-GO Offer
                            </span>
                            @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700">
                                {{ $rankNames[$row->rank_number] ?? 'Rank '.$row->rank_number }}
                            </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-gray-600">
                            @php $points = $row->rap_points ?? $row->aogo_points; @endphp
                            @if($points !== null && $row->point_value_paise !== null)
                                {{ $points }} × ₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->point_value_paise / 100, 0) }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-mono">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gross_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-gray-500">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->admin_charge_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-gray-500">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->tds_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->net_paise / 100, 2) }}</td>
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
