@extends('admin.layouts.admin')
@section('title', 'Monthly payout batch')
@section('heading', 'Monthly Payout Batch — '.$batch->batch_date->format('d M Y'))

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

    $countOf = fn (string $status) => (int) ($statusCounts[$status] ?? 0);
    $totalLines = (int) collect($statusCounts)->sum();
    $failedCount = $countOf('failed');
    $canApprove = $batch->status === 'pending';
    // Maker-checker (QA F94): approving is `finance.approve` — which the role
    // that RUNS the batch does not hold — and never by the admin who created
    // this batch. A scheduler-built batch has no maker and any approver may
    // sign it off.
    $isOwnBatch = $batch->created_by !== null && (int) $batch->created_by === (int) auth()->id();
    $mayApprove = $canApprove && auth()->user()?->can('finance.approve') && ! $isOwnBatch;
    $canReconcile = in_array($batch->status, ['approved', 'partially_failed', 'failed'], true);
    // The NEFT file is the instruction the bank acts on, so it exists only once
    // finance has signed the amount off — and only for finance (QA F95).
    $canExportNeft = $batch->approved_at !== null
        && in_array($batch->status, ['approved', 'dispatched', 'completed', 'partially_failed', 'failed'], true);
    // The manual controls on each line need a batch someone signed off.
    $canActOnLines = $canExportNeft && auth()->user()?->can('finance.record');
    $pendingAlreadySent = (int) ($bankFiles['pending_already_sent'] ?? 0);
    $routeBase = 'admin.compensation.monthly-payouts';
@endphp

<div class="mb-4 flex items-start justify-between gap-3 flex-wrap">
    <a href="{{ route('admin.compensation.monthly-payouts.index') }}"
       class="text-sm text-brand-700 hover:underline">{{ svg('lucide-arrow-left', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }} Back to monthly payout batches</a>

    <div class="flex items-center gap-2 flex-wrap justify-end">
        <span class="inline-flex px-2 py-1 rounded text-[11px] font-medium {{ $batchStatusLabel['cls'] }}">
            {{ $batchStatusLabel['text'] }}
        </span>

        @can('finance.record')
        @if($canExportNeft)
        {{-- In Razorpay mode the file is a record to reconcile against rather
             than an instruction, but it still only exists after approval. --}}
        {{-- Holds only the lines still to pay. Every download is kept (see
             Bank files below). --}}
        <form method="GET" action="{{ route('admin.compensation.monthly-payouts.neft', $batch) }}"
              data-confirm-title="Download bank file"
              data-confirm="Download the bank file for the {{ $countOf('pending') }} line(s) still to pay?"
              data-confirm-impact="{{ $pendingAlreadySent > 0
                  ? 'Warning: '.$pendingAlreadySent.' of these lines are already in a bank file you downloaded earlier. Upload this new file only if the bank did NOT process the earlier one, or those distributors are paid twice.'
                  : 'Impact: the file is kept on record, and the download is logged. It holds full account numbers — hand it only to the bank.' }}">
            <button type="submit"
                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                <x-lucide-download class="w-4 h-4" /> Download bank file (NEFT)
            </button>
        </form>
        @endif
        @endcan

        @if($mayApprove)
            @if($isRazorpay)
            <form method="POST" action="{{ route('admin.compensation.monthly-payouts.approve', $batch) }}"
                  data-confirm-title="Approve and dispatch to the bank"
                  data-confirm="Dispatch {{ $rupees($batch->total_net_paise) }} to {{ $batch->distributor_count }} distributor(s) through Razorpay Payouts?{{ $held['count'] > 0 ? ' A further '.$rupees($held['gross']).' of income for '.$held['count'].' distributor(s) is held in their wallets and is NOT part of this dispatch.' : '' }}"
                  data-confirm-impact="Impact: this initiates REAL BANK TRANSFERS immediately. Each transfer is confirmed by Razorpay's webhook and cannot be recalled from this screen.">
                @csrf
                <x-ui.button :disabled="! $gatewayReady">
                    <x-lucide-zap class="w-4 h-4" /> Approve &amp; dispatch to bank
                </x-ui.button>
            </form>
            @else
            <form method="POST" action="{{ route('admin.compensation.monthly-payouts.approve', $batch) }}"
                  data-confirm-title="Approve payout batch"
                  data-confirm="Approve this payout batch of {{ $rupees($batch->total_net_paise) }} to {{ $batch->distributor_count }} distributor(s)?{{ $held['count'] > 0 ? ' A further '.$rupees($held['gross']).' of income for '.$held['count'].' distributor(s) is held in their wallets and is NOT part of this approval.' : '' }}"
                  data-confirm-impact="Impact: the batch is signed off for payment. Holds are re-checked first — anyone whose KYC or bank details arrived since the batch was built is released into it. No money moves until you upload the bank file (NEFT) to the bank and import the bank's response file here.">
                @csrf
                <x-ui.button >
                    <x-lucide-check class="w-4 h-4" /> Approve batch
                </x-ui.button>
            </form>
            @endif
        @endif

        @if($canActOnLines && $failedCount > 0)
        <form method="POST" action="{{ route('admin.compensation.monthly-payouts.retry-failed', $batch) }}"
              data-confirm-title="Send all failed again"
              data-confirm="Send all {{ $failedCount }} failed payment(s) in this batch again?"
              data-confirm-impact="{{ $isRazorpay
                  ? 'Impact: each failed line under the retry limit ('.$maxRetries.') is sent to Razorpay again as a real bank transfer. Fix wrong bank details first — they fail again.'
                  : 'Impact: every failed line goes back to waiting and is included in the next bank file you download. Fix wrong bank details first — they fail again.' }}">
            @csrf
            <button type="submit"
                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-amber-300 bg-amber-50 text-sm font-medium text-amber-800 hover:bg-amber-100 transition-colors">
                <x-lucide-refresh-cw class="w-4 h-4" /> Send all {{ $failedCount }} failed again
            </button>
        </form>
        @endif
    </div>
