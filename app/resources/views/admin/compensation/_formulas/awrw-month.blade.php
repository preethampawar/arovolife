{{-- Lifetime Awards & Rewards month header + how a milestone is valued (aggregated milestones + CURRENT rank award budgets).
     Expects: array $aw (BonusCalculationSnapshots::awRwMonths item), string $monthStart --}}
@php
    $inr = \App\Modules\Shared\Support\IndianNumber::rupees(...);
    $num = \App\Modules\Shared\Support\IndianNumber::format(...);
    $totalBudget = array_sum(array_map(fn (array $r): int => $r['budget_paise'] * $r['milestones'], $aw['ranks']));
    $totalNet = array_sum(array_column($aw['ranks'], 'net_paise'));
@endphp
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6">
    <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex flex-wrap items-center gap-x-6 gap-y-1 text-xs">
        <span class="font-semibold text-gray-800">{{ \Illuminate\Support\Carbon::parse($monthStart)->format('F Y') }}</span>
        <span class="text-gray-500">Milestones triggered <strong class="text-gray-700">{{ $num($aw['milestones']) }}</strong>
            <x-help-tip text="One milestone per distributor per rank, created the first month they qualify for that rank." /></span>
        <span class="text-gray-500">Delivered <strong class="text-gray-700">{{ $num($aw['delivered']) }}</strong></span>
        <span class="text-gray-500">Award worth (current budgets) <strong class="text-gray-700">{{ $inr($totalBudget) }}</strong>
            <x-help-tip text="Σ of each rank's CURRENT Lifetime Award budget × milestones of that rank. Budgets are edited on the rank ladder, so this is a live figure, not a frozen snapshot." /></span>
        <span class="text-gray-500">Cash rewards net <strong class="text-gray-700">{{ $inr($totalNet) }}</strong></span>
        <span class="text-gray-500 ml-auto">Computed <strong class="text-gray-700">{{ $aw['computed_at']?->format('d M Y H:i') ?? '—' }}</strong>
            <x-help-tip text="When this month's milestones were written by the Rank Bonus run." /></span>
    </div>

    <div class="px-4 py-4 grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
        <div>
            <p class="font-semibold text-gray-800 mb-2">How an award or reward is valued</p>
            <ol class="space-y-1.5 font-mono text-gray-700">
                <li><span class="text-gray-500">1.</span> Milestone = first month a distributor qualifies for a rank <span class="font-sans text-gray-500">(later qualifications add to its count while pending)</span></li>
                <li><span class="text-gray-500">2.</span> Award worth = the rank's Lifetime Award budget <span class="font-sans text-gray-500">(goods from the rank's reward catalogue)</span></li>
                <li><span class="text-gray-500">3.</span> Cash reward (in lieu of goods) = Award worth − Admin charge − TDS</li>
                <li><span class="text-gray-500">4.</span> Nothing is funded from a BV pool — the budget is a fixed per-rank amount.</li>
            </ol>
        </div>
        <div>
            <p class="font-semibold text-gray-800 mb-2">With this month's values</p>
            <table class="w-full font-mono text-gray-700">
                <thead>
                    <tr class="text-gray-500 font-sans">
                        <th class="text-left font-medium pb-1">Rank</th>
                        <th class="text-right font-medium pb-1">Milestones</th>
                        <th class="text-right font-medium pb-1">Budget each</th>
                        <th class="text-right font-medium pb-1">Cash rows</th>
                        <th class="text-right font-medium pb-1">Gross − TDS = Net</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($aw['ranks'] as $r)
                    <tr>
                        <td class="py-0.5">{{ $r['name'] }}</td>
                        <td class="py-0.5 text-right">{{ $num($r['milestones']) }}</td>
                        <td class="py-0.5 text-right">{{ $inr($r['budget_paise'], 0) }}</td>
                        <td class="py-0.5 text-right">{{ $num($r['cash']) }}</td>
                        <td class="py-0.5 text-right">{{ $r['cash'] > 0 ? $inr($r['gross_paise']).' − '.$inr($r['tds_paise']).' = ' : '' }}<strong>{{ $inr($r['net_paise']) }}</strong></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
