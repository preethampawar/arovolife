@extends('admin.layouts.admin')
@section('title', 'GBB Input & Output Per Month')
@section('heading', 'GBB Input & Output Per Month Calculation')

@section('content')

@developer
<div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Per-month Growth Booster Bonus pool economics. Each month shows the company's total BV, the
    <strong>GBB pool</strong> (the configured pool rate of that month's BV), every distributor who earned AGP,
    the <strong>point value</strong> the month froze and each distributor's income.
    <span class="font-medium">AGP point value = (GBB pool) ÷ (total AGP)</span>, floored to whole rupees — so
    the month's payout equals the pool apart from that remainder. Held rows sit inside the frozen denominator
    and release at the frozen point value; suspended rows earned AGP that was excluded and is never paid.
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
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-500 text-white text-sm font-medium">Apply</button>
    @if($month || $from || $to)
    <a href="{{ route('admin.compensation.gbb-input-output.index') }}"
       class="text-sm text-gray-500 hover:text-gray-700">Clear</a>
    @endif
    <a href="{{ route('admin.compensation.gbb-input-output.export', array_filter(['month' => $month, 'from' => $from, 'to' => $to])) }}"
       class="ml-auto px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs text-gray-700 hover:bg-gray-50">
        ↓ Download CSV
    </a>
</form>

@if($pools->isEmpty())
<div class="bg-white rounded-xl border border-gray-200 shadow-sm">
    <p class="px-6 py-8 text-sm text-gray-400 text-center">
        No pooled months yet — rows appear once the GBB monthly run freezes a month's pool.
    </p>
</div>
@else
<div class="space-y-6">
    @foreach($pools->items() as $pool)
    @php
        $rows = collect($earners[$pool->month_start] ?? []);
        $creditedIncome = (int) $rows->where('status', \App\Modules\Compensation\Models\GbbMonthlyResult::STATUS_CREDITED)->sum('income_paise');
        $heldIncome = (int) $rows->where('status', \App\Modules\Compensation\Models\GbbMonthlyResult::STATUS_REPURCHASE_HELD)->sum('income_paise');
        $totalIncome = $creditedIncome + $heldIncome;
    @endphp
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex flex-wrap items-center gap-x-6 gap-y-1 text-xs">
            <span class="font-semibold text-gray-800">{{ \Illuminate\Support\Carbon::parse($pool->month_start)->format('F Y') }}</span>
            <span class="text-gray-500">Month total BV <strong class="text-gray-700">@bv($pool->company_bv_paise)</strong></span>
            <span class="text-gray-500">GBB pool ({{ \App\Modules\Shared\Support\IndianNumber::percentFromBp($pool->pool_rate_bp) }})
                <strong class="text-gray-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($pool->pool_paise / 100, 2) }}</strong></span>
            <span class="text-gray-500">Total AGP <strong class="text-gray-700">{{ \App\Modules\Shared\Support\IndianNumber::format($pool->total_agp) }}</strong></span>
            <span class="text-gray-500">Point value
                <strong class="text-gray-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($pool->point_value_paise / 100, 2) }}</strong></span>
            <span class="text-gray-500 ml-auto">Computed
                <strong class="text-gray-700">{{ $pool->created_at?->format('d M Y H:i') ?? '—' }}</strong>
                <x-help-tip text="When this month's pool was frozen — the figures reflect the data as it stood at this moment. On a testing recompute this is the recompute time, not the month's end." /></span>
        </div>
        @include('admin.compensation._formulas.gbb-month', ['pool' => $pool, 'embedded' => true, 'open' => count($pools->items()) === 1])

        @if($pool->total_agp === 0 && $pool->pool_paise > 0)
        <div class="px-4 py-2 bg-amber-50 border-b border-amber-100 text-[11px] text-amber-800">
            No payable AGP was earned this month, so the pool went unspent and the month's point value is
            frozen at ₹0.
        </div>
        @elseif($heldIncome > 0)
        <div class="px-4 py-2 bg-amber-50 border-b border-amber-100 text-[11px] text-amber-800">
            ₹{{ \App\Modules\Shared\Support\IndianNumber::format($heldIncome / 100, 2) }} of the frozen
            payout is held pending repurchase — releases credit at the frozen point value, so the month's
            economics never move.
        </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left text-gray-500 font-medium">S.no</th>
                        <th class="px-3 py-2 text-left text-gray-500 font-medium">Distributor <x-help-tip text="Each distributor who earned AGP this month, with the AGP they earned. Held rows sit inside the frozen denominator; suspended rows earned AGP that was excluded from it and is never paid." /></th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">AGP</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Point value <x-help-tip text="The GBB pool divided by the month's total AGP, floored to whole rupees. One value applies to every earner in the month." /></th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Income</th>
                        <th class="px-3 py-2 text-center text-gray-500 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($rows as $i => $row)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2 text-gray-500">{{ $i + 1 }}</td>
                        <td class="px-3 py-2">
                            <a href="{{ route('admin.compensation.distributors.show', $row->distributor_id) }}"
                               class="text-brand-600 hover:underline font-medium">{{ $row->full_name ?: 'Distributor' }}</a>
                            <span class="text-gray-400">({{ $row->adn }})</span>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <span class="inline-flex px-1.5 py-0.5 rounded bg-indigo-100 text-indigo-700 font-semibold">{{ \App\Modules\Shared\Support\IndianNumber::format((int) $row->agp_earned) }}</span>
                        </td>
                        <td class="px-3 py-2 text-right text-gray-700">
                            {{ $row->point_value_paise !== null ? '₹'.\App\Modules\Shared\Support\IndianNumber::format(((int) $row->point_value_paise) / 100, 2) : '—' }}
                        </td>
                        <td class="px-3 py-2 text-right font-semibold text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->income_paise / 100, 2) }}</td>
                        <td class="px-3 py-2 text-center">
                            @if($row->status === \App\Modules\Compensation\Models\GbbMonthlyResult::STATUS_CREDITED)
                            <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium bg-green-100 text-green-700">Credited</span>
                            @elseif($row->status === \App\Modules\Compensation\Models\GbbMonthlyResult::STATUS_REPURCHASE_HELD)
                            <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium bg-amber-100 text-amber-700">Held</span>
                            @else
                            <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium bg-gray-100 text-gray-600">Suspended — AGP excluded</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="px-3 py-4 text-center text-gray-400">No Growth Booster Bonus earners this month.</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="bg-gray-50 border-t-2 border-gray-200 text-gray-800">
                    <tr>
                        <td class="px-3 py-1.5 text-right text-xs" colspan="2">Total AGP</td>
                        <td class="px-3 py-1.5 text-right font-semibold">{{ \App\Modules\Shared\Support\IndianNumber::format($pool->total_agp) }}</td>
                        <td class="px-3 py-1.5 text-right text-[11px] text-gray-500" colspan="3">
                            {{ $pool->total_agp > 0 ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($pool->pool_paise / 100, 0).' ÷ '.\App\Modules\Shared\Support\IndianNumber::format($pool->total_agp) : '—' }}
                        </td>
                    </tr>
                    <tr class="font-semibold">
                        <td class="px-3 py-2 text-right text-xs" colspan="3">Total income</td>
                        <td class="px-3 py-2 text-right {{ $pool->leftover_paise < 0 ? 'text-red-600' : 'text-gray-500' }} text-[11px]">
                            leftover {{ $pool->leftover_paise < 0 ? '−' : '' }}₹{{ \App\Modules\Shared\Support\IndianNumber::format(abs($pool->leftover_paise) / 100, 2) }}
                        </td>
                        <td class="px-3 py-2 text-right text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($totalIncome / 100, 2) }}</td>
                        <td></td>
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
