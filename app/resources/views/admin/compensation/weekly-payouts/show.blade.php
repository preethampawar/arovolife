@extends('admin.layouts.admin')
@section('title', 'Payout batch')
@section('heading', 'Payout Batch — '.$batch->batch_date->format('d M Y'))

@section('content')

<div class="mb-4 flex items-center justify-between">
    <a href="{{ route('admin.compensation.weekly-payouts.index') }}"
       class="text-sm text-brand-600 hover:underline">← Back to payout batches</a>
    <div class="flex items-center gap-3">
        {{-- NEFT export --}}
        <a href="{{ route('admin.compensation.weekly-payouts.neft', $batch) }}"
           class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
            &#11015; NEFT CSV
        </a>
        {{-- Approve batch --}}
        @if($batch->status === 'pending')
        <form method="POST" action="{{ route('admin.compensation.weekly-payouts.approve', $batch) }}">
            @csrf
            <button type="submit"
                    onclick="return confirm('Approve this payout batch? This confirms the NEFT transfers have been (or will be) sent and marks all pending line items as transferred.')"
                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition-colors">
                ✓ Approve batch
            </button>
        </form>
        @elseif($batch->status === 'completed')
        <span class="text-xs text-gray-500">
            Approved by {{ $batch->approvedByUser?->full_name ?? 'system' }}
            on {{ $batch->approved_at?->format('d M Y H:i') }}
        </span>
        @endif
    </div>
</div>

@if(session('success'))
<div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
    {{ session('success') }}
</div>
@endif

@if(session('error'))
<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
    {{ session('error') }}
</div>
@endif

{{-- Batch summary --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Distributors</p>
        <p class="mt-1 text-lg font-bold text-gray-900">{{ number_format($batch->distributor_count) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider flex items-center gap-1">
            Total gross <x-help-tip text="Sum of wallet balances before repurchase deduction." />
        </p>
        <p class="mt-1 text-lg font-bold text-gray-900">₹{{ number_format($batch->total_gross_paise / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider flex items-center gap-1">
            Deductions <x-help-tip text="Repurchase wallet deductions across all line items." />
        </p>
        <p class="mt-1 text-lg font-bold text-red-600">₹{{ number_format($batch->total_deductions_paise / 100, 2) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Net to transfer</p>
        <p class="mt-1 text-lg font-bold text-green-700">₹{{ number_format($batch->total_net_paise / 100, 2) }}</p>
    </div>
</div>

{{-- Line items table --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900">Line items</span>
        @php
        $statusLabel = [
            'pending'    => ['text' => 'Pending approval', 'cls' => 'bg-amber-100 text-amber-700'],
            'processing' => ['text' => 'Processing',       'cls' => 'bg-blue-100 text-blue-700'],
            'completed'  => ['text' => 'Approved',         'cls' => 'bg-green-100 text-green-700'],
            'failed'     => ['text' => 'Failed',           'cls' => 'bg-red-100 text-red-700'],
        ][$batch->status] ?? ['text' => ucfirst($batch->status), 'cls' => 'bg-gray-100 text-gray-600'];
        @endphp
        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $statusLabel['cls'] }}">
            {{ $statusLabel['text'] }}
        </span>
    </div>
    @if($lines->isEmpty())
    <p class="px-6 py-10 text-sm text-gray-400 text-center">No line items in this batch.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-500">ADN</th>
                    <th class="px-3 py-2 text-left text-gray-500">Name</th>
                    <th class="px-3 py-2 text-right text-gray-500">
                        Wallet balance <x-help-tip text="Wallet balance at time of batch generation." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-500">
                        Repurchase deduction <x-help-tip text="10% of prior month GSB+MB+RB, capped ₹10,000." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-500">Net to transfer</th>
                    <th class="px-3 py-2 text-left text-gray-500">Bank (last 4)</th>
                    <th class="px-3 py-2 text-left text-gray-500">UTR</th>
                    <th class="px-3 py-2 text-center text-gray-500">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($lines as $line)
                @php
                    $lc = [
                        'transferred'    => 'bg-green-100 text-green-700',
                        'failed'         => 'bg-red-100 text-red-700',
                        'below_minimum'  => 'bg-gray-100 text-gray-500',
                        'pending'        => 'bg-amber-100 text-amber-700',
                    ];
                @endphp
                <tr>
                    <td class="px-3 py-2 font-mono font-medium">
                        <a href="{{ route('admin.compensation.distributors.show', $line->distributor_id) }}"
                           class="text-brand-600 hover:underline">
                            {{ $line->distributor->adn ?? '—' }}
                        </a>
                    </td>
                    <td class="px-3 py-2 text-gray-700 truncate max-w-[140px]">
                        {{ $line->distributor->user?->full_name ?? '—' }}
                    </td>
                    <td class="px-3 py-2 text-right">₹{{ number_format($line->wallet_balance_paise / 100, 2) }}</td>
                    <td class="px-3 py-2 text-right text-gray-500">
                        {{ $line->repurchase_deduction_paise > 0 ? '₹'.number_format($line->repurchase_deduction_paise / 100, 2) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right font-semibold {{ $line->net_transferred_paise > 0 ? 'text-green-700' : 'text-gray-400' }}">
                        ₹{{ number_format($line->net_transferred_paise / 100, 2) }}
                    </td>
                    <td class="px-3 py-2 font-mono text-gray-500">{{ $line->bank_account_last4 ?? '—' }}</td>
                    <td class="px-3 py-2 font-mono text-gray-500">{{ $line->utr_number ?? '—' }}</td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $lc[$line->status] ?? 'bg-gray-100 text-gray-500' }}">
                            {{ str_replace('_', ' ', ucfirst($line->status)) }}
                        </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $lines->links() }}</div>
    @endif
</div>

@endsection
