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
    and release at the frozen point value; suspended rows, and rows blocked by an unspent repurchase wallet,
    earned AGP that was excluded and is never paid.
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
        // Legacy rows only — priced into their month's pool, so they still have
        // to reconcile here, but nothing releases them any more.
        $heldIncome = (int) $rows->where('status', \App\Modules\Compensation\Models\GbbMonthlyResult::STATUS_REPURCHASE_HELD)->sum('income_paise');
        $walletBlockedCount = $rows->where('status', \App\Modules\Compensation\Models\GbbMonthlyResult::STATUS_REPURCHASE_WALLET_BLOCKED)->count();
        $totalIncome = $creditedIncome + $heldIncome;
        $totalDeduction = (int) $rows->sum('deduction_paise');
        $totalCredited = (int) $rows->sum('credited_paise');
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

        @if(!empty($lateEarners[$pool->month_start]))
        <div class="px-4 py-2 bg-amber-50 border-b border-amber-100 text-[11px] text-amber-800">
            <span class="font-semibold">Earned AGP after the pool was frozen:</span>
            @foreach($lateEarners[$pool->month_start] as $distributorId => $adn)
                <span class="font-mono">{{ $adn !== '' ? $adn : '#'.$distributorId }}</span>{{ $loop->last ? '' : ',' }}
            @endforeach
            <span class="block mt-1">
                This month's pool and its roster were frozen before these distributors earned their AGP. They were
                not paid from this month's pool: a pool that has already been divided is never re-divided, or the
                month would pay out more than it collected. Nothing on this page pays them, and there is no admin
                action here that will. Whether they are owed anything for this month is a plan decision, not an
                operational one — record it and escalate it.
            </span>
        </div>
        @endif

        @if($pool->total_agp === 0 && $pool->pool_paise > 0)
        <div class="px-4 py-2 bg-amber-50 border-b border-amber-100 text-[11px] text-amber-800">
            No payable AGP was earned this month, so the pool went unspent and the month's point value is
            frozen at ₹0.
        </div>
        @endif

        @if($walletBlockedCount > 0)
        <div class="px-4 py-2 bg-amber-50 border-b border-amber-100 text-[11px] text-amber-800">
            {{ \App\Modules\Shared\Support\IndianNumber::format($walletBlockedCount) }}
            {{ $walletBlockedCount === 1 ? 'distributor' : 'distributors' }} forfeited this month: the repurchase
            wallet was not cleared at the last instant of it. Their AGP was excluded from the denominator, so the
            month's point value was priced without them and nothing here will ever pay them.
        </div>
        @endif

        @if($heldIncome > 0)
        <div class="px-4 py-2 bg-amber-50 border-b border-amber-100 text-[11px] text-amber-800">
            ₹{{ \App\Modules\Shared\Support\IndianNumber::format($heldIncome / 100, 2) }} of the frozen
            payout sits on legacy <code>repurchase_held</code> rows. Their AGP was inside the denominator, so the
            pool was priced with them, but the release path was removed with the 2026-09-07 repurchase rules and
            nothing credits them now. Escalate rather than paying them from this page.
        </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left text-gray-500 font-medium">S.no</th>
                        <th class="px-3 py-2 text-left text-gray-500 font-medium">Distributor <x-help-tip text="Each distributor who earned AGP this month, with the AGP they earned. Blocked = the repurchase wallet was not cleared at the last instant of the month: that AGP was excluded from the denominator and is never paid. Held and suspended are legacy states no run writes any more." /></th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">AGP</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Point value <x-help-tip text="The GBB pool divided by the month's total AGP, floored to whole rupees. One value applies to every earner in the month." /></th>
                        <x-bonus-credit-head gross-label="Income" th-class="px-3 py-2 text-right text-gray-500 font-medium" />
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
                        <x-bonus-credit-cells :gross="(int) $row->income_paise" :deduction="(int) $row->deduction_paise" :credited="(int) $row->credited_paise" :is-credited="$row->status === \App\Modules\Compensation\Models\GbbMonthlyResult::STATUS_CREDITED" td-class="px-3 py-2 text-right" />
                        <td class="px-3 py-2 text-center">
                            @if($row->status === \App\Modules\Compensation\Models\GbbMonthlyResult::STATUS_CREDITED)
                            <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium bg-green-100 text-green-700">Credited</span>
                            @elseif($row->status === \App\Modules\Compensation\Models\GbbMonthlyResult::STATUS_REPURCHASE_HELD)
                            <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium bg-amber-100 text-amber-700">Held (legacy)</span>
                            @elseif($row->status === \App\Modules\Compensation\Models\GbbMonthlyResult::STATUS_REPURCHASE_WALLET_BLOCKED)
                            <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium bg-amber-100 text-amber-800">Blocked — repurchase wallet not ₹0</span>
                            @else
                            <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium bg-gray-100 text-gray-600">Suspended — AGP excluded (legacy)</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="px-3 py-4 text-center text-gray-400">No Growth Booster Bonus earners this month.</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="bg-gray-50 border-t-2 border-gray-200 text-gray-800">
                    <tr>
                        <td class="px-3 py-1.5 text-right text-xs" colspan="2">Total AGP</td>
                        <td class="px-3 py-1.5 text-right font-semibold">{{ \App\Modules\Shared\Support\IndianNumber::format($pool->total_agp) }}</td>
                        <td class="px-3 py-1.5 text-right text-[11px] text-gray-500" colspan="5">
                            {{ $pool->total_agp > 0 ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($pool->pool_paise / 100, 0).' ÷ '.\App\Modules\Shared\Support\IndianNumber::format($pool->total_agp) : '—' }}
                        </td>
                    </tr>
                    <tr class="font-semibold">
                        <td class="px-3 py-2 text-right text-xs" colspan="4">Total income / deduction / credited</td>
                        <td class="px-3 py-2 text-right text-gray-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($totalIncome / 100, 2) }}</td>
                        <td class="px-3 py-2 text-right {{ $totalDeduction > 0 ? 'text-red-600' : 'text-gray-500' }}">{{ $totalDeduction > 0 ? '-₹'.\App\Modules\Shared\Support\IndianNumber::format($totalDeduction / 100, 2) : '—' }}</td>
                        <td class="px-3 py-2 text-right text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($totalCredited / 100, 2) }}</td>
                        <td class="px-3 py-2 text-right {{ $pool->leftover_paise < 0 ? 'text-red-600' : 'text-gray-500' }} text-[11px]">
                            leftover {{ $pool->leftover_paise < 0 ? '−' : '' }}₹{{ \App\Modules\Shared\Support\IndianNumber::format(abs($pool->leftover_paise) / 100, 2) }}
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
