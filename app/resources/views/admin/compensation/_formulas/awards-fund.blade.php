{{-- Lifetime Awards fund, month by month (client 2026-10-09, Q1). A report, never a gate.
     Expects: list $fund (BonusCalculationSnapshots::awardsFund), int $fundRateBp --}}
@php
    $inr = \App\Modules\Shared\Support\IndianNumber::rupees(...);
    // rupees() puts the sign after the ₹ ("₹-5,400"); a balance reads "-₹5,400".
    $signed = fn (int $paise): string => $paise < 0 ? '-'.$inr(-$paise, 0) : $inr($paise, 0);
    $currentMonth = \Illuminate\Support\Carbon::now('Asia/Kolkata')->startOfMonth()->toDateString();
    $last = $fund === [] ? null : $fund[array_key_last($fund)];
    $shortMonths = collect($fund)->filter(fn (array $m): bool => $m['balance_paise'] < 0)->count();
@endphp
<details class="group bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6">
    <summary class="px-4 py-3 bg-gray-50 group-open:border-b border-gray-200 flex flex-wrap items-center gap-x-6 gap-y-1 text-xs list-none [&::-webkit-details-marker]:hidden cursor-pointer select-none" title="Show or hide the awards fund">
        <x-lucide-chevron-right class="h-3.5 w-3.5 text-gray-400 shrink-0 transition-transform group-open:rotate-90" aria-hidden="true" />
        <span class="font-semibold text-gray-800">Awards fund</span>
        <span class="text-gray-500">Rate <strong class="text-gray-700">{{ \App\Modules\Shared\Support\IndianNumber::percentFromBp($fundRateBp) }}</strong> of each month's BV
            <x-help-tip text="The client funds the Lifetime Awards & Rewards from this share of sales (one of seven shares that add up to 100%). Setting: Lifetime Awards fund rate. Every month is shown at the current rate; each rate change is in the settings audit log." /></span>
        <span class="text-gray-500 ml-auto">Balance
            <x-help-tip text="Σ fund added − Σ award worth earned, from the first month with sales or an award. A report only: an earned award is never delayed by it." />
            <strong class="{{ ($last['balance_paise'] ?? 0) < 0 ? 'text-red-600' : 'text-gray-700' }}">{{ $last ? $signed($last['balance_paise']) : '—' }}</strong></span>
    </summary>

    @if($shortMonths > 0)
    <div class="px-4 py-2 bg-red-50 border-b border-red-100 text-[11px] text-red-800">
        The award worth earned has run ahead of the awards fund in {{ \App\Modules\Shared\Support\IndianNumber::format($shortMonths) }}
        {{ $shortMonths === 1 ? 'month' : 'months' }}. Awards are still released; tell the client, because the awards must be paid from sales.
    </div>
    @endif

    <div class="px-4 py-4 overflow-x-auto text-xs">
        @if($fund === [])
        <p class="text-gray-500">No sales and no awards yet.</p>
        @else
        <table class="w-full font-mono text-gray-700">
            <thead>
                <tr class="text-gray-500 font-sans">
                    <th class="text-left font-medium pb-1">Month</th>
                    <th class="text-right font-medium pb-1">Month BV</th>
                    <th class="text-right font-medium pb-1">Fund added</th>
                    <th class="text-right font-medium pb-1">Award worth earned</th>
                    <th class="text-right font-medium pb-1">Balance</th>
                </tr>
            </thead>
            <tbody>
                @foreach(array_reverse($fund) as $m)
                <tr>
                    <td class="py-0.5 font-sans">{{ \Illuminate\Support\Carbon::parse($m['month_start'])->format('M Y') }}@if($m['month_start'] === $currentMonth) <span class="text-gray-400">(in progress)</span>@endif</td>
                    <td class="py-0.5 text-right">{{ \App\Modules\Shared\Support\IndianNumber::format($m['bv_paise'] / 100) }}</td>
                    <td class="py-0.5 text-right">{{ $inr($m['fund_paise'], 0) }}</td>
                    <td class="py-0.5 text-right">{{ $inr($m['awarded_paise'], 0) }}</td>
                    <td class="py-0.5 text-right {{ $m['balance_paise'] < 0 ? 'text-red-600 font-semibold' : '' }}">{{ $signed($m['balance_paise']) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</details>
