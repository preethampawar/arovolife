@extends('layouts.app')
@section('title', 'My Income — Genos Ledger')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My Income</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        A transaction view of your Genos BV. Each purchase made in your Left or Right group adds BV here as it happens; at the daily 23:59 cut-off the day is settled — the matched BV is used for your Genos Sales Bonus, the weaker side resets, and the balance shown carries over as the next day's opening BV. Business that occurs before matching is carry over; on a day a slab matches, the remaining BV is your carry forward.
    </div>

    @if(! $genosBvEligible)
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-500 font-medium">Genos BV is not being counted yet.</p>
            <p class="text-sm text-gray-400 mt-1">Group BV is counted only after your lifetime personal BV reaches {{ $gsbMinBvPaise !== null ? \Illuminate\Support\Number::format($gsbMinBvPaise / 100, 0) : '600' }} BV of personal purchases. Your Genos ledger will appear here after that.</p>
        </div>
    @else

    @php
        $debtParts = [];
        if (($openDebts['L'] ?? 0) > 0) { $debtParts[] = 'Left group '.\Illuminate\Support\Number::format($openDebts['L'] / 100, 0).' BV'; }
        if (($openDebts['R'] ?? 0) > 0) { $debtParts[] = 'Right group '.\Illuminate\Support\Number::format($openDebts['R'] / 100, 0).' BV'; }
    @endphp
    @if($debtParts !== [])
    {{-- Outstanding cancelled-order adjustment --}}
    <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-800 mb-6">
        <span class="font-semibold">Adjustment pending from cancelled orders:</span>
        {{ implode(' · ', $debtParts) }} — this BV will be deducted from the next purchases made in that group before new group BV is added.
    </div>
    @endif

    {{-- Filter form --}}
    <form method="GET" class="flex flex-wrap gap-3 mb-6 items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" name="from" value="{{ request('from') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" name="to" value="{{ request('to') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <button type="submit" class="px-4 py-1.5 bg-brand-500 text-white text-sm rounded-lg hover:bg-brand-600 transition-colors">Filter</button>
        @if(request('from') || request('to'))
            <a href="{{ route('income.genos-ledger') }}" class="px-4 py-1.5 text-sm text-gray-600 hover:text-gray-800">Clear</a>
        @endif
    </form>

    @if($days->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-500 font-medium">No Genos BV activity yet.</p>
            <p class="text-sm text-gray-400 mt-1">Entries will appear here as members of your Genos make purchases.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[640px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Entry</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center gap-1">Member <x-help-tip text="The ADN of the Genos member whose purchase generated this BV." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Left BV <x-help-tip text="BV added to your Left group by this purchase." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Right BV <x-help-tip text="BV added to your Right group by this purchase." /></span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($days as $day)
                    <tr class="bg-gray-50/70">
                        <td colspan="4" class="px-4 py-2 font-semibold text-gray-600">
                            {{ \Illuminate\Support\Carbon::parse($day->date)->format('d M Y') }}
                        </td>
                    </tr>
                    @if(count($day->credits) === 0 && count($day->reversals) === 0)
                    <tr>
                        <td colspan="4" class="px-4 py-2.5 text-gray-400 italic">No group BV added this day.</td>
                    </tr>
                    @endif
                    @foreach($day->credits as $credit)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2.5 text-gray-500">
                            Purchase BV
                            @if($credit->debt_consumed_paise > 0)
                            <span class="block text-xs text-gray-400">after {{ \Illuminate\Support\Number::format($credit->debt_consumed_paise / 100, 0) }} BV adjusted for a cancelled order</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 font-mono text-gray-700">{{ $credit->buyer_adn }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-medium {{ $credit->side === 'L' ? 'text-green-700' : 'text-gray-300' }}">
                            {{ $credit->side === 'L' ? '+'.\Illuminate\Support\Number::format($credit->bv_paise / 100, 0) : '—' }}
                        </td>
                        <td class="px-4 py-2.5 text-right font-mono font-medium {{ $credit->side === 'R' ? 'text-green-700' : 'text-gray-300' }}">
                            {{ $credit->side === 'R' ? '+'.\Illuminate\Support\Number::format($credit->bv_paise / 100, 0) : '—' }}
                        </td>
                    </tr>
                    @endforeach
                    @foreach($day->reversals as $reversal)
                    <tr class="bg-red-50/40">
                        <td class="px-4 py-2.5 text-red-700">
                            Order cancelled — BV reversed
                            @if($reversal->debt_paise > 0)
                            <span class="block text-xs text-red-500">{{ \Illuminate\Support\Number::format($reversal->debt_paise / 100, 0) }} BV will be deducted from future {{ $reversal->side === 'L' ? 'Left' : 'Right' }} group BV before new BV is added</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 font-mono text-gray-700">{{ $reversal->buyer_adn }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-medium {{ $reversal->side === 'L' ? 'text-red-700' : 'text-gray-300' }}">
                            {{ $reversal->side === 'L' ? '−'.\Illuminate\Support\Number::format($reversal->absorbed_paise / 100, 0) : '—' }}
                        </td>
                        <td class="px-4 py-2.5 text-right font-mono font-medium {{ $reversal->side === 'R' ? 'text-red-700' : 'text-gray-300' }}">
                            {{ $reversal->side === 'R' ? '−'.\Illuminate\Support\Number::format($reversal->absorbed_paise / 100, 0) : '—' }}
                        </td>
                    </tr>
                    @endforeach
                    @if($day->cutoff)
                    @php
                        $c = $day->cutoff;
                        $statusLabels = [
                            'credited' => ['GSB earned', 'bg-green-100 text-green-700'],
                            'no_match' => ['No match', 'bg-gray-100 text-gray-500'],
                            'below_600bv' => ['Below 600 BV', 'bg-amber-100 text-amber-700'],
                            'reversed' => ['Reversed', 'bg-red-100 text-red-700'],
                        ];
                        [$statusLabel, $statusClass] = $statusLabels[$c->status] ?? ['Pending', 'bg-gray-100 text-gray-500'];
                    @endphp
                    <tr class="bg-indigo-50/60">
                        <td colspan="2" class="px-4 py-2.5 text-indigo-900">
                            <span class="font-semibold">Daily cut-off</span>
                            <span class="inline-flex ml-2 px-2 py-0.5 rounded-full text-xs font-medium {{ $statusClass }}">{{ $statusLabel }}</span>
                            @if($c->slab)
                            <span class="ml-1 text-xs">· Slab {{ $c->slab }} matched</span>
                            @endif
                        </td>
                        <td colspan="2" class="px-4 py-2.5 text-right text-indigo-900 text-xs">
                            {{ $c->slab ? 'carried forward' : 'carried over' }}: <span class="font-mono font-medium">power {{ $c->power_side_after ? '('.$c->power_side_after.') ' : '' }}{{ \Illuminate\Support\Number::format($c->power_cf_after_paise / 100, 0) }}</span>
                            · <span class="font-mono font-medium">slab-1 weaker {{ \Illuminate\Support\Number::format($c->slab1_weaker_cf_after_paise / 100, 0) }}</span>
                        </td>
                    </tr>
                    @else
                    <tr class="bg-indigo-50/40">
                        <td colspan="4" class="px-4 py-2.5 text-indigo-400 italic">Cut-off pending for this day.</td>
                    </tr>
                    @endif
                    @endforeach
                </tbody>
            </table>
        </div>
        @if(method_exists($days, 'links'))
            <div class="mt-4">{{ $days->links() }}</div>
        @endif
    @endif

    @endif
</div>
@endsection
