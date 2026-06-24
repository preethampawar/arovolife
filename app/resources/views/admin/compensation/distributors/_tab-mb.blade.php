<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Mentorship Bonus earned by this distributor as a sponsor. Rate starts at 10% of the sponsee's GSB, stepping down 1% per ₹30,000 cumulative sponsee GSB, flooring at 1% permanently. Each sponsor-sponsee pair is tracked independently.
</div>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if(empty($rows) || $rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-400 text-center">No Mentorship Bonus history yet.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-500">Date</th>
                <th class="px-3 py-2 text-left text-gray-500">Sponsee ADN <x-help-tip text="The downline distributor whose GSB triggered this Mentorship Bonus." /></th>
                <th class="px-3 py-2 text-right text-gray-500">Sponsee GSB <x-help-tip text="The sponsee's gross GSB on this date." /></th>
                <th class="px-3 py-2 text-center text-gray-500">Rate % <x-help-tip text="MB rate for this pair on this date (10% → 1% as cumulative sponsee GSB grows)." /></th>
                <th class="px-3 py-2 text-right text-gray-500">MB credited <x-help-tip text="MB amount credited to this distributor's wallet." /></th>
                <th class="px-3 py-2 text-right text-gray-500">Cumulative sponsee GSB <x-help-tip text="Total sponsee GSB seen by this pair — used to compute the step-down rate." /></th>
                <th class="px-3 py-2 text-center text-gray-500">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($rows as $row)
            <tr>
                <td class="px-3 py-2 font-medium">{{ $row->cutoff_date->format('d M Y') }}</td>
                <td class="px-3 py-2 font-mono">{{ $row->sponsee->adn ?? '—' }}</td>
                <td class="px-3 py-2 text-right">₹{{ number_format($row->sponsee_gsb_paise / 100, 2) }}</td>
                <td class="px-3 py-2 text-center font-semibold">{{ $row->mb_rate_pct }}%</td>
                <td class="px-3 py-2 text-right font-semibold text-green-700">₹{{ number_format($row->mb_paise / 100, 2) }}</td>
                <td class="px-3 py-2 text-right text-gray-500">₹{{ number_format($row->sponsee_cumulative_gsb_paise / 100, 2) }}</td>
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
