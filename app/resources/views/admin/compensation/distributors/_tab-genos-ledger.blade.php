<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Transaction view of this distributor's Genos BV: every paid downline order that credited their Left or Right group (snapshotted at credit time), cancelled-order reversals, closed by that day's cut-off settlement — matched BV is consumed, the weaker side resets, and the carry-forward shown is what survived into the next day. Settlement figures come from the stored cut-off result, never recomputed.
</div>

@php
    $debtParts = [];
    if (($openDebts['L'] ?? 0) > 0) { $debtParts[] = 'Left '.\App\Modules\Commerce\Support\Bv::format($openDebts['L']); }
    if (($openDebts['R'] ?? 0) > 0) { $debtParts[] = 'Right '.\App\Modules\Commerce\Support\Bv::format($openDebts['R']); }
@endphp
@if($debtParts !== [])
<div class="mb-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
    <span class="font-semibold">Open cancelled-order adjustment:</span>
    {{ implode(' · ', $debtParts) }} — deducted from the next propagated purchases on that side before new Genos BV is credited (KP Q8: no clawback, negative-carry on the same leg).
</div>
@endif

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-4">
    <input type="hidden" name="tab" value="genos-ledger">
    <input type="date" name="from" value="{{ $from ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" placeholder="From">
    <input type="date" name="to" value="{{ $to ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" placeholder="To">
    <select name="type" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
        <option value="">All entries</option>
        <option value="credits" {{ ($entryType ?? null) === 'credits' ? 'selected' : '' }}>Credits only</option>
        <option value="reversals" {{ ($entryType ?? null) === 'reversals' ? 'selected' : '' }}>Reversals only</option>
    </select>
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-700 text-white text-sm font-medium">Apply</button>
    @if($from || $to || ($entryType ?? null))
    <a href="{{ route('admin.compensation.distributors.show', [$distributor, 'tab' => 'genos-ledger']) }}"
       class="text-sm text-gray-600 hover:text-gray-700">Clear</a>
    @endif
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($days->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-600 text-center">No Genos BV activity yet.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-600">Entry</th>
                <th class="px-3 py-2 text-left text-gray-600">Order</th>
                <th class="px-3 py-2 text-left text-gray-600">Buyer</th>
                <th class="px-3 py-2 text-right text-gray-600">Left BV</th>
                <th class="px-3 py-2 text-right text-gray-600">Right BV</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($days as $day)
            <tr class="bg-gray-50/70">
                <td colspan="5" class="px-3 py-1.5 font-semibold text-gray-600">
                    {{ \Illuminate\Support\Carbon::parse($day->date)->format('d M Y') }}
                </td>
            </tr>
            @php
                $showCredits  = ($entryType ?? null) !== 'reversals';
                $showReversals = ($entryType ?? null) !== 'credits';
                $visibleCount = ($showCredits ? count($day->credits) : 0) + ($showReversals ? count($day->reversals) : 0);
            @endphp
            @if($visibleCount === 0)
            <tr>
                <td colspan="5" class="px-3 py-2 text-gray-600 italic">No {{ ($entryType ?? null) === 'credits' ? 'credit' : (($entryType ?? null) === 'reversals' ? 'reversal' : 'Genos BV') }} entries this day.</td>
            </tr>
            @endif
            @if($showCredits)
            @foreach($day->credits as $credit)
            <tr>
                <td class="px-3 py-2 text-gray-600">
                    Order BV credit
                    @if($credit->debt_consumed_paise > 0)
                    <span class="block text-[10px] text-gray-600">after @bv($credit->debt_consumed_paise) consumed by a cancelled-order adjustment</span>
                    @endif
                </td>
                <td class="px-3 py-2">
                    <a href="{{ route('admin.commerce.orders.show', $credit->order_id) }}" class="text-brand-700 hover:underline font-mono">#{{ $credit->order_id }}</a>
                </td>
                <td class="px-3 py-2">
                    <span class="font-mono">{{ $credit->buyer_adn }}</span>
                    @if($credit->buyer_name)
                    <span class="text-gray-600">— {{ $credit->buyer_name }}</span>
                    @endif
                </td>
                <td class="px-3 py-2 text-right font-medium {{ $credit->side === 'L' ? 'text-green-700' : 'text-gray-300' }}">
                    {{ $credit->side === 'L' ? '+'.\App\Modules\Shared\Support\IndianNumber::format($credit->bv_paise / 100, 0) : '—' }}
                </td>
                <td class="px-3 py-2 text-right font-medium {{ $credit->side === 'R' ? 'text-green-700' : 'text-gray-300' }}">
                    {{ $credit->side === 'R' ? '+'.\App\Modules\Shared\Support\IndianNumber::format($credit->bv_paise / 100, 0) : '—' }}
                </td>
            </tr>
            @endforeach
            @endif
            @if($showReversals)
            @foreach($day->reversals as $reversal)
            <tr class="bg-red-50/40">
                <td class="px-3 py-2 text-red-700">
                    Order BV reversal
                    @if($reversal->debt_paise > 0)
                    <span class="block text-[10px] text-red-500">@bv($reversal->debt_paise) carried as {{ $reversal->side === 'L' ? 'Left' : 'Right' }}-side adjustment against future BV</span>
                    @endif
                </td>
                <td class="px-3 py-2">
                    <a href="{{ route('admin.commerce.orders.show', $reversal->order_id) }}" class="text-brand-700 hover:underline font-mono">#{{ $reversal->order_id }}</a>
                </td>
                <td class="px-3 py-2">
                    <span class="font-mono">{{ $reversal->buyer_adn }}</span>
                    @if($reversal->buyer_name)
                    <span class="text-gray-600">— {{ $reversal->buyer_name }}</span>
                    @endif
                </td>
                <td class="px-3 py-2 text-right font-medium {{ $reversal->side === 'L' ? 'text-red-700' : 'text-gray-300' }}">
                    {{ $reversal->side === 'L' ? '−'.\App\Modules\Shared\Support\IndianNumber::format($reversal->absorbed_paise / 100, 0) : '—' }}
                </td>
                <td class="px-3 py-2 text-right font-medium {{ $reversal->side === 'R' ? 'text-red-700' : 'text-gray-300' }}">
                    {{ $reversal->side === 'R' ? '−'.\App\Modules\Shared\Support\IndianNumber::format($reversal->absorbed_paise / 100, 0) : '—' }}
                </td>
            </tr>
            @endforeach
            @endif
            @if($day->cutoff)
            @php
                $c = $day->cutoff;
                $b = ['credited' => 'bg-green-100 text-green-700', 'reversed' => 'bg-red-100 text-red-700', 'failed' => 'bg-red-100 text-red-700', 'no_match' => 'bg-gray-100 text-gray-600', 'frozen' => 'bg-blue-100 text-blue-700', 'below_600bv' => 'bg-amber-100 text-amber-700'];
            @endphp
            <tr class="bg-purple-50/60">
                <td colspan="3" class="px-3 py-2 text-purple-800">
                    <span class="font-semibold">Cut-off settlement</span>
                    <span class="inline-flex ml-2 px-2 py-0.5 rounded text-[10px] font-medium {{ $b[$c->status] ?? 'bg-gray-100 text-gray-600' }}">{{ str_replace('_', ' ', $c->status) }}</span>
                    @if($c->slab)
                    · slab {{ $c->slab }} matched, gross GSB ₹{{ \App\Modules\Shared\Support\IndianNumber::format($c->gross_gsb_paise / 100, 2) }}
                    @endif
                </td>
                <td colspan="2" class="px-3 py-2 text-right text-purple-800">
                    carried forward:
                    <span class="font-medium">power {{ $c->power_side_after ? '('.$c->power_side_after.') ' : '' }}@bv($c->power_cf_after_paise)</span>
                    · <span class="font-medium">slab-1 weaker @bv($c->slab1_weaker_cf_after_paise)</span>
                </td>
            </tr>
            @else
            <tr class="bg-purple-50/40">
                <td colspan="5" class="px-3 py-2 text-purple-400 italic">Cut-off not run yet for this day.</td>
            </tr>
            @endif
            @endforeach
        </tbody>
    </table>
    <div class="px-4 py-3 border-t border-gray-100">{{ $days->links() }}</div>
    @endif
</div>
