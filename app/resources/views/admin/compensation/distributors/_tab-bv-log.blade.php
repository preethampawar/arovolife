<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Daily accumulated group BV for this distributor's left and right Genos subtrees. This is the input to the daily GSB cut-off. BV here is fresh daily (excludes carry-forward).
</div>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if(empty($rows) || $rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-400 text-center">No daily BV log yet — GSB engine not yet active.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-500">Date</th>
                <th class="px-3 py-2 text-right text-gray-500">Left group BV <x-help-tip text="Sum of all BV from left Genos subtree on this date." /></th>
                <th class="px-3 py-2 text-right text-gray-500">Right group BV</th>
                <th class="px-3 py-2 text-right text-gray-500">Total group BV</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($rows as $row)
            <tr>
                <td class="px-3 py-2 font-medium">{{ $row->date->format('d M Y') }}</td>
                <td class="px-3 py-2 text-right">@bv($row->left_bv_paise)</td>
                <td class="px-3 py-2 text-right">@bv($row->right_bv_paise)</td>
                <td class="px-3 py-2 text-right font-semibold">@bv($row->left_bv_paise + $row->right_bv_paise)</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>
