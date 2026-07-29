@extends('admin.layouts.admin')
@section('title', 'GSB Input & Output Per Day')
@section('heading', 'GSB Input & Output Per Day Calculation')

@section('content')

<div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Per-day GSB pool economics (from the first pooled day onward). Each day shows the company's total BV, the
    GSB pool (the configured pool rate of that day's BV), the <strong>fixed</strong> section (slabs 1–2 — always paid at their fixed
    score value) and the <strong>variable</strong> section (slabs 3–7 — the day's pro-rated score value, capped at the
    fixed value; the variance column shows how far below the cap the day landed). Search by day number, week number
    or date range.
</div>

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-4">
    <input type="number" name="day" value="{{ $day ?? '' }}" min="1" placeholder="Day #"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-24">
    <input type="number" name="week" value="{{ $week ?? '' }}" min="1" placeholder="Week #"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-24">
    <input type="date" name="from" value="{{ $from ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <input type="date" name="to" value="{{ $to ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-500 text-white text-sm font-medium">Apply</button>
    @if($day || $week || $from || $to)
    <a href="{{ route('admin.compensation.gsb-input-output.index') }}"
       class="text-sm text-gray-500 hover:text-gray-700">Clear</a>
    @endif
    <a href="{{ route('admin.compensation.gsb-input-output.export', array_filter(['day' => $day, 'week' => $week, 'from' => $from, 'to' => $to])) }}"
       class="ml-auto px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs text-gray-700 hover:bg-gray-50">
        ↓ Download CSV
    </a>
</form>

@if($pools->isEmpty())
<div class="bg-white rounded-xl border border-gray-200 shadow-sm">
    <p class="px-6 py-8 text-sm text-gray-400 text-center">
        No pooled cut-off days yet — rows appear once the GSB daily pool pricing runs its first cut-off.
    </p>
</div>
@else
<div class="space-y-6">
    @foreach($pools->items() as $pool)
    @php
        $dateStr = $pool->cutoff_date->toDateString();
        $dayNo = $anchor === null ? null : (int) $anchor->diffInDays($pool->cutoff_date->copy()->startOfDay()) + 1;
        $weekNo = $dayNo === null ? null : intdiv($dayNo - 1, 7) + 1;
        $aggs = collect($slabAggregates[$dateStr] ?? []);
        $fixedRows = $aggs->filter(fn ($a) => (int) $a->slab < 3)->values();
        $variableRows = $aggs->filter(fn ($a) => (int) $a->slab >= 3)->values();
        $fixedIncome = (int) $fixedRows->sum('income_paise');
        $variableIncome = (int) $variableRows->sum('income_paise');
        $rowValue = function ($agg) use ($pool) {
            if ($agg->snap_value_paise !== null) { return (int) $agg->snap_value_paise; }
            return (int) $agg->slab >= 3 ? (int) $pool->variable_score_value_paise : (int) ($agg->fixed_value_paise ?? 0);
        };
    @endphp
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex flex-wrap items-center gap-x-6 gap-y-1 text-xs">
            <span class="font-semibold text-gray-800">{{ $pool->cutoff_date->format('d/m/Y') }}</span>
            <span class="text-gray-500">Day <strong class="text-gray-700">{{ $dayNo ?? '—' }}</strong></span>
            <span class="text-gray-500">Week <strong class="text-gray-700">{{ $weekNo ?? '—' }}</strong></span>
            <span class="text-gray-500">Day total BV <strong class="text-gray-700">@bv($pool->company_bv_paise)</strong></span>
            <span class="text-gray-500">GSB pool ({{ rtrim(rtrim(number_format($pool->pool_rate_bp / 100, 2), '0'), '.') }}%)
                <strong class="text-gray-700">₹{{ \Illuminate\Support\Number::format($pool->pool_paise / 100, 2) }}</strong></span>
            <span class="text-gray-500">Variable score value
                <strong class="text-gray-700">₹{{ \Illuminate\Support\Number::format($pool->variable_score_value_paise / 100, 2) }}</strong>
                <span class="text-gray-400">(cap ₹{{ \Illuminate\Support\Number::format($pool->variable_score_value_cap_paise / 100, 2) }})</span></span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left text-gray-500 font-medium">Slab</th>
                        <th class="px-3 py-2 text-center text-gray-500 font-medium">Section</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Achievers</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Total score</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Score value <x-help-tip text="Rupees per score point used on this day. Slabs 1–2: the fixed value. Slabs 3–7: the day's pro-rated pool value (never above the cap)." /></th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Income</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Variance <x-help-tip text="Variable score value minus the fixed cap: 0 when the pool covered the full value, negative when the day was pro-rated down. Fixed slabs always 0." /></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($aggs as $agg)
                    @php
                        $isFixed = (int) $agg->slab < 3;
                        $value = $rowValue($agg);
                        $variance = $isFixed ? 0 : $value - (int) $pool->variable_score_value_cap_paise;
                    @endphp
                    <tr class="hover:bg-gray-50 {{ $isFixed ? '' : 'bg-amber-50/30' }}">
                        <td class="px-3 py-2">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full {{ $isFixed ? 'bg-green-100 text-green-700' : 'bg-indigo-100 text-indigo-700' }} font-bold text-[11px]">
                                {{ $agg->slab }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-center">
                            <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $isFixed ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' }}">
                                {{ $isFixed ? 'Fixed' : 'Variable' }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-right text-gray-700">{{ \Illuminate\Support\Number::format((int) $agg->achievers) }}</td>
                        <td class="px-3 py-2 text-right font-semibold text-gray-800">{{ \Illuminate\Support\Number::format((int) $agg->total_score) }}</td>
                        <td class="px-3 py-2 text-right text-gray-700">₹{{ \Illuminate\Support\Number::format($value / 100, 2) }}</td>
                        <td class="px-3 py-2 text-right font-semibold text-green-700">₹{{ \Illuminate\Support\Number::format($agg->income_paise / 100, 2) }}</td>
                        <td class="px-3 py-2 text-right {{ $variance < 0 ? 'text-red-600 font-medium' : 'text-gray-400' }}">
                            {{ $variance === 0 ? '0' : '−₹'.\Illuminate\Support\Number::format(abs($variance) / 100, 2) }}
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-3 py-4 text-center text-gray-400">No slab achievers this day.</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="bg-gray-50 border-t-2 border-gray-200 text-gray-800">
                    <tr>
                        <td class="px-3 py-1.5 text-right text-xs" colspan="5">Fixed section total (slabs 1–2)</td>
                        <td class="px-3 py-1.5 text-right font-semibold">₹{{ \Illuminate\Support\Number::format($fixedIncome / 100, 2) }}</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td class="px-3 py-1.5 text-right text-xs" colspan="5">Variable section total (slabs 3–7)</td>
                        <td class="px-3 py-1.5 text-right font-semibold">₹{{ \Illuminate\Support\Number::format($variableIncome / 100, 2) }}</td>
                        <td></td>
                    </tr>
                    <tr class="font-semibold">
                        <td class="px-3 py-2 text-right text-xs" colspan="5">Grand total income</td>
                        <td class="px-3 py-2 text-right text-green-700">₹{{ \Illuminate\Support\Number::format(($fixedIncome + $variableIncome) / 100, 2) }}</td>
                        <td class="px-3 py-2 text-right {{ $pool->leftover_paise < 0 ? 'text-red-600' : 'text-gray-500' }} text-[11px]">
                            leftover {{ $pool->leftover_paise < 0 ? '−' : '' }}₹{{ \Illuminate\Support\Number::format(abs($pool->leftover_paise) / 100, 2) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    @endforeach
</div>
<div class="mt-4">{{ $pools->links() }}</div>
@endif

@endsection
