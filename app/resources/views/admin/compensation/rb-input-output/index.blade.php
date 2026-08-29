@extends('admin.layouts.admin')
@section('title', 'Rank Bonus Input & Output Per Month')
@section('heading', 'Rank Bonus Input & Output Per Month Calculation')

@section('content')

<div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Per-month Rank Bonus pool economics, one row per rank. Each month shows the company turnover (BV), each
    rank's pool (its share of the <strong>{{ \App\Modules\Shared\Support\IndianNumber::percentFromBp($envelopeBp) }}
    rank envelope</strong>), the qualifiers it paid and the income they received.
    <span class="font-medium">Rank 1 divides its pool by points (RAP + AO-GO)</span>; ranks 2–9 split their
    pool equally among qualifiers. The ₹ pools, qualifier counts and point values are frozen snapshots from
    the run; the envelope % and per-rank pool % shown are <strong>current plan settings</strong>, as are the
    asterisked pools of ranks that had no qualifiers. Search by month or month range.
</div>

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-4">
    <input type="month" name="month" value="{{ $month ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <input type="month" name="from" value="{{ $from ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <input type="month" name="to" value="{{ $to ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-500 text-white text-sm font-medium">Apply</button>
    @if($month || $from || $to)
    <a href="{{ route('admin.compensation.rb-input-output.index') }}"
       class="text-sm text-gray-500 hover:text-gray-700">Clear</a>
    @endif
    <a href="{{ route('admin.compensation.rb-input-output.export', array_filter(['month' => $month, 'from' => $from, 'to' => $to])) }}"
       class="ml-auto px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs text-gray-700 hover:bg-gray-50">
        ↓ Download CSV
    </a>
</form>

@if($months->isEmpty())
<div class="bg-white rounded-xl border border-gray-200 shadow-sm">
    <p class="px-6 py-8 text-sm text-gray-400 text-center">
        No Rank Bonus months yet — rows appear once the monthly rank run credits a month.
    </p>
</div>
@else
<div class="space-y-6">
    @foreach($months->items() as $monthRow)
    @php
        $monthStart = \Illuminate\Support\Carbon::parse($monthRow->month_start)->toDateString();
        $block = $blocks[$monthStart];
    @endphp
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex flex-wrap items-center gap-x-6 gap-y-1 text-xs">
            <span class="font-semibold text-gray-800">{{ \Illuminate\Support\Carbon::parse($monthStart)->format('F Y') }}</span>
            <span class="text-gray-500">Month turnover
                <strong class="text-gray-700">@if($block['turnover_paise'] !== null)@bv($block['turnover_paise'])@else — @endif</strong></span>
            <span class="text-gray-500">Rank envelope ({{ \App\Modules\Shared\Support\IndianNumber::percentFromBp($envelopeBp) }})
                <x-help-tip text="The envelope % and each rank's pool % are current plan settings, not frozen snapshots — the ₹ pool amounts of ranks that paid ARE frozen on the run's result rows." />
                @if($block['turnover_paise'] !== null)
                <strong class="text-gray-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format(($block['turnover_paise'] * $envelopeBp / 10000) / 100, 2) }}</strong>
                @endif
            </span>
            <span class="text-gray-500 ml-auto">Computed
                <strong class="text-gray-700">{{ $block['computed_at']?->format('d M Y H:i') ?? '—' }}</strong>
                <x-help-tip text="When this month's Rank Bonus rows were written — the figures reflect the data as it stood at this moment. On a testing recompute this is the recompute time, not the month's end." /></span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left text-gray-500 font-medium">Rank</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Pool % <x-help-tip text="This rank's share of the rank envelope, as currently configured in Plan Settings." /></th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Pool</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Qualifiers</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Held <x-help-tip text="Re-qualifiers who failed the requalification conditions — recorded but never credited, and excluded from the pool split." /></th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Points</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Point value / share <x-help-tip text="Rank 1: the pool divided by total points (RAP + AO-GO), floored to whole rupees. Ranks 2–9: the equal per-qualifier share." /></th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Income</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Leftover <x-help-tip text="Pool minus income paid — the flooring remainder the engine leaves unspent. Derived by this report; the engine does not store it." /></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($block['ranks'] as $rank)
                    <tr class="hover:bg-gray-50 {{ $rank['frozen'] ? '' : 'text-gray-400' }}">
                        <td class="px-3 py-2">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full {{ $rank['frozen'] ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-500' }} font-bold text-[11px]">{{ $rank['rank'] }}</span>
                            <span class="ml-1 {{ $rank['frozen'] ? 'text-gray-700' : '' }} font-medium">{{ $rank['name'] }}</span>
                        </td>
                        <td class="px-3 py-2 text-right">{{ \App\Modules\Shared\Support\IndianNumber::percent($rank['pool_pct']) }}</td>
                        <td class="px-3 py-2 text-right {{ $rank['frozen'] ? 'text-gray-700' : '' }}">
                            ₹{{ \App\Modules\Shared\Support\IndianNumber::format($rank['pool_paise'] / 100, 2) }}{{ $rank['frozen'] ? '' : ' *' }}
                        </td>
                        <td class="px-3 py-2 text-right">{{ \App\Modules\Shared\Support\IndianNumber::format($rank['qualifiers']) }}</td>
                        <td class="px-3 py-2 text-right">{{ $rank['held'] > 0 ? \App\Modules\Shared\Support\IndianNumber::format($rank['held']) : '—' }}</td>
                        <td class="px-3 py-2 text-right">{{ $rank['total_points'] !== null ? \App\Modules\Shared\Support\IndianNumber::format($rank['total_points']) : '—' }}</td>
                        <td class="px-3 py-2 text-right">
                            @if($rank['point_value_paise'] !== null)
                                ₹{{ \App\Modules\Shared\Support\IndianNumber::format($rank['point_value_paise'] / 100, 2) }}
                            @elseif($rank['share_paise'] !== null)
                                ₹{{ \App\Modules\Shared\Support\IndianNumber::format($rank['share_paise'] / 100, 2) }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right {{ $rank['income_paise'] > 0 ? 'font-semibold text-green-700' : '' }}">₹{{ \App\Modules\Shared\Support\IndianNumber::format($rank['income_paise'] / 100, 2) }}</td>
                        <td class="px-3 py-2 text-right {{ ($rank['leftover_paise'] ?? 0) < 0 ? 'text-red-600 font-medium' : '' }}">
                            @if($rank['leftover_paise'] !== null)
                                {{ $rank['leftover_paise'] < 0 ? '−' : '' }}₹{{ \App\Modules\Shared\Support\IndianNumber::format(abs($rank['leftover_paise']) / 100, 2) }}
                            @else
                                unspent *
                            @endif
                        </td>
                    </tr>
                    @if($rank['rank'] === 1 && $block['aogo'] !== null)
                    <tr class="hover:bg-gray-50 bg-amber-50/30">
                        <td class="px-3 py-2 pl-6">
                            <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium bg-amber-100 text-amber-700">AO-GO</span>
                            <span class="ml-1 text-gray-600">shares the Rank 1 pool</span>
                        </td>
                        <td class="px-3 py-2 text-right text-gray-400">—</td>
                        <td class="px-3 py-2 text-right text-gray-400">—</td>
                        <td class="px-3 py-2 text-right text-gray-700">{{ \App\Modules\Shared\Support\IndianNumber::format($block['aogo']['grants']) }}</td>
                        <td class="px-3 py-2 text-right text-gray-400">—</td>
                        <td class="px-3 py-2 text-right text-gray-700">{{ \App\Modules\Shared\Support\IndianNumber::format($block['aogo']['points']) }}</td>
                        <td class="px-3 py-2 text-right text-gray-700">
                            {{ $block['aogo']['point_value_paise'] !== null ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($block['aogo']['point_value_paise'] / 100, 2) : '—' }}
                        </td>
                        <td class="px-3 py-2 text-right font-semibold text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($block['aogo']['income_paise'] / 100, 2) }}</td>
                        <td class="px-3 py-2 text-right text-gray-400">—</td>
                    </tr>
                    @endif
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50 border-t-2 border-gray-200 text-gray-800">
                    <tr class="font-semibold">
                        <td class="px-3 py-2 text-right text-xs" colspan="7">Total income</td>
                        <td class="px-3 py-2 text-right text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($block['total_income_paise'] / 100, 2) }}</td>
                        <td class="px-3 py-2 text-right {{ $block['total_leftover_paise'] < 0 ? 'text-red-600' : 'text-gray-500' }} text-[11px]">
                            leftover {{ $block['total_leftover_paise'] < 0 ? '−' : '' }}₹{{ \App\Modules\Shared\Support\IndianNumber::format(abs($block['total_leftover_paise']) / 100, 2) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="px-4 py-2 border-t border-gray-100 text-[11px] text-gray-400">
            * estimated from the month's turnover and the current plan settings — this rank had no qualifiers,
            so nothing was frozen and its pool went unspent.
        </div>
    </div>
    @endforeach
</div>
<div class="mt-4">{{ $months->links() }}</div>
@endif

@endsection
