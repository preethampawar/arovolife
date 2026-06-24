<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Shows every daily cut-off result for this distributor. Gross GSB is before deductions. Failed rows have not been credited to the wallet — use Retry. Reversed rows have a debit entry in the wallet ledger.
</div>

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-4">
    <input type="hidden" name="tab" value="gsb">
    <input type="date" name="from" value="{{ $from ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" placeholder="From">
    <input type="date" name="to" value="{{ $to ?? '' }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" placeholder="To">
    <select name="status" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
        <option value="">All statuses</option>
        @foreach(['credited' => 'Credited', 'failed' => 'Failed', 'no_match' => 'No match', 'frozen' => 'Frozen', 'below_600bv' => 'Below 600 BV', 'calculated' => 'Calculated'] as $value => $label)
        <option value="{{ $value }}" {{ ($status ?? '') === $value ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
    </select>
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-500 text-white text-sm font-medium">Apply</button>
    @if($from || $to || ($status ?? ''))
    <a href="{{ route('admin.compensation.distributors.show', [$distributor, 'tab' => 'gsb']) }}"
       class="text-sm text-gray-500 hover:text-gray-700">Clear</a>
    @endif
</form>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if(empty($rows) || $rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-400 text-center">No GSB history yet.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-500">Date</th>
                <th class="px-3 py-2 text-right text-gray-500">Left BV <x-help-tip text="Left group BV today (fresh, no carry-forward)." /></th>
                <th class="px-3 py-2 text-right text-gray-500">Right BV</th>
                <th class="px-3 py-2 text-center text-gray-500">Slab</th>
                <th class="px-3 py-2 text-right text-gray-500">Gross GSB</th>
                <th class="px-3 py-2 text-right text-gray-500">Admin 3%</th>
                <th class="px-3 py-2 text-right text-gray-500">TDS 5%</th>
                <th class="px-3 py-2 text-right text-gray-500">Net GSB</th>
                <th class="px-3 py-2 text-center text-gray-500">Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($rows as $row)
            @php $b = ['credited' => 'bg-green-100 text-green-700', 'failed' => 'bg-red-100 text-red-700', 'no_match' => 'bg-gray-100 text-gray-500', 'frozen' => 'bg-blue-100 text-blue-700', 'below_600bv' => 'bg-amber-100 text-amber-700']; @endphp
            <tr class="{{ $row->status === 'failed' ? 'bg-red-50' : '' }}">
                <td class="px-3 py-2 font-medium">{{ $row->cutoff_date->format('d M Y') }}</td>
                <td class="px-3 py-2 text-right">@bv($row->left_bv_paise)</td>
                <td class="px-3 py-2 text-right">@bv($row->right_bv_paise)</td>
                <td class="px-3 py-2 text-center">{{ $row->slab ?? '—' }}</td>
                <td class="px-3 py-2 text-right font-semibold">{{ $row->gross_gsb_paise ? '₹'.number_format($row->gross_gsb_paise / 100, 2) : '—' }}</td>
                <td class="px-3 py-2 text-right text-gray-500">{{ $row->admin_charge_paise ? '₹'.number_format($row->admin_charge_paise / 100, 2) : '—' }}</td>
                <td class="px-3 py-2 text-right text-gray-500">{{ $row->tds_paise ? '₹'.number_format($row->tds_paise / 100, 2) : '—' }}</td>
                <td class="px-3 py-2 text-right font-semibold {{ $row->net_gsb_paise > 0 ? 'text-green-700' : 'text-gray-400' }}">{{ $row->net_gsb_paise > 0 ? '₹'.number_format($row->net_gsb_paise / 100, 2) : '—' }}</td>
                <td class="px-3 py-2 text-center">
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $b[$row->status] ?? 'bg-gray-100 text-gray-500' }}">{{ str_replace('_', ' ', $row->status) }}</span>
                </td>
                <td class="px-3 py-2">
                    @if($row->status === 'failed')
                    <a href="{{ route('admin.compensation.manual-controls.index', ['adn' => $distributor->adn, 'action' => 'retry', 'date' => $row->cutoff_date->toDateString()]) }}" class="text-[10px] px-2 py-0.5 rounded bg-amber-100 text-amber-800 font-medium">Retry</a>
                    @elseif($row->status === 'credited')
                    <a href="{{ route('admin.compensation.manual-controls.index', ['adn' => $distributor->adn, 'action' => 'reverse', 'date' => $row->cutoff_date->toDateString()]) }}" class="text-[10px] px-2 py-0.5 rounded bg-red-100 text-red-700 font-medium">Reverse</a>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>