</div>

<p class="mb-4 text-xs text-gray-600">
    Created by {{ $batch->createdByUser?->full_name ?? 'the scheduler' }}.
    @if($batch->approved_at)
        Approved by {{ $batch->approvedByUser?->full_name ?? 'system' }}
        on {{ $batch->approved_at->format('d M Y H:i') }}.
    @endif
</p>

@if($canApprove && $isOwnBatch)
<div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
    You created this batch, so a second person has to approve it. Separation of duties keeps the hand that builds a
    payout run apart from the hand that signs it off.
</div>
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

@include('admin.compensation.payout-bank-files._next-steps')

@if(! $isRazorpay && $canReconcile)
<x-ui.card flush class="mb-6">
    <div class="px-5 py-3 border-b border-gray-100">
        <span class="text-sm font-semibold text-gray-900 flex items-center gap-1">
            Import bank response
            <x-help-tip text="The response file your bank returns after processing the NEFT upload. Rows are matched on ADN; each one marks that line item transferred (with its UTR) or failed (with the bank's reason)." />
        </span>
    </div>
    <form method="POST" action="{{ route('admin.compensation.monthly-payouts.reconcile', $batch) }}"
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
                Rows for lines already paid or failed are skipped. Every uploaded file is kept (see Bank files below).
            </p>
        </div>
        <x-ui.button >
            <x-lucide-upload class="w-4 h-4" /> Import response
        </x-ui.button>
    </form>
</x-ui.card>
@endif

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
    <x-ui.card padding="p-4">
        <p class="text-xs font-medium text-gray-600 uppercase tracking-wider">Distributors</p>
        <p class="mt-1 text-lg font-bold text-gray-900">{{ \App\Modules\Shared\Support\IndianNumber::format($batch->distributor_count) }}</p>
    </x-ui.card>
    <x-ui.card padding="p-4">
        <p class="text-xs font-medium text-gray-600 uppercase tracking-wider flex items-center gap-1">
            Total gross <x-help-tip text="Sum of wallet balances before deductions." />
        </p>
        <p class="mt-1 text-lg font-bold text-gray-900">{{ $rupees($batch->total_gross_paise) }}</p>
    </x-ui.card>
    <x-ui.card padding="p-4">
        <p class="text-xs font-medium text-gray-600 uppercase tracking-wider flex items-center gap-1">
            Deductions <x-help-tip text="Repurchase deduction + admin charge (3% of gross, capped ₹25,000 per group) + TDS (5% of payable) across all line items." />
        </p>
        <p class="mt-1 text-lg font-bold text-red-600">{{ $rupees($batch->total_deductions_paise) }}</p>
        <dl class="mt-2 space-y-0.5 text-[11px] text-gray-600">
            <div class="flex items-baseline justify-between gap-2">
                <dt>Repurchase</dt>
                <dd class="font-medium tabular-nums text-gray-800">{{ $rupees($deductions['repurchase']) }}</dd>
            </div>
            <div class="flex items-baseline justify-between gap-2">
                <dt>Admin charge</dt>
                <dd class="font-medium tabular-nums text-gray-800">{{ $rupees($deductions['admin_charge']) }}</dd>
            </div>
            <div class="flex items-baseline justify-between gap-2">
                <dt>TDS</dt>
                <dd class="font-medium tabular-nums text-gray-800">{{ $rupees($deductions['tds']) }}</dd>
            </div>
        </dl>
    </x-ui.card>
    <x-ui.card padding="p-4">
        <p class="text-xs font-medium text-gray-600 uppercase tracking-wider">Net to transfer</p>
        <p class="mt-1 text-lg font-bold text-green-700">{{ $rupees($batch->total_net_paise) }}</p>
    </x-ui.card>
