@extends('admin.layouts.admin')
@section('title', 'Payout batch')
@section('heading', 'Payout Batch — '.$batch->batch_date->format('d M Y'))

@section('content')

@php
    $rupees = fn ($paise) => '₹'.\App\Modules\Shared\Support\IndianNumber::format($paise / 100, 2);

    $batchStatusLabel = [
        'pending'          => ['text' => 'Pending approval',            'cls' => 'bg-amber-100 text-amber-700'],
        'processing'       => ['text' => 'Processing',                  'cls' => 'bg-blue-100 text-blue-700'],
        'approved'         => ['text' => 'Approved — awaiting bank',    'cls' => 'bg-blue-100 text-blue-700'],
        'dispatched'       => ['text' => 'Dispatched — awaiting bank',  'cls' => 'bg-blue-100 text-blue-700'],
        'completed'        => ['text' => 'Completed',                   'cls' => 'bg-green-100 text-green-700'],
        'failed'           => ['text' => 'Failed',                      'cls' => 'bg-red-100 text-red-700'],
        'partially_failed' => ['text' => 'Partially failed',            'cls' => 'bg-red-100 text-red-700'],
    ][$batch->status] ?? ['text' => ucfirst(str_replace('_', ' ', $batch->status)), 'cls' => 'bg-gray-100 text-gray-600'];

    $lineStatusClass = [
        'transferred'         => 'bg-green-100 text-green-700',
        'failed'              => 'bg-red-100 text-red-700',
        'bank_decrypt_failed' => 'bg-red-100 text-red-700',
        'pending'             => 'bg-amber-100 text-amber-700',
        'kyc_pending'         => 'bg-amber-100 text-amber-700',
        'below_minimum'       => 'bg-gray-100 text-gray-600',
        'no_bank_account'     => 'bg-gray-100 text-gray-600',
        'web_only'            => 'bg-gray-100 text-gray-600',
    ];

    // Only these three describe money in transit; the rest are wallet holds.
    $countOf = fn (string $status) => (int) ($statusCounts[$status] ?? 0);
    $totalLines = (int) collect($statusCounts)->sum();
    $failedCount = $countOf('failed');
    $canApprove = $batch->status === 'pending';
    $canReconcile = in_array($batch->status, ['approved', 'partially_failed', 'failed'], true);
@endphp

<div class="mb-4 flex items-start justify-between gap-3 flex-wrap">
    <a href="{{ route('admin.compensation.weekly-payouts.index') }}"
       class="text-sm text-brand-700 hover:underline">← Back to payout batches</a>

    <div class="flex items-center gap-2 flex-wrap justify-end">
        <span class="inline-flex px-2 py-1 rounded text-[11px] font-medium {{ $batchStatusLabel['cls'] }}">
            {{ $batchStatusLabel['text'] }}
        </span>

        {{-- The CSV is always available: finance reconciles against it even in
             Razorpay mode, where it is a record rather than an instruction. --}}
        <a href="{{ route('admin.compensation.weekly-payouts.neft', $batch) }}"
           class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
            <x-lucide-download class="w-4 h-4" /> NEFT CSV
        </a>

        @if($canApprove)
            @if($isRazorpay)
            <form method="POST" action="{{ route('admin.compensation.weekly-payouts.approve', $batch) }}"
                  data-confirm-title="Approve and dispatch to the bank"
                  data-confirm="Dispatch {{ $rupees($batch->total_net_paise) }} to {{ $batch->distributor_count }} distributor(s) through Razorpay Payouts?"
                  data-confirm-impact="Impact: this initiates REAL BANK TRANSFERS immediately. Each transfer is confirmed by Razorpay's webhook and cannot be recalled from this screen.">
                @csrf
                <button type="submit" @disabled(! $gatewayReady)
                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-sm font-semibold transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                    <x-lucide-zap class="w-4 h-4" /> Approve &amp; dispatch to bank
                </button>
            </form>
            @else
            <form method="POST" action="{{ route('admin.compensation.weekly-payouts.approve', $batch) }}"
                  data-confirm-title="Approve payout batch"
                  data-confirm="Approve this payout batch of {{ $rupees($batch->total_net_paise) }} to {{ $batch->distributor_count }} distributor(s)?"
                  data-confirm-impact="Impact: the batch is signed off for payment. No money moves until you upload the NEFT CSV to the bank and import the bank's response file here.">
                @csrf
                <button type="submit"
                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-sm font-semibold transition-colors">
                    <x-lucide-check class="w-4 h-4" /> Approve batch
                </button>
            </form>
            @endif
        @endif

        @if($isRazorpay && $failedCount > 0)
        <form method="POST" action="{{ route('admin.compensation.weekly-payouts.retry-failed', $batch) }}"
              data-confirm-title="Retry failed payouts"
              data-confirm="Re-send all {{ $failedCount }} failed transfer(s) in this batch?"
              data-confirm-impact="Impact: each eligible line item is queued for another attempt with Razorpay. Lines that have reached the retry limit ({{ $maxRetries }}) are skipped.">
            @csrf
            <button type="submit"
                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-amber-300 bg-amber-50 text-sm font-medium text-amber-800 hover:bg-amber-100 transition-colors">
                <x-lucide-refresh-cw class="w-4 h-4" /> Retry {{ $failedCount }} failed
            </button>
        </form>
        @endif
    </div>
