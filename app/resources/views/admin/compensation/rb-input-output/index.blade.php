@extends('admin.layouts.admin')
@section('title', 'Rank Bonus Input & Output Per Month')
@section('heading', 'Rank Bonus Input & Output Per Month Calculation')

@section('content')

@developer
<div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Per-month Rank Bonus pool economics, one row per rank. The Rank Bonus is <strong>one pool</strong> — the
    rank envelope, a share of the month's company turnover (BV) — divided by points in two passes.
    <span class="font-medium">Pass 1</span> prices AO-GO and the lower ranks at min(cap, ⌊envelope ÷ their points⌋);
    <span class="font-medium">pass 2</span> prices the higher ranks the same way from what pass 1 left. Everyone
    is paid their own points × their pass's value, and the leftover stays with the company. The pass summary,
    ₹ allotments, qualifier counts and point values are frozen snapshots from the run. A month priced before the
    two-pass rule is labelled, has no pass summary, and pairs its turnover with the current envelope % setting.
    Search by month or month range.
</div>
@enddeveloper

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-4">
    <input type="month" name="month" value="{{ $month ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <input type="month" name="from" value="{{ $from ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <input type="month" name="to" value="{{ $to ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <x-ui.button type="submit" size="sm">Apply</x-ui.button>
    @if($month || $from || $to)
    <a href="{{ route('admin.compensation.rb-input-output.index') }}"
       class="text-sm text-gray-500 hover:text-gray-700">Clear</a>
    @endif
    <a href="{{ route('admin.compensation.rb-input-output.export', array_merge(array_filter(['month' => $month, 'from' => $from, 'to' => $to]), ['format' => 'xlsx'])) }}"
       class="ml-auto px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs text-gray-700 hover:bg-gray-50">
        ↓ Download Excel
    </a>
    <a href="{{ route('admin.compensation.rb-input-output.export', array_merge(array_filter(['month' => $month, 'from' => $from, 'to' => $to]), ['format' => 'csv'])) }}"
       class="px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-sm text-gray-700 hover:bg-gray-50">
        CSV
    </a>
</form>

@if($months->isEmpty())
<x-ui.card flush>
    <x-ui.empty-state title="No Rank Bonus months yet."
                      description="Rows appear once the monthly rank run credits a month." />
</x-ui.card>
@else
<div class="space-y-6">
    @foreach($months->items() as $monthRow)
    @php
        $monthStart = \Illuminate\Support\Carbon::parse($monthRow->month_start)->toDateString();
        $block = $blocks[$monthStart];
    @endphp
    <x-ui.card flush>
        <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex flex-wrap items-center gap-x-6 gap-y-1 text-xs">
            <span class="font-semibold text-gray-800">{{ \Illuminate\Support\Carbon::parse($monthStart)->format('F Y') }}</span>
            <span class="text-gray-500">Month turnover
                <strong class="text-gray-700">@if($block['turnover_paise'] !== null)@bv($block['turnover_paise'])@else — @endif</strong></span>
            <span class="text-gray-500">Rank envelope ({{ \App\Modules\Shared\Support\IndianNumber::percentFromBp($block['envelope_bp']) }})
                @if($block['legacy'])
                <x-help-tip text="The envelope % is a current plan setting, not a frozen snapshot — the ₹ pool amounts of ranks that paid ARE frozen on the run's result rows." />
                @else
                <x-help-tip text="The one pool both passes divide — frozen on the month's pass rows by the run, with the envelope % in force that month." />
                @endif
                @if($block['envelope_paise'] !== null)
                <strong class="text-gray-700">{{ \App\Modules\Shared\Support\IndianNumber::rupees($block['envelope_paise']) }}</strong>
                @endif
            </span>
            <span class="text-gray-500 ml-auto">Computed
                <strong class="text-gray-700">{{ $block['computed_at']?->format('d M Y H:i') ?? '—' }}</strong>
                <x-help-tip text="When this month's Rank Bonus rows were written — the figures reflect the data as it stood at this moment. On a testing recompute this is the recompute time, not the month's end." /></span>
        </div>
        @include('admin.compensation._formulas.rb-month', ['rank1' => $rank1Snapshots[$monthStart] ?? null, 'date' => \Illuminate\Support\Carbon::parse($monthStart), 'rankNames' => $rankNames, 'embedded' => true, 'open' => count($months->items()) === 1])

        @if($block['legacy'])
        <div class="px-4 py-2 border-b border-gray-200 bg-amber-50 text-[11px] text-amber-800">
            This month was priced under the per-rank pool rule in force before the two-pass rule (client 2026-10-05); it has no pass summary.
        </div>
        @else
        @if($block['passes_incomplete'])
        <div class="px-4 py-2 border-b border-gray-200 bg-amber-50 text-[11px] text-amber-800 font-medium">
            Pass row missing — this month's freeze is incomplete. The engine writes both pass rows together; rebuild the month before trusting any figure on it.
        </div>
        @endif
        <div class="overflow-x-auto border-b border-gray-200">
            <table class="w-full text-xs">
                <caption class="px-3 pt-2 text-left text-[11px] font-medium text-gray-600">
                    Two-pass formula — one pool, priced in two passes
                    <x-help-tip text="Pass 1 divides the whole envelope among AO-GO and the lower ranks; pass 2 divides what pass 1 left among the higher ranks. Each pass value is min(cap, ⌊pool ÷ points⌋); everything here is frozen on the month's pass rows." />
                </caption>
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left text-gray-500 font-medium">Pass</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Pool</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Points</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Raw value <x-help-tip text="The pool divided by the points, floored to the whole rupee, before the cap." /></th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Cap</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Point value</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Payout</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Leftover <x-help-tip text="Pool minus payout. Pass 1's leftover is pass 2's pool; pass 2's leftover stays with the company." /></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($block['passes'] as $pass)
                    <tr>
                        <td class="px-3 py-2 font-medium text-gray-700">Pass {{ $pass['pass'] }}</td>
                        <td class="px-3 py-2 text-right text-gray-700">{{ \App\Modules\Shared\Support\IndianNumber::rupees($pass['pool_paise']) }}</td>
                        <td class="px-3 py-2 text-right">{{ \App\Modules\Shared\Support\IndianNumber::format($pass['total_points']) }}</td>
                        <td class="px-3 py-2 text-right">{{ $pass['total_points'] > 0 ? \App\Modules\Shared\Support\IndianNumber::rupees($pass['raw_point_value_paise']) : '—' }}</td>
                        <td class="px-3 py-2 text-right">{{ \App\Modules\Shared\Support\IndianNumber::rupees($pass['point_value_cap_paise']) }}</td>
                        <td class="px-3 py-2 text-right font-medium text-gray-700">{{ $pass['total_points'] > 0 ? \App\Modules\Shared\Support\IndianNumber::rupees($pass['point_value_paise']) : '—' }}</td>
                        <td class="px-3 py-2 text-right text-gray-700">{{ \App\Modules\Shared\Support\IndianNumber::rupees($pass['payout_paise']) }}</td>
                        <td class="px-3 py-2 text-right">{{ \App\Modules\Shared\Support\IndianNumber::rupees($pass['leftover_paise']) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left text-gray-500 font-medium">Rank</th>
                        <th class="px-3 py-2 text-center text-gray-500 font-medium">Pass <x-help-tip text="Which pass priced this rank. A month priced before the two-pass rule shows —." /></th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Pool</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Qualifiers</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Held <x-help-tip text="Re-qualifiers who failed the requalification conditions — recorded but never credited, and excluded from the pool split." /></th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Blocked <x-help-tip text="Qualifiers whose repurchase wallet was not at ₹0 at month end. They earned the rank but are paid nothing, and their share stays in Leftover." /></th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Points</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Point value / share <x-help-tip text="The value of one point in this rank's pass — min(cap, ⌊pool ÷ points⌋) — so each achiever is paid own points × this value. A month priced before the two-pass rule shows its Rank 1 point value or the equal per-qualifier share of ranks 2–9." /></th>
                        <x-bonus-credit-head gross-label="Income" th-class="px-3 py-2 text-right text-gray-500 font-medium" />
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Leftover <x-help-tip text="For a two-pass month each rank is allotted exactly what it pays, so its leftover is ₹0 and the month's leftover (the footer) is pass 2's, stored by the engine. A month priced before the two-pass rule derives it per rank: pool minus income paid." /></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($block['ranks'] as $rank)
                    <tr class="hover:bg-gray-50 {{ $rank['frozen'] ? '' : 'text-gray-400' }}">
                        <td class="px-3 py-2">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full {{ $rank['frozen'] ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-500' }} font-bold text-[11px]">{{ $rank['rank'] }}</span>
                            <span class="ml-1 {{ $rank['frozen'] ? 'text-gray-700' : '' }} font-medium">{{ $rank['name'] }}</span>
                        </td>
                        <td class="px-3 py-2 text-center" data-pass-cell>{{ $rank['pass'] ?? '—' }}</td>
                        <td class="px-3 py-2 text-right {{ $rank['frozen'] ? 'text-gray-700' : '' }}">
                            ₹{{ \App\Modules\Shared\Support\IndianNumber::format($rank['pool_paise'] / 100, 2) }}{{ $rank['frozen'] ? '' : ' *' }}
                        </td>
                        <td class="px-3 py-2 text-right">{{ \App\Modules\Shared\Support\IndianNumber::format($rank['qualifiers']) }}</td>
                        <td class="px-3 py-2 text-right">{{ $rank['held'] > 0 ? \App\Modules\Shared\Support\IndianNumber::format($rank['held']) : '—' }}</td>
                        <td class="px-3 py-2 text-right {{ $rank['blocked'] > 0 ? 'text-amber-700 font-medium' : '' }}">{{ $rank['blocked'] > 0 ? \App\Modules\Shared\Support\IndianNumber::format($rank['blocked']) : '—' }}</td>
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
                        <x-bonus-credit-cells :gross="$rank['income_paise']" :deduction="$rank['deduction_paise']" :credited="$rank['credited_paise']" :is-credited="$rank['frozen'] && $rank['income_paise'] > 0" td-class="px-3 py-2 text-right" />
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
                            <span class="ml-1 text-gray-600">{{ $block['legacy'] ? 'shared the Rank 1 pool' : 'priced in pass 1 with Rank 1' }}</span>
                        </td>
                        <td class="px-3 py-2 text-center">{{ $block['legacy'] ? '—' : 1 }}</td>
                        <td class="px-3 py-2 text-right text-gray-400">—</td>
                        <td class="px-3 py-2 text-right text-gray-700">{{ \App\Modules\Shared\Support\IndianNumber::format($block['aogo']['grants']) }}</td>
                        <td class="px-3 py-2 text-right text-gray-400">—</td>
                        <td class="px-3 py-2 text-right text-gray-400">—</td>
                        <td class="px-3 py-2 text-right text-gray-700">{{ \App\Modules\Shared\Support\IndianNumber::format($block['aogo']['points']) }}</td>
                        <td class="px-3 py-2 text-right text-gray-700">
                            {{ $block['aogo']['point_value_paise'] !== null ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($block['aogo']['point_value_paise'] / 100, 2) : '—' }}
                        </td>
                        <x-bonus-credit-cells :gross="$block['aogo']['income_paise']" :deduction="$block['aogo']['deduction_paise']" :credited="$block['aogo']['credited_paise']" :is-credited="true" td-class="px-3 py-2 text-right" />
                        <td class="px-3 py-2 text-right text-gray-400">—</td>
                    </tr>
                    @endif
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50 border-t-2 border-gray-200 text-gray-800">
                    <tr class="font-semibold">
                        <td class="px-3 py-2 text-right text-xs" colspan="8">Total income / deduction / credited</td>
                        <td class="px-3 py-2 text-right text-gray-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($block['total_income_paise'] / 100, 2) }}</td>
                        <td class="px-3 py-2 text-right {{ $block['total_deduction_paise'] > 0 ? 'text-red-600' : 'text-gray-500' }}">{{ $block['total_deduction_paise'] > 0 ? '-₹'.\App\Modules\Shared\Support\IndianNumber::format($block['total_deduction_paise'] / 100, 2) : '—' }}</td>
                        <td class="px-3 py-2 text-right text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($block['total_credited_paise'] / 100, 2) }}</td>
                        <td class="px-3 py-2 text-right {{ $block['total_leftover_paise'] < 0 ? 'text-red-600' : 'text-gray-500' }} text-[11px]">
                            @if($block['passes_incomplete'])
                            <span class="text-amber-800">leftover unknown (pass row missing)</span>
                            @else
                            leftover {{ $block['total_leftover_paise'] < 0 ? '−' : '' }}₹{{ \App\Modules\Shared\Support\IndianNumber::format(abs($block['total_leftover_paise']) / 100, 2) }}
                            @endif
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        @if(collect($block['ranks'])->contains(fn (array $rank): bool => ! $rank['frozen']))
        <div class="px-4 py-2 border-t border-gray-100 text-[11px] text-gray-400">
            * this rank had no qualifiers in a month priced before the two-pass rule, so nothing was frozen
            for it.
        </div>
        @endif
    </x-ui.card>
    @endforeach
</div>
<div class="mt-4">{{ $months->links() }}</div>
@endif

@endsection
