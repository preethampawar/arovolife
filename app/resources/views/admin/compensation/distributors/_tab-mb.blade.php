<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Mentorship Bonus earned by this distributor as a sponsor. When a directly sponsored sponsee's cut-off matches a GSB slab, the sponsor earns that slab's MSB points; the credit is points × the slab's point value (per-slab configurable). Legacy rows from the old 10%→1% rate ladder show — in the points columns.
</div>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if(empty($rows) || $rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-400 text-center">No Mentorship Bonus history yet.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-500">Date</th>
                <th class="px-3 py-2 text-left text-gray-500">Sponsee ADN <x-help-tip text="The downline distributor whose GSB slab achievement triggered this Mentorship Bonus." /></th>
                <th class="px-3 py-2 text-center text-gray-500">Slab <x-help-tip text="The GSB slab the sponsee matched on this date." /></th>
                <th class="px-3 py-2 text-right text-gray-500">MSB points <x-help-tip text="Points credited to this sponsor for the sponsee's slab (snapshotted at credit time)." /></th>
                <th class="px-3 py-2 text-right text-gray-500">Point value <x-help-tip text="Rupee value of one MSB point at credit time (per-slab configurable, default ₹250)." /></th>
                <th class="px-3 py-2 text-right text-gray-500">MB credited <x-help-tip text="MB amount credited to this distributor's wallet (points × value)." /></th>
                <th class="px-3 py-2 text-center text-gray-500">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($rows as $row)
            <tr>
                <td class="px-3 py-2 font-medium">{{ $row->cutoff_date->format('d M Y') }}</td>
                <td class="px-3 py-2 font-mono">{{ $row->sponsee->adn ?? '—' }}</td>
                <td class="px-3 py-2 text-center">
                    @if($row->slab !== null)
                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 font-bold text-[11px]">{{ $row->slab }}</span>
                    @else
                    <span class="text-gray-400">—</span>
                    @endif
                </td>
                <td class="px-3 py-2 text-right font-semibold">
                    @if($row->msb_points !== null){{ $row->msb_points }}@else<span class="text-gray-400 font-normal">—</span>@endif
                </td>
                <td class="px-3 py-2 text-right text-gray-500">
                    @if($row->msb_point_value_paise !== null)₹{{ \Illuminate\Support\Number::format($row->msb_point_value_paise / 100, 2) }}@else<span class="text-gray-400">—</span>@endif
                </td>
                <td class="px-3 py-2 text-right font-semibold text-green-700">₹{{ \Illuminate\Support\Number::format($row->mb_gross_paise / 100, 2) }}</td>
                <td class="px-3 py-2 text-center">
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $row->status === 'credited' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ ucfirst($row->status) }}
                    </span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>