</div>

@if($batch->approved_at)
<p class="mb-4 text-xs text-gray-600">
    Approved by {{ $batch->approvedByUser?->full_name ?? 'system' }}
    on {{ $batch->approved_at->format('d M Y H:i') }}.
</p>
@endif

@if($canApprove && $isRazorpay && ! $gatewayReady)
<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
    The payout gateway is set to <strong>Razorpay Payouts</strong> but its credentials are not configured on this
    server, so this batch cannot be dispatched. Set the <code>RAZORPAYX_*</code> environment variables, or switch the
    gateway to Manual NEFT on the
    <a href="{{ route('admin.compensation.payout-settings.index') }}" class="underline font-medium">Payout Settings</a> page.
</div>
@endif

@if(session('success'))
<div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
    {{ session('success') }}
</div>
@endif

@if(session('error') || $errors->any())
<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
    {{ session('error') ?? $errors->first() }}
</div>
@endif

{{-- Manual NEFT: import the bank's response file ─────────────────────── --}}
@if(! $isRazorpay && $canReconcile)
<div class="mb-6 bg-white rounded-xl border border-gray-200 shadow-sm">
    <div class="px-5 py-3 border-b border-gray-100">
        <span class="text-sm font-semibold text-gray-900 flex items-center gap-1">
            Import bank response
            <x-help-tip text="The response file your bank returns after processing the NEFT upload. Rows are matched on ADN; each one marks that line item transferred (with its UTR) or failed (with the bank's reason)." />
        </span>
    </div>
    <form method="POST" action="{{ route('admin.compensation.weekly-payouts.reconcile', $batch) }}"
          enctype="multipart/form-data" class="p-5 flex flex-wrap items-end gap-3"
          data-confirm-title="Import bank response file"
          data-confirm="Apply this bank response file to the batch?"
          data-confirm-impact="Impact: line items still awaiting the bank are marked transferred or failed from the file. Lines already settled are left untouched and reported as skipped.">
        @csrf
        <div class="flex-1 min-w-[260px]">
            <label for="response_file" class="block text-xs font-medium text-gray-700 mb-1">Bank response file (CSV, max 5 MB)</label>
            <input type="file" id="response_file" name="response_file" accept=".csv,text/csv" required
                   class="block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-200">
            <p class="mt-1 text-xs text-gray-500">
                Needs a header row with an <strong>ADN</strong> column and a <strong>Status</strong> column;
                <strong>UTR</strong> and <strong>Failure Reason</strong> are used when present.
            </p>
        </div>
        <button type="submit"
                class="inline-flex items-center gap-1 px-3 py-2 rounded-lg bg-brand-700 hover:bg-brand-800 text-white text-sm font-semibold transition-colors">
            <x-lucide-upload class="w-4 h-4" /> Import response
        </button>
    </form>
</div>
@endif

{{-- Batch summary --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-600 uppercase tracking-wider">Distributors</p>
        <p class="mt-1 text-lg font-bold text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::format($batch->distributor_count) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-600 uppercase tracking-wider flex items-center gap-1">
            Total gross <x-help-tip text="Sum of wallet balances before repurchase deduction." />
        </p>
        <p class="mt-1 text-lg font-bold text-gray-900">{{ $rupees($batch->total_gross_paise) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-600 uppercase tracking-wider flex items-center gap-1">
            Deductions <x-help-tip text="Repurchase deduction + admin charge + TDS across all line items." />
        </p>
        <p class="mt-1 text-lg font-bold text-red-600">{{ $rupees($batch->total_deductions_paise) }}</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
        <p class="text-xs font-medium text-gray-600 uppercase tracking-wider">Net to transfer</p>
        <p class="mt-1 text-lg font-bold text-green-700">{{ $rupees($batch->total_net_paise) }}</p>
    </div>
</div>

{{-- Where every line item stands --}}
<div class="flex flex-wrap items-center gap-2 mb-6 text-xs">
    <span class="text-gray-600 font-medium">{{ $totalLines }} line item(s):</span>
    @foreach($statusCounts as $status => $count)
    <span class="inline-flex px-2 py-0.5 rounded font-medium {{ $lineStatusClass[$status] ?? 'bg-gray-100 text-gray-600' }}">
        {{ $count }} {{ str_replace('_', ' ', $status) }}
    </span>
    @endforeach
</div>

{{-- Line items table --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900">Line items</span>
        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $batchStatusLabel['cls'] }}">
            {{ $batchStatusLabel['text'] }}
        </span>
    </div>
    @if($lines->isEmpty())
    <p class="px-6 py-10 text-sm text-gray-600 text-center">No line items in this batch.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-600">ADN</th>
                    <th class="px-3 py-2 text-left text-gray-600">Name</th>
                    <th class="px-3 py-2 text-right text-gray-600">
                        Wallet balance <x-help-tip text="Wallet balance at time of batch generation." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-600">
                        Repurchase deduction <x-help-tip text="10% of prior month GSB+MB+RB, capped ₹10,000." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-600">Net to transfer</th>
                    <th class="px-3 py-2 text-left text-gray-600">Bank (last 4)</th>
                    <th class="px-3 py-2 text-left text-gray-600">
                        UTR / Payout ID <x-help-tip text="The bank's Unique Transaction Reference once the transfer settles. The payout ID is Razorpay's own reference while it is in flight." />
                    </th>
                    <th class="px-3 py-2 text-center text-gray-600">Mode</th>
                    <th class="px-3 py-2 text-center text-gray-600">
                        Retries <x-help-tip text="How many times this transfer has been re-sent. The limit is {{ $maxRetries }}." />
                    </th>
                    <th class="px-3 py-2 text-center text-gray-600">Status</th>
                    <th class="px-3 py-2 text-left text-gray-600">Reason</th>
                    @if($isRazorpay)
                    <th class="px-3 py-2 text-center text-gray-600">Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($lines as $line)
                <tr>
                    <td class="px-3 py-2 font-mono font-medium">
                        <a href="{{ route('admin.compensation.distributors.show', $line->distributor_id) }}"
                           class="text-brand-700 hover:underline">
                            {{ $line->distributor->adn ?? '—' }}
                        </a>
                    </td>
                    <td class="px-3 py-2 text-gray-700 truncate max-w-[140px]">
                        {{ $line->distributor->user?->full_name ?? '—' }}
                    </td>
                    <td class="px-3 py-2 text-right">{{ $rupees($line->wallet_balance_paise) }}</td>
                    <td class="px-3 py-2 text-right text-gray-600">
                        {{ $line->repurchase_deduction_paise > 0 ? $rupees($line->repurchase_deduction_paise) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right font-semibold {{ $line->net_transferred_paise > 0 ? 'text-green-700' : 'text-gray-600' }}">
                        {{ $rupees($line->net_transferred_paise) }}
                    </td>
                    <td class="px-3 py-2 font-mono text-gray-600">{{ $line->bank_account_last4 ?? '—' }}</td>
                    <td class="px-3 py-2 font-mono text-gray-600">
                        @if($line->utr_number)
                            {{ $line->utr_number }}
                        @elseif($line->razorpay_payout_id)
                            <span class="text-gray-500" title="Razorpay payout reference — the UTR arrives when the bank settles it.">{{ $line->razorpay_payout_id }}</span>
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center text-gray-600 uppercase">{{ $line->transfer_mode ?? '—' }}</td>
                    <td class="px-3 py-2 text-center {{ $line->retry_count > 0 ? 'font-semibold text-amber-700' : 'text-gray-500' }}">
                        {{ $line->retry_count }}
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $lineStatusClass[$line->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ str_replace('_', ' ', ucfirst($line->status)) }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-gray-600 max-w-[220px]">
                        <span class="line-clamp-2" title="{{ $line->failure_reason }}">{{ $line->failure_reason ?? '—' }}</span>
                    </td>
                    @if($isRazorpay)
                    <td class="px-3 py-2 text-center">
                        @if($line->status === 'failed' && $line->razorpay_payout_id === null && $line->retry_count < $maxRetries && $line->net_transferred_paise > 0)
                        <form method="POST" action="{{ route('admin.compensation.weekly-payouts.line-items.retry', [$batch, $line]) }}"
                              data-confirm-title="Retry this payout"
                              data-confirm="Re-send {{ $rupees($line->net_transferred_paise) }} to ADN {{ $line->distributor->adn ?? $line->distributor_id }}?"
                              data-confirm-impact="Impact: this queues another real bank transfer attempt. Attempt {{ $line->retry_count + 1 }} of {{ $maxRetries }}.">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-1 px-2 py-1 rounded border border-amber-300 bg-amber-50 text-[11px] font-medium text-amber-800 hover:bg-amber-100 transition-colors">
                                <x-lucide-refresh-cw class="w-3 h-3" /> Retry
                            </button>
                        </form>
                        @elseif($line->status === 'failed')
                        <span class="text-[11px] text-gray-500">Not retryable</span>
                        @else
                        <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    @endif
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $lines->links() }}</div>
    @endif
</div>

@endsection
