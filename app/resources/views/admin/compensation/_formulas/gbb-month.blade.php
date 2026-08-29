{{-- GBB month header + AGP point-value formula (frozen gbb_monthly_pools row).
     Expects: \App\Modules\Compensation\Models\GbbMonthlyPool $pool --}}
@php
    $inr = \App\Modules\Shared\Support\IndianNumber::rupees(...);
    $pct = \App\Modules\Shared\Support\IndianNumber::percentFromBp($pool->pool_rate_bp);
    $rawValue = $pool->total_agp > 0 ? intdiv($pool->pool_paise, $pool->total_agp) : null;
@endphp
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6">
    <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex flex-wrap items-center gap-x-6 gap-y-1 text-xs">
        <span class="font-semibold text-gray-800">{{ \Illuminate\Support\Carbon::parse($pool->month_start)->format('F Y') }}</span>
        <span class="text-gray-500">Month total BV <strong class="text-gray-700">@bv($pool->company_bv_paise)</strong>
            <x-help-tip text="This month's accumulated company BV as the engine froze it on the run — the base of the GBB pool." /></span>
        <span class="text-gray-500">GBB pool ({{ $pct }}) <strong class="text-gray-700">{{ $inr($pool->pool_paise) }}</strong>
            <x-help-tip text="The pool rate is frozen on the month's pool row together with every figure in this header." /></span>
        <span class="text-gray-500">Total AGP <strong class="text-gray-700">{{ \App\Modules\Shared\Support\IndianNumber::format($pool->total_agp) }}</strong></span>
        <span class="text-gray-500">Point value <strong class="text-gray-700">{{ $inr($pool->point_value_paise) }}</strong></span>
        <span class="text-gray-500 ml-auto">Computed <strong class="text-gray-700">{{ $pool->created_at?->format('d M Y H:i') ?? '—' }}</strong>
            <x-help-tip text="When this month's pool was frozen — the figures reflect the data as it stood at this moment. On a testing recompute this is the recompute time, not the month's end." /></span>
    </div>

    <div class="px-4 py-4 grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
        <div>
            <p class="font-semibold text-gray-800 mb-2">How the AGP point value is calculated</p>
            <ol class="space-y-1.5 font-mono text-gray-700">
                <li><span class="text-gray-500">1.</span> GBB pool = Month total BV × GBB pool %</li>
                <li><span class="text-gray-500">2.</span> Total AGP = Σ (each earner's AGP for the month, capped per distributor)</li>
                <li><span class="text-gray-500">3.</span> Point value = ⌊ GBB pool ÷ Total AGP ⌋ <span class="font-sans text-gray-500">(floored to the whole rupee; remainder stays unspent)</span></li>
                <li><span class="text-gray-500">4.</span> Income = Earner's AGP × Point value</li>
            </ol>
        </div>
        <div>
            <p class="font-semibold text-gray-800 mb-2">With this month's values</p>
            <ol class="space-y-1.5 font-mono text-gray-700">
                <li><span class="text-gray-500">1.</span> @bv($pool->company_bv_paise) × {{ $pct }} = <strong>{{ $inr($pool->pool_paise) }}</strong></li>
                <li><span class="text-gray-500">2.</span> Total AGP = <strong>{{ \App\Modules\Shared\Support\IndianNumber::format($pool->total_agp) }}</strong></li>
                @if($rawValue !== null)
                <li><span class="text-gray-500">3.</span> ⌊ {{ $inr($pool->pool_paise) }} ÷ {{ \App\Modules\Shared\Support\IndianNumber::format($pool->total_agp) }} ⌋ = ⌊ {{ $inr($rawValue) }} ⌋ = <strong>{{ $inr($pool->point_value_paise, 0) }}</strong></li>
                <li><span class="text-gray-500">4.</span> e.g. 10 AGP → 10 × {{ $inr($pool->point_value_paise, 0) }} = <strong>{{ $inr(10 * $pool->point_value_paise, 0) }}</strong></li>
                @else
                <li class="font-sans text-gray-500">3–4. No payable AGP this month — the pool went unspent.</li>
                @endif
            </ol>
        </div>
    </div>
</div>
