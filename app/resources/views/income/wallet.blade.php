@extends('layouts.app')
@section('title', 'My Income — Wallet & Payouts')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My Income</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        Your wallet receives GSB and Mentorship Bonus credits after each 23:59 cut-off. Every Tuesday, your wallet balance (minus deductions) is transferred to your registered bank account — provided the balance is at least ₹500. Repurchase deduction: 10% of your previous month's GSB + Mentorship Bonus (max ₹10,000) is held back to fund your mandatory monthly repurchase. Balances below ₹500 roll over to the next Tuesday.
    </div>

    {{-- Stat cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-500">Wallet Balance</p>
                <x-help-tip text="Your current wallet balance — GSB and other bonus credits net of any debits. This will be transferred to your bank account on the next payout date." />
            </div>
            <p class="text-2xl font-bold {{ $walletBalancePaise > 0 ? 'text-green-700' : 'text-gray-900' }}">
                ₹{{ number_format($walletBalancePaise / 100, 2) }}
            </p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-500">Total Paid Out</p>
                <x-help-tip text="Total net amount transferred to your bank account since you joined." />
            </div>
            <p class="text-2xl font-bold text-gray-900">₹{{ number_format($totalPaidOutPaise / 100, 2) }}</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-500">Next Payout Date</p>
            </div>
            <p class="text-2xl font-bold text-gray-900">{{ $nextPayout->format('d M') }}</p>
            <p class="text-xs text-gray-400 mt-0.5">Every Tuesday (IST)</p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-xs text-gray-500">Min. Payout</p>
                <x-help-tip text="Wallet balances below this threshold roll over to the next Tuesday batch." />
            </div>
            <p class="text-2xl font-bold text-gray-900">₹{{ number_format($minThresholdPaise / 100, 0) }}</p>
        </div>
    </div>

    {{-- Wallet ledger --}}
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-base font-semibold text-gray-800">Wallet Ledger</h2>
        <a href="{{ route('income.wallet.export') }}" class="text-sm text-brand-600 hover:text-brand-700 font-medium">&#11015; CSV</a>
    </div>

    @if($ledgerRows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-8 text-center mb-6">
            <p class="text-gray-500 font-medium">No wallet transactions yet.</p>
            <p class="text-sm text-gray-400 mt-1">Credits will appear here after your first GSB cut-off.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto mb-6">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Date</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center gap-1">Type <x-help-tip text="gsb_credit = daily GSB. mb_credit = Mentorship Bonus. payout_debit = Tuesday bank transfer. manual_credit = admin adjustment." /></span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Amount</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Running balance <x-help-tip text="Your wallet balance immediately after this entry." /></span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($ledgerRows as $item)
                    @php $entry = $item['entry']; $runningBalance = $item['running_balance_paise']; @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-700">{{ $entry->created_at?->format('d M Y') }}</td>
                        <td class="px-4 py-3 font-mono text-gray-600 text-xs">{{ $entry->type }}</td>
                        <td class="px-4 py-3 text-right font-semibold {{ $entry->amount_paise >= 0 ? 'text-green-700' : 'text-red-600' }}">
                            {{ $entry->amount_paise >= 0 ? '+' : '-' }}₹{{ number_format(abs($entry->amount_paise) / 100, 2) }}
                        </td>
                        <td class="px-4 py-3 text-right font-semibold text-blue-700">
                            ₹{{ number_format($runningBalance / 100, 2) }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Payout history --}}
    <h2 class="text-base font-semibold text-gray-800 mb-3">Payout History</h2>
    @if($payoutRows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-8 text-center">
            <p class="text-gray-500 font-medium">No payouts yet.</p>
            <p class="text-sm text-gray-400 mt-1">Your first bank transfer will appear here after the Tuesday payout run.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Date</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">Net transferred <x-help-tip text="Amount actually sent to your bank account after all deductions." /></span>
                        </th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">Status</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">UTR</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($payoutRows as $row)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-gray-700">{{ $row->created_at?->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold text-green-700">₹{{ number_format($row->net_transferred_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($row->status === 'transferred')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Transferred</span>
                            @elseif($row->status === 'below_minimum')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">Below ₹{{ number_format($minThresholdPaise / 100, 0) }}</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">{{ ucfirst($row->status) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ $row->utr_number ?? '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
