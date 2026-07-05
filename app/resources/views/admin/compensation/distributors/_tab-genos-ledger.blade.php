<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Transaction view of this distributor's Genos BV: every paid downline order that credited their Left or Right group (derived from the propagation log), closed by that day's cut-off settlement — matched BV is consumed, the weaker side resets, and the carry-forward shown is what survived into the next day. Settlement figures come from the stored cut-off result, never recomputed.
</div>

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-4">
    <input type="hidden" name="tab" value="genos-ledger">
    <input type="date" name="from" value="{{ $from ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" placeholder="From">
    <input type="date" name="to" value="{{ $to ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" placeholder="To">
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-500 text-white text-sm font-medium">Apply</button>
    @if($from || $to)
    <a href="{{ route('admin.compensation.distributors.show', [$distributor, 'tab' => 'genos-ledger']) }}"
       class="text-sm text-gray-500 hover:text-gray-700">Clear</a>
    @endif
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($days->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-400 text-center">No Genos BV activity yet.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-500">Entry</th>
                <th class="px-3 py-2 text-left text-gray-500">Order</th>
                <th class="px-3 py-2 text-left text-gray-500">Buyer</th>
                <th class="px-3 py-2 text-right text-gray-500">Left BV</th>
                <th class="px-3 py-2 text-right text-gray-500">Right BV</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($days as $day)
            <tr class="bg-gray-50/70">
                <td colspan="5" class="px-3 py-1.5 font-semibold text-gray-600">
                    {{ \Illuminate\Support\Carbon::parse($day->date)->format('d M Y') }}
                </td>
            </tr>
            @forelse($day->credits as $credit)
            <tr>
                <td class="px-3 py-2 text-gray-500">Order BV credit</td>
                <td class="px-3 py-2">
                    <a href="{{ route('admin.commerce.orders.show', $credit->order_id) }}" class="text-brand-600 hover:underline font-mono">#{{ $credit->order_id }}</a>
                </td>
                <td class="px-3 py-2">
                    <span class="font-mono">{{ $credit->buyer_adn }}</span>
                    @if($credit->buyer_name)
                    <span class="text-gray-400">— {{ $credit->buyer_name }}</span>
                    @endif
                </td>
                <td class="px-3 py-2 text-right font-medium {{ $credit->side === 'L' ? 'text-green-700' : 'text-gray-300' }}">
                    {{ $credit->side === 'L' ? '+'.number_format($credit->bv_paise / 100, 0) : '—' }}
                </td>
                <td class="px-3 py-2 text-right font-medium {{ $credit->side === 'R' ? 'text-green-700' : 'text-gray-300' }}">
                    {{ $credit->side === 'R' ? '+'.number_format($credit->bv_paise / 100, 0) : '—' }}
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5" class="px-3 py-2 text-gray-400 italic">No group BV credited this day.</td>
            </tr>
            @endforelse
            @if($day->cutoff)
            @php
                $c = $day->cutoff;
                $b = ['credited' => 'bg-green-100 text-green-700', 'reversed' => 'bg-red-100 text-red-700', 'failed' => 'bg-red-100 text-red-700', 'no_match' => 'bg-gray-100 text-gray-500', 'frozen' => 'bg-blue-100 text-blue-700', 'below_600bv' => 'bg-amber-100 text-amber-700'];
            @endphp
            <tr class="bg-purple-50/60">
                <td colspan="3" class="px-3 py-2 text-purple-800">
                    <span class="font-semibold">Cut-off settlement</span>
                    <span class="inline-flex ml-2 px-2 py-0.5 rounded text-[10px] font-medium {{ $b[$c->status] ?? 'bg-gray-100 text-gray-500' }}">{{ str_replace('_', ' ', $c->status) }}</span>
                    @if($c->slab)
                    · slab {{ $c->slab }} matched, gross GSB ₹{{ number_format($c->gross_gsb_paise / 100, 2) }}
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
