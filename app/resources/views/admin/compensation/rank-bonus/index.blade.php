@extends('admin.layouts.admin')
@section('title', 'Rank Bonus')
@section('heading', 'Rank Bonus')

@section('content')

@developer
<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    The Rank Bonus is distributed monthly from 9 separate pools (one per rank). Each pool is that rank's percentage of the month's rank envelope — the envelope being a configurable share (default 20%) of the month's company-wide BV. Rank 1 is points-based: achievers earn RAP and AO-GO grantees earn offer points, and the pool is divided by the month's total points. Ranks 2–9 split their pool equally among achievers. Runs automatically on the 8th of each month.
</div>
@enddeveloper

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($months->isEmpty())
        <p class="px-6 py-10 text-sm text-gray-600 text-center">No Rank Bonus batches yet — engine has not yet run.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-600">Month</th>
                    <th class="px-4 py-2 text-right text-gray-600">Distributors credited</th>
                    <th class="px-4 py-2 text-right text-gray-600">Net credited</th>
                    <th class="px-4 py-2 text-right text-gray-600">Credited at</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($months as $m)
                <tr>
                    <td class="px-4 py-2 font-medium">{{ \Illuminate\Support\Carbon::parse($m->month_start)->format('F Y') }}</td>
                    <td class="px-4 py-2 text-right">{{ \App\Modules\Shared\Support\IndianNumber::format($m->qualifier_count) }}</td>
                    <td class="px-4 py-2 text-right font-semibold text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($m->total_net_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right text-gray-600">
                        {{ $m->credited_at ? \Illuminate\Support\Carbon::parse($m->credited_at)->format('d M Y H:i') : '—' }}
                    </td>
                    <td class="px-4 py-2">
                        <a href="{{ route('admin.compensation.rank-bonus.show', \Illuminate\Support\Carbon::parse($m->month_start)->format('Y-m')) }}"
                           class="text-brand-700 text-xs hover:underline">View →</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

@endsection
