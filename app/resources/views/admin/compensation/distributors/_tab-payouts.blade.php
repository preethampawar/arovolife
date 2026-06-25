<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    History of weekly Tuesday bank transfer payout line items for this distributor.
</div>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if(empty($rows) || $rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-400 text-center">No payout history yet.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-500">Batch date</th>
                <th class="px-3 py-2 text-right text-gray-500">Wallet balance <x-help-tip text="Wallet balance at time of payout." /></th>
                <th class="px-3 py-2 text-right text-gray-500">Repurchase deduction <x-help-tip text="10% of prior month GSB+MB+RB, capped ₹10,000." /></th>
                <th class="px-3 py-2 text-right text-gray-500">Net transferred</th>
                <th class="px-3 py-2 text-left text-gray-500">UTR</th>
                <th class="px-3 py-2 text-center text-gray-500">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($rows as $line)
            @php
                $lc = ['transferred' => 'bg-green-100 text-green-700', 'failed' => 'bg-red-100 text-red-700', 'below_minimum' => 'bg-gray-100 text-gray-500', 'pending' => 'bg-amber-100 text-amber-700'];
            @endphp
            <tr>
                <td class="px-3 py-2 font-medium">
                    <a href="{{ route('admin.compensation.weekly-payouts.show', $line->payoutBatch) }}"
                       class="text-brand-600 hover:underline">
                        {{ $line->payoutBatch?->batch_date?->format('d M Y') ?? '—' }}
                    </a>
                </td>
                <td class="px-3 py-2 text-right">₹{{ number_format($line->wallet_balance_paise / 100, 2) }}</td>
                <td class="px-3 py-2 text-right text-gray-500">
                    {{ $line->repurchase_deduction_paise > 0 ? '₹'.number_format($line->repurchase_deduction_paise / 100, 2) : '—' }}
                </td>
                <td class="px-3 py-2 text-right font-semibold text-green-700">₹{{ number_format($line->net_transferred_paise / 100, 2) }}</td>
                <td class="px-3 py-2 font-mono text-gray-500">{{ $line->utr_number ?? '—' }}</td>
                <td class="px-3 py-2 text-center">
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $lc[$line->status] ?? 'bg-gray-100 text-gray-500' }}">
                        {{ ucfirst(str_replace('_', ' ', $line->status)) }}
                    </span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>
