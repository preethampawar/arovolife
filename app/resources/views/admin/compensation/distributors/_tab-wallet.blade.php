@developer
<div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    Append-only double-entry wallet ledger. Credits (GSB, MB) are positive; debits (payouts, reversals) are negative. The running balance is the wallet balance at that point in time. Repurchase wallet entries are shown separately below.
    @if(!empty($ledger) && $ledger->count() >= 500)
        <span class="block mt-1 text-amber-700 font-medium">Showing the most recent 500 entries. Older entries are omitted from this view.</span>
    @endif
</div>
@enddeveloper
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if(empty($ledger) || $ledger->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-600 text-center">No wallet transactions yet.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-600">Date</th>
                <th class="px-3 py-2 text-left text-gray-600">Type <x-help-tip text="gsb_credit, mb_credit = bonus credits; payout_debit = Tuesday transfer; reversal = admin correction." /></th>
                <th class="px-3 py-2 text-right text-gray-600">Amount</th>
                <th class="px-3 py-2 text-right text-gray-600">Running balance <x-help-tip text="Main wallet balance immediately after this entry." /></th>
                <th class="px-3 py-2 text-left text-gray-600">Memo</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($ledger as $item)
            @php $entry = $item['entry']; $runningBalance = $item['running_balance_paise']; @endphp
            <tr>
                <td class="px-3 py-2 font-medium">{{ $entry->created_at?->format('d M Y') }}</td>
                <td class="px-3 py-2 font-mono text-gray-600">{{ $entry->type }}</td>
                <td class="px-3 py-2 text-right font-semibold {{ $entry->amount_paise >= 0 ? 'text-green-700' : 'text-red-600' }}">
                    {{ $entry->amount_paise >= 0 ? '+' : '' }}₹{{ \App\Modules\Shared\Support\IndianNumber::format($entry->amount_paise / 100, 2) }}
                </td>
                <td class="px-3 py-2 text-right font-semibold text-blue-700">
                    ₹{{ \App\Modules\Shared\Support\IndianNumber::format($runningBalance / 100, 2) }}
                </td>
                <td class="px-3 py-2 text-gray-600">{{ $entry->memo ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>

{{-- Repurchase wallet ledger --}}
<h3 class="text-sm font-semibold text-gray-700 mt-6 mb-2 flex items-center gap-1">
    Repurchase Wallet
    <x-help-tip text="10% of prior-month bonus income (max ₹10,000) is withheld each payout and credited here. The balance is applied at checkout toward the monthly repurchase obligation. It cannot be withdrawn." />
</h3>
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if(empty($repurchaseLedger) || $repurchaseLedger->isEmpty())
    <p class="px-6 py-8 text-sm text-gray-600 text-center">No repurchase wallet transactions yet — deductions appear here once the first payout is processed.</p>
    @else
    <table class="w-full text-xs">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-2 text-left text-gray-600">Date</th>
                <th class="px-3 py-2 text-left text-gray-600">Type <x-help-tip text="repurchase_deduction = withheld from payout; repurchase_wallet_used = applied at checkout." /></th>
                <th class="px-3 py-2 text-right text-gray-600">Amount</th>
                <th class="px-3 py-2 text-left text-gray-600">Memo</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @foreach($repurchaseLedger as $entry)
            <tr>
                <td class="px-3 py-2 font-medium">{{ $entry->created_at?->format('d M Y') }}</td>
                <td class="px-3 py-2 font-mono text-gray-600">{{ $entry->type }}</td>
                <td class="px-3 py-2 text-right font-semibold {{ $entry->amount_paise >= 0 ? 'text-green-700' : 'text-red-600' }}">
                    {{ $entry->amount_paise >= 0 ? '+' : '' }}₹{{ \App\Modules\Shared\Support\IndianNumber::format($entry->amount_paise / 100, 2) }}
                </td>
                <td class="px-3 py-2 text-gray-600">{{ $entry->memo ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>