</div>

<div class="flex flex-wrap items-center gap-2 mb-6 text-xs">
    <span class="text-gray-600 font-medium">{{ $totalLines }} line item(s):</span>
    @foreach($statusCounts as $status => $count)
    <span class="inline-flex px-2 py-0.5 rounded font-medium {{ $lineStatusClass[$status] ?? 'bg-gray-100 text-gray-600' }}">
        {{ $count }} {{ str_replace('_', ' ', $status) }}
    </span>
    @endforeach
</div>

<x-ui.card flush>
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900">Line items</span>
        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $batchStatusLabel['cls'] }}">
            {{ $batchStatusLabel['text'] }}
        </span>
    </div>
    @if($lines->isEmpty())
    <x-ui.empty-state title="No line items in this batch." />
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-gray-600 w-12">S.No.</th>
                    <th class="px-3 py-2 text-left text-gray-600">ADN</th>
                    <th class="px-3 py-2 text-left text-gray-600">Name</th>
                    <th class="px-3 py-2 text-right text-gray-600">
                        Payable before deductions <x-help-tip text="What was left in the main wallet for this batch: gross minus the repurchase deduction already taken at credit time. The admin charge and TDS come off it below." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-600">
                        Gross <x-help-tip text="Bonus income swept into this batch, before any deduction." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-600">
                        Repurchase deduction <x-help-tip text="10% of each bonus, capped ₹10,000 per calendar month. Already taken at credit time — shown so the row reconciles." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-600">
                        Admin charge <x-help-tip text="3% of gross, capped at ₹25,000 per bonus group per cycle." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-600">
                        TDS <x-help-tip text="5% of payable (gross − repurchase − admin charge)." />
                    </th>
                    <th class="px-3 py-2 text-right text-gray-600">Net to transfer</th>
                    <th class="px-3 py-2 text-left text-gray-600">
                        Bank account <x-help-tip text="{{ $bank['full'] ? 'The beneficiary account the transfer goes to, in full, so it can be checked against the bank. Only finance roles see the whole number.' : 'The last four digits of the beneficiary account. The full number is visible to finance roles only.' }}" />
                    </th>
                    <th class="px-3 py-2 text-left text-gray-600">
                        UTR / Payout ID <x-help-tip text="The bank's Unique Transaction Reference once the transfer settles." />
                    </th>
                    <th class="px-3 py-2 text-center text-gray-600">Mode</th>
                    <th class="px-3 py-2 text-center text-gray-600">
                        Retries <x-help-tip text="How many times this transfer has been re-sent. The limit is {{ $maxRetries }}." />
                    </th>
                    <th class="px-3 py-2 text-center text-gray-600">Status</th>
                    <th class="px-3 py-2 text-left text-gray-600">Reason</th>
                    @if($canActOnLines)
                    <th class="px-3 py-2 text-center text-gray-600">
                        Actions <x-help-tip text="Finish a payment here when the bank response file cannot: Mark paid or Mark failed with what the bank told you, Mark returned when the bank sends a paid transfer back, Send again once a failure's cause is fixed. Every click is logged." />
                    </th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($lines as $line)
                <tr>
                    <td class="px-3 py-2 text-gray-500 tabular-nums">{{ $lines->firstItem() + $loop->index }}</td>
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
                    <td class="px-3 py-2 text-right text-gray-700">{{ $rupees($line->gross_paise) }}</td>
                    <td class="px-3 py-2 text-right text-gray-600">
                        {{ $line->repurchase_deduction_paise > 0 ? $rupees($line->repurchase_deduction_paise) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right text-gray-600">
                        {{ $line->admin_charge_paise > 0 ? $rupees($line->admin_charge_paise) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right text-gray-600">
                        {{ $line->tds_paise > 0 ? $rupees($line->tds_paise) : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right font-semibold {{ $line->net_transferred_paise > 0 ? 'text-green-700' : 'text-gray-600' }}">
                        {{ $rupees($line->net_transferred_paise) }}
                    </td>
                    <td class="px-3 py-2 font-mono text-gray-600 whitespace-nowrap">
                        {{ $bank['numbers'][$line->distributor_id] ?? $line->bank_account_last4 ?? '—' }}
                    </td>
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
                    @if($canActOnLines)
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        @include('admin.compensation.payout-bank-files._line-actions')
                    </td>
                    @endif
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $lines->links() }}</div>
    @endif
</x-ui.card>

@include('admin.compensation.payout-bank-files._panel')

@endsection
