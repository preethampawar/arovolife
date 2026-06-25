<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Personal BV ledger for this distributor — every accrual (purchase) and reversal (cancellation / refund). Net BV is the running total. BV is tied exclusively to paid product sales (hard rule #2).
</div>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if(empty($rows) || $rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-400 text-center">No BV ledger entries yet.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-500">Date &amp; Time</th>
                <th class="px-3 py-2 text-left text-gray-500">Type</th>
                <th class="px-3 py-2 text-left text-gray-500">Order</th>
                <th class="px-3 py-2 text-right text-gray-500">BV <x-help-tip text="Positive = accrual (purchase). Negative = reversal (cancellation or refund)." /></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($rows as $row)
            <tr>
                <td class="px-3 py-2 font-medium">{{ $row->effective_at->format('d M Y, H:i') }}</td>
                <td class="px-3 py-2">
                    @if($row->type === 'accrual')
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium bg-green-100 text-green-700">Accrual</span>
                    @else
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium bg-red-100 text-red-700">Reversal</span>
                    @endif
                </td>
                <td class="px-3 py-2 text-gray-500">
                    @if($row->order_id)
                    <a href="{{ route('admin.commerce.orders.show', $row->order_id) }}" class="text-brand-600 hover:underline font-mono">#{{ $row->order_id }}</a>
                    @else
                    —
                    @endif
                </td>
                <td class="px-3 py-2 text-right font-semibold {{ $row->bv_paise >= 0 ? 'text-green-700' : 'text-red-600' }}">
                    {{ ($row->bv_paise >= 0 ? '+' : '') }}@bv($row->bv_paise)
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>
