<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Monthly Fortune Bonus. Positions are filled first-come-first-served; each matrix level pays depth points × that level's point value, subject to the level's per-distributor cap and the plan's minimum commission.
</div>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if(empty($rows) || $rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-600 text-center">No Fortune Bonus history yet.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-600">Month</th>
                <th class="px-3 py-2 text-center text-gray-600">Position <x-help-tip text="The distributor's FCFS position in the month's Fortune matrix." /></th>
                <th class="px-3 py-2 text-center text-gray-600">Level</th>
                <th class="px-3 py-2 text-right text-gray-600">Points</th>
                <th class="px-3 py-2 text-right text-gray-600">Point value</th>
                <th class="px-3 py-2 text-right text-gray-600">Cap</th>
                <th class="px-3 py-2 text-right text-gray-600">Gross</th>
                <th class="px-3 py-2 text-right text-gray-600">Admin</th>
                <th class="px-3 py-2 text-right text-gray-600">TDS</th>
                <th class="px-3 py-2 text-right text-gray-600">Net</th>
                <th class="px-3 py-2 text-center text-gray-600">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($rows as $row)
            @php
                $statusClass = match ($row->status) {
                    'credited' => 'bg-green-100 text-green-700',
                    'reversed' => 'bg-red-100 text-red-700',
                    'skipped' => 'bg-gray-100 text-gray-600',
                    default => 'bg-gray-100 text-gray-600',
                };
            @endphp
            <tr>
                <td class="px-3 py-2 font-medium">{{ \Illuminate\Support\Carbon::parse($row->month_start)->format('M Y') }}</td>
                <td class="px-3 py-2 text-center">#{{ $row->position }}</td>
                <td class="px-3 py-2 text-center">
                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 font-bold text-[11px]">{{ $row->matrix_level }}</span>
                </td>
                <td class="px-3 py-2 text-right font-semibold">{{ $row->points ?? '—' }}</td>
                <td class="px-3 py-2 text-right text-gray-600">
                    @if($row->point_value_paise !== null)₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->point_value_paise / 100, 2) }}@else<span class="text-gray-600">—</span>@endif
                </td>
                <td class="px-3 py-2 text-right text-gray-600">
                    @if($row->cap_paise !== null)₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->cap_paise / 100, 0) }}@else<span class="text-gray-600">—</span>@endif
                </td>
                <td class="px-3 py-2 text-right">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->gross_paise / 100, 2) }}</td>
                <td class="px-3 py-2 text-right text-gray-600">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->admin_charge_paise / 100, 2) }}</td>
                <td class="px-3 py-2 text-right text-gray-600">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->tds_paise / 100, 2) }}</td>
                <td class="px-3 py-2 text-right font-semibold text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->net_paise / 100, 2) }}</td>
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
