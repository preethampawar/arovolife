@developer
<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Monthly Arete Development Centre (ADC) bonus earned by this distributor as a centre holder — a share of the centre's members' BV for the month, subject to the centre's phase and cap.
</div>
@enddeveloper
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if(empty($rows) || $rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-600 text-center">No ADC bonus history yet.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-600">Month</th>
                <th class="px-3 py-2 text-left text-gray-600">Centre</th>
                <th class="px-3 py-2 text-right text-gray-600">Members</th>
                <th class="px-3 py-2 text-right text-gray-600">Member BV</th>
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
                    default => 'bg-gray-100 text-gray-600',
                };
            @endphp
            <tr>
                <td class="px-3 py-2 font-medium">{{ \Illuminate\Support\Carbon::parse($row->month_start)->format('M Y') }}</td>
                <td class="px-3 py-2">{{ $row->center?->name ?? '#'.$row->center_id }}</td>
                <td class="px-3 py-2 text-right">{{ \App\Modules\Shared\Support\IndianNumber::format($row->member_count) }}</td>
                <td class="px-3 py-2 text-right">@bv($row->total_member_bv_paise)</td>
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
