@developer
<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    History of weekly Tuesday bank transfer payout line items for this distributor. Each batch pays one Wednesday-to-Tuesday earning week and runs the Tuesday one week after that week closed, so a batch dated Tuesday 22 September pays income earned from Wednesday 9 September to Tuesday 15 September.
</div>
@enddeveloper
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if(empty($rows) || $rows->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-600 text-center">No payout history yet.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-600 w-12">S.No.</th>
                <th class="px-3 py-2 text-left text-gray-600">Batch date</th>
                <th class="px-3 py-2 text-right text-gray-600">Wallet balance <x-help-tip text="Wallet balance at time of payout." /></th>
                <th class="px-3 py-2 text-right text-gray-600">Repurchase deduction <x-help-tip text="Deducted from bonus credits at earn time (10% per credit, monthly cap ₹10,000). Swept to the repurchase wallet." /></th>
                <th class="px-3 py-2 text-right text-gray-600">Admin 3% <x-help-tip text="3% of gross income, capped ₹25,000 per bonus group." /></th>
                <th class="px-3 py-2 text-right text-gray-600">TDS 5% <x-help-tip text="5% of payable (after repurchase and admin deduction)." /></th>
                <th class="px-3 py-2 text-right text-gray-600">Net transferred</th>
                <th class="px-3 py-2 text-left text-gray-600">UTR</th>
                <th class="px-3 py-2 text-center text-gray-600">Status</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($rows as $line)
            @php
                $lc = ['transferred' => 'bg-green-100 text-green-700', 'failed' => 'bg-red-100 text-red-700', 'below_minimum' => 'bg-gray-100 text-gray-600', 'pending' => 'bg-amber-100 text-amber-700', 'kyc_pending' => 'bg-amber-100 text-amber-700', 'no_bank_account' => 'bg-gray-100 text-gray-600', 'web_only' => 'bg-gray-100 text-gray-600', 'bank_decrypt_failed' => 'bg-red-100 text-red-700'];
            @endphp
            <tr>
                <td class="px-3 py-2 text-gray-500 tabular-nums">{{ $rows->firstItem() + $loop->index }}</td>
                <td class="px-3 py-2 font-medium">
                    <a href="{{ route('admin.compensation.weekly-payouts.show', $line->payoutBatch) }}"
                       class="text-brand-700 hover:underline">
                        {{ $line->payoutBatch?->batch_date?->format('d M Y') ?? '—' }}
                    </a>
                </td>
                <td class="px-3 py-2 text-right">₹{{ \App\Modules\Shared\Support\IndianNumber::format($line->wallet_balance_paise / 100, 2) }}</td>
                <td class="px-3 py-2 text-right text-gray-600">
                    {{ $line->repurchase_deduction_paise > 0 ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($line->repurchase_deduction_paise / 100, 2) : '—' }}
                </td>
                <td class="px-3 py-2 text-right text-gray-600">
                    {{ ($line->admin_charge_paise ?? 0) > 0 ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($line->admin_charge_paise / 100, 2) : '—' }}
                </td>
                <td class="px-3 py-2 text-right text-gray-600">
                    {{ ($line->tds_paise ?? 0) > 0 ? '₹'.\App\Modules\Shared\Support\IndianNumber::format($line->tds_paise / 100, 2) : '—' }}
                </td>
                <td class="px-3 py-2 text-right font-semibold text-green-700">₹{{ \App\Modules\Shared\Support\IndianNumber::format($line->net_transferred_paise / 100, 2) }}</td>
                <td class="px-3 py-2 font-mono text-gray-600">{{ $line->utr_number ?? '—' }}</td>
                <td class="px-3 py-2 text-center">
                    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $lc[$line->status] ?? 'bg-gray-100 text-gray-600' }}">
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
