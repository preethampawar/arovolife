<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Monthly Growth Booster Bonus (GBB). AGP earned from the month's GSB slab matches divides the month's GBB pool: income = the distributor's AGP × the point value (pool ÷ the month's total AGP).
</div>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if(empty($rows) || $rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-400 text-center">No Growth Booster Bonus history yet.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-500">Month</th>
                <th class="px-3 py-2 text-right text-gray-500">AGP earned <x-help-tip text="Achievement Growth Points earned from this month's GSB slab matches." /></th>
                <th class="px-3 py-2 text-right text-gray-500">Pool AGP <x-help-tip text="Total AGP earned by everyone in the month — the divisor for the point value." /></th>
                <th class="px-3 py-2 text-right text-gray-500">Point value</th>
                <th class="px-3 py-2 text-right text-gray-500">Gross</th>
                <th class="px-3 py-2 text-right text-gray-500">Admin</th>
                <th class="px-3 py-2 text-right text-gray-500">TDS</th>
                <th class="px-3 py-2 text-right text-gray-500">Net</th>
                <th class="px-3 py-2 text-center text-gray-500">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($rows as $row)
            @php
                $statusClass = match ($row->status) {
                    'credited' => 'bg-green-100 text-green-700',
                    'reversed' => 'bg-red-100 text-red-700',
                    'held' => 'bg-amber-100 text-amber-800',
                    default => 'bg-gray-100 text-gray-500',
                };
            @endphp
            <tr>
                <td class="px-3 py-2 font-medium">{{ \Illuminate\Support\Carbon::parse($row->year_month)->format('M Y') }}</td>
                <td class="px-3 py-2 text-right font-semibold">{{ \App\Modules\Shared\Support\IndianNumber::format($row->agp_earned) }}</td>
                <td class="px-3 py-2 text-right text-gray-500">{{ \App\Modules\Shared\Support\IndianNumber::format($row->total_pool_agp) }}</td>
                <td class="px-3 py-2 text-right text-gray-500">
                    @if($row->point_value_paise !== null)₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->point_value_paise / 100, 2) }}@else<span class="text-gray-400">—</span>@endif
                </td>
                <td class="px-3 py-2 text-right">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gbb_gross_paise / 100, 2) }}</td>
                <td class="px-3 py-2 text-right text-gray-500">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->admin_charge_paise / 100, 2) }}</td>
                <td class="px-3 py-2 text-right text-gray-500">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->tds_paise / 100, 2) }}</td>
                <td class="px-3 py-2 text-right font-semibold text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gbb_net_paise / 100, 2) }}</td>
                <td class="px-3 py-2 text-center">
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $statusClass }}">{{ str_replace('_', ' ', $row->status) }}</span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>
