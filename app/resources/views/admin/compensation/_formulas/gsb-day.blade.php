{{-- GSB cut-off day header + slab 3–7 score-value formula (frozen gsb_daily_pools row).
     Expects: \App\Modules\Compensation\Models\GsbDailyPool $pool --}}
@php
    $inr = \App\Modules\Shared\Support\IndianNumber::rupees(...);
    $pct = \App\Modules\Shared\Support\IndianNumber::percentFromBp($pool->pool_rate_bp);
    $remainderPaise = max(0, $pool->pool_paise - $pool->fixed_payout_paise);
    $rawValue = $pool->variable_total_score > 0 ? intdiv($remainderPaise, $pool->variable_total_score) : null;
    $wasCapped = $rawValue !== null && intdiv($rawValue, 100) * 100 > $pool->variable_score_value_cap_paise;
@endphp
{{-- Collapsed by default; a page showing a single period opens it. The header row is the summary.
     With embedded => true it renders as a strip inside a report card that already shows the header. --}}
<details class="group {{ ($embedded ?? false) ? 'border-b border-gray-200' : 'bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6' }}"{{ ($open ?? false) ? ' open' : '' }}>
    <summary class="{{ ($embedded ?? false) ? 'px-4 py-2 hover:bg-gray-50' : 'px-4 py-3 bg-gray-50 group-open:border-b border-gray-200' }} flex flex-wrap items-center gap-x-6 gap-y-1 text-xs list-none [&::-webkit-details-marker]:hidden cursor-pointer select-none" title="Show or hide the calculation">
        <x-lucide-chevron-right class="h-3.5 w-3.5 text-gray-400 shrink-0 transition-transform group-open:rotate-90" aria-hidden="true" />
        @if($embedded ?? false)
        <span class="text-gray-600">How this day's slab 3–7 score value was calculated</span>
        @else
        <span class="font-semibold text-gray-800">{{ $pool->cutoff_date->format('d/m/Y') }}</span>
        <span class="text-gray-500">Day total BV <strong class="text-gray-700">@bv($pool->company_bv_paise)</strong>
            <x-help-tip text="The company-wide BV received on this cut-off day, as the engine froze it — the base of the GSB pool." /></span>
        <span class="text-gray-500">GSB pool ({{ $pct }}) <strong class="text-gray-700">{{ $inr($pool->pool_paise) }}</strong>
            <x-help-tip text="The pool rate is frozen on the day's pool row together with every figure in this header." /></span>
        <span class="text-gray-500">Fixed payout (slabs 1–2) <strong class="text-gray-700">{{ $inr($pool->fixed_payout_paise) }}</strong></span>
        <span class="text-gray-500">Slab 3–7 score <strong class="text-gray-700">{{ \App\Modules\Shared\Support\IndianNumber::format($pool->variable_total_score) }}</strong></span>
        <span class="text-gray-500">Score value (slabs 3–7) <strong class="text-gray-700">{{ $inr($pool->variable_score_value_paise) }}</strong>
            <span class="text-gray-400">(cap {{ $inr($pool->variable_score_value_cap_paise) }})</span></span>
        <span class="text-gray-500 ml-auto">Computed <strong class="text-gray-700">{{ $pool->created_at?->format('d M Y H:i') ?? '—' }}</strong>
            <x-help-tip text="When this day's pool was frozen — the figures reflect the data as it stood at this moment. On a testing recompute this is the recompute time, not the original cut-off run." /></span>
        @endif
    </summary>

    <div class="px-4 py-4 grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
        <div>
            <p class="font-semibold text-gray-800 mb-2">How the slab 3–7 score value is calculated</p>
            <ol class="space-y-1.5 font-mono text-gray-700">
                <li><span class="text-gray-500">1.</span> GSB pool = Day total BV × GSB pool %</li>
                <li><span class="text-gray-500">2.</span> Remaining pool = GSB pool − Fixed payout (slabs 1–2 at their fixed score value)</li>
                <li><span class="text-gray-500">3.</span> Score value = min( Cap, ⌊ Remaining pool ÷ Slab 3–7 total score ⌋ ) <span class="font-sans text-gray-500">(floored to the whole rupee; never above the fixed cap)</span></li>
                <li><span class="text-gray-500">4.</span> Income = Slab score × Score value <span class="font-sans text-gray-500">(slabs 1–2: score × fixed value)</span></li>
            </ol>
        </div>
        <div>
            <p class="font-semibold text-gray-800 mb-2">With this day's values</p>
            <ol class="space-y-1.5 font-mono text-gray-700">
                <li><span class="text-gray-500">1.</span> @bv($pool->company_bv_paise) × {{ $pct }} = <strong>{{ $inr($pool->pool_paise) }}</strong></li>
                <li><span class="text-gray-500">2.</span> {{ $inr($pool->pool_paise) }} − {{ $inr($pool->fixed_payout_paise) }} = <strong>{{ $inr($remainderPaise) }}</strong></li>
                @if($rawValue !== null)
                <li><span class="text-gray-500">3.</span> min( {{ $inr($pool->variable_score_value_cap_paise) }}, ⌊ {{ $inr($remainderPaise) }} ÷ {{ \App\Modules\Shared\Support\IndianNumber::format($pool->variable_total_score) }} ⌋ = ⌊ {{ $inr($rawValue) }} ⌋ ) = <strong>{{ $inr($pool->variable_score_value_paise, 0) }}</strong>{{ $wasCapped ? ' (capped)' : '' }}</li>
                <li><span class="text-gray-500">4.</span> e.g. slab 3 ({{ $slab3Score ?? '—' }} score) → {{ $slab3Score ?? '—' }} × {{ $inr($pool->variable_score_value_paise, 0) }} = <strong>{{ isset($slab3Score) ? $inr($slab3Score * $pool->variable_score_value_paise, 0) : '—' }}</strong></li>
                @else
                <li class="font-sans text-gray-500">3–4. No slab 3–7 score on this day — the score value froze at the cap ({{ $inr($pool->variable_score_value_cap_paise, 0) }}) and the remaining pool went unspent.</li>
                @endif
            </ol>
        </div>
    </div>
</details>
