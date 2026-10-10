{{-- Lifetime Awards & Rewards month header + how a tranche is valued (aggregated milestones per rank and tranche; amounts frozen on the milestones).
     Expects: array $aw (BonusCalculationSnapshots::awRwMonths item), string $monthStart --}}
@php
    $inr = \App\Modules\Shared\Support\IndianNumber::rupees(...);
    $num = \App\Modules\Shared\Support\IndianNumber::format(...);
@endphp
{{-- Collapsed by default; a page showing a single period opens it. The header row is the summary. --}}
<details class="group bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6"{{ ($open ?? false) ? ' open' : '' }}>
    <summary class="px-4 py-3 bg-gray-50 group-open:border-b border-gray-200 flex flex-wrap items-center gap-x-6 gap-y-1 text-xs list-none [&::-webkit-details-marker]:hidden cursor-pointer select-none" title="Show or hide the calculation">
        <x-lucide-chevron-right class="h-3.5 w-3.5 text-gray-400 shrink-0 transition-transform group-open:rotate-90" aria-hidden="true" />
        <span class="font-semibold text-gray-800">{{ \Illuminate\Support\Carbon::parse($monthStart)->format('F Y') }}</span>
        <span class="text-gray-500">Milestones triggered <strong class="text-gray-700">{{ $num($aw['milestones']) }}</strong>
            <x-help-tip text="One milestone per distributor per rank per tranche, created in the month the rank's qualification count first reaches the tranche." /></span>
        <span class="text-gray-500">Delivered <strong class="text-gray-700">{{ $num($aw['delivered']) }}</strong></span>
        <span class="text-gray-500">Award worth <strong class="text-gray-700">{{ $inr($aw['amount_paise'], 0) }}</strong>
            <x-help-tip text="Σ of the tranche amounts recorded on this month's milestones when they were earned." /></span>
        <span class="text-gray-500 ml-auto">Computed <strong class="text-gray-700">{{ $aw['computed_at']?->format('d M Y H:i') ?? '—' }}</strong>
            <x-help-tip text="When this month's milestones were written by the Rank Bonus run." /></span>
    </summary>

    <div class="px-4 py-4 grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
        <div>
            <p class="font-semibold text-gray-800 mb-2">How an award is valued</p>
            <ol class="space-y-1.5 font-mono text-gray-700">
                <li><span class="text-gray-500">1.</span> Tranche A = the rank's 1st qualification, B = the 2nd, C = the 3rd <span class="font-sans text-gray-500">(Ranks 1–2 one tranche, 3–5 two, 6–9 three)</span></li>
                <li><span class="text-gray-500">2.</span> Award worth = the tranche amount; the rank's tranches add up to its budget <span class="font-sans text-gray-500">(goods from the rank's reward catalogue)</span></li>
                <li><span class="text-gray-500">3.</span> Merchandise only — never paid in cash <span class="font-sans text-gray-500">(no admin charge, no TDS, no wallet credit)</span></li>
                <li><span class="text-gray-500">4.</span> Funded from the awards fund <span class="font-sans text-gray-500">(a share of each month's BV, client 2026-10-09 — see Awards fund above; the tranche amounts stay fixed and an award is never delayed by the fund)</span></li>
            </ol>
        </div>
        <div>
            <p class="font-semibold text-gray-800 mb-2">With this month's values</p>
            <div class="overflow-x-auto">
            <table class="w-full font-mono text-gray-700">
                <thead>
                    <tr class="text-gray-500 font-sans">
                        <th class="text-left font-medium pb-1 w-12">S.No.</th>
                        <th class="text-left font-medium pb-1">Rank</th>
                        <th class="text-left font-medium pb-1">Tranche</th>
                        <th class="text-right font-medium pb-1">Milestones</th>
                        <th class="text-right font-medium pb-1">Delivered</th>
                        <th class="text-right font-medium pb-1">Amount</th>
                        <th class="text-right font-medium pb-1">Rank budget</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($aw['ranks'] as $r)
                    <tr>
                        <td class="py-0.5 text-gray-500 tabular-nums">{{ $loop->iteration }}</td>
                        <td class="py-0.5">{{ $r['name'] }}</td>
                        <td class="py-0.5">{{ chr(64 + $r['tranche']) }}</td>
                        <td class="py-0.5 text-right">{{ $num($r['milestones']) }}</td>
                        <td class="py-0.5 text-right">{{ $num($r['delivered']) }}</td>
                        <td class="py-0.5 text-right"><strong>{{ $inr($r['amount_paise'], 0) }}</strong></td>
                        <td class="py-0.5 text-right">{{ $inr($r['budget_paise'], 0) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>
    </div>
</details>
