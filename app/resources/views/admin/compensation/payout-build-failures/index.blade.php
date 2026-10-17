@extends('admin.layouts.admin')
@section('title', 'Payout build failures')
@section('heading', 'Payouts — Build failures')

@section('content')

<div class="mb-5 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    When one distributor's payout line cannot be built, the batch skips them and carries on for everyone else.
    Nothing is taken from their wallet. Fix the cause, then <strong>Retry</strong>: before the batch is approved they
    are built into that same batch and paid for that week or month. Once a batch is approved without them, the next
    batch of the same type pays them — a week later for a weekly batch, a month later for a monthly one — and the row
    closes by itself.
</div>

@if(session('success'))
<div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
@endif

{{-- Filters --}}
<form method="GET" class="flex flex-wrap items-center gap-3 mb-5">
    <select name="status" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
        <option value="open" {{ $status === 'open' ? 'selected' : '' }}>Open ({{ $openCount }})</option>
        <option value="resolved" {{ $status === 'resolved' ? 'selected' : '' }}>Resolved</option>
        <option value="all" {{ $status === 'all' ? 'selected' : '' }}>All</option>
    </select>
    <select name="type" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
        <option value="">Weekly and monthly</option>
        <option value="weekly" {{ $type === 'weekly' ? 'selected' : '' }}>Weekly</option>
        <option value="monthly" {{ $type === 'monthly' ? 'selected' : '' }}>Monthly</option>
    </select>
    <input type="date" name="from" value="{{ $from }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" aria-label="Last failed from">
    <input type="date" name="to" value="{{ $to }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" aria-label="Last failed to">
    <input type="text" name="q" value="{{ $q }}" placeholder="Search ADN…" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm w-40">
    @if($batchId)
    <input type="hidden" name="batch" value="{{ $batchId }}">
    <a href="{{ route('admin.compensation.payout-failures.index', array_filter(['status' => $status, 'type' => $type, 'q' => $q])) }}"
       class="text-xs text-brand-700 hover:underline">Batch #{{ $batchId }} only — show all batches</a>
    @endif
    <x-ui.button>Apply</x-ui.button>
</form>

@php
    $filteredBatch = $batchId ? $rows->first()?->payoutBatch : null;
@endphp
@if($filteredBatch && $filteredBatch->approved_at === null && $rows->contains(fn ($r) => ! $r->isResolved()))
<form method="POST" action="{{ route('admin.compensation.payout-failures.retry-batch', $filteredBatch) }}" class="mb-4"
      data-confirm-title="Retry every open failure in this batch"
      data-confirm="Retry every distributor this batch could not build?"
      data-confirm-impact="Impact: each one is rebuilt on the compensation queue. Those that now build are added to this batch and its totals change; those that still fail stay here with the new error.">
    @csrf
    <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-amber-300 bg-amber-50 text-sm font-medium text-amber-800 hover:bg-amber-100">
        <x-lucide-refresh-cw class="w-4 h-4" /> Retry all open in batch #{{ $filteredBatch->id }}
    </button>
</form>
@endif

<x-ui.card flush>
    @if($rows->isEmpty())
    <x-ui.empty-state title="No build failures match these filters." />
    @else
    <div class="divide-y divide-gray-100">
        @foreach($rows as $row)
        @php
            $batch = $row->payoutBatch;
            $batchRoute = $batch && $batch->batch_type === 'monthly'
                ? route('admin.compensation.monthly-payouts.show', $batch)
                : ($batch ? route('admin.compensation.weekly-payouts.show', $batch) : null);
            $staleRetry = $row->status === 'retrying'
                && $row->retry_requested_at !== null
                && $row->retry_requested_at->lt(now()->subMinutes($staleMinutes));
            $approved = $batch?->approved_at !== null;
            $canRetry = ! $row->isResolved() && ! $approved && ($row->status === 'open' || $staleRetry);
            $nextBatch = $batch && $batch->batch_type === 'monthly' ? 'next month’s batch' : 'next week’s batch';

            if ($row->isResolved()) {
                // No line: by the time of the retry nothing was left to pay
                // (reversed, or already taken by a later batch). A later batch
                // "settles" rather than "pays": its line may be an income-cap
                // forfeit, which closes the wallet without a transfer.
                $badge = match (true) {
                    $row->resolution === 'built' && $row->resolved_line_item_id === null
                        => ['Resolved — nothing left to pay', 'bg-gray-100 text-gray-700'],
                    $row->resolution === 'built'
                        => ['Resolved — built into batch #'.$row->resolved_by_batch_id, 'bg-green-100 text-green-700'],
                    default => ['Resolved — settled by batch #'.$row->resolved_by_batch_id, 'bg-green-100 text-green-700'],
                };
            } elseif ($row->status === 'retrying' && ! $staleRetry) {
                $badge = ['Retrying…', 'bg-blue-100 text-blue-700'];
            } elseif ($approved) {
                $badge = ['Open — waiting for '.$nextBatch, 'bg-amber-100 text-amber-700'];
            } else {
                $badge = ['Open', 'bg-red-100 text-red-700'];
            }
        @endphp
        <div class="px-4 py-3 text-xs">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <a href="{{ route('admin.compensation.distributors.show', $row->distributor_id) }}"
                           class="font-mono font-medium text-brand-700 hover:underline">{{ $row->distributor?->adn ?? '—' }}</a>
                        <span class="text-gray-700">{{ $row->distributor?->user?->full_name ?? '—' }}</span>
                        @if($batchRoute)
                        <a href="{{ $batchRoute }}" class="text-gray-600 hover:underline">
                            {{ ucfirst($batch->batch_type) }} batch #{{ $batch->id }} · {{ $batch->batch_date->format('d M Y') }}
                        </a>
                        @endif
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $badge[1] }}">{{ $badge[0] }}</span>
                    </div>
                    <div class="text-gray-500">
                        First failed {{ $row->first_failed_at->format('d M Y, H:i') }} ·
                        last failed {{ $row->last_failed_at->format('d M Y, H:i') }} ·
                        {{ $row->attempts }} attempt(s)
                        @if($row->resolved_at) · resolved {{ $row->resolved_at->format('d M Y, H:i') }} @endif
                    </div>
                    <div class="font-mono text-gray-800 break-all">
                        <span class="text-red-700">{{ class_basename($row->exception_class) }}</span>:
                        {{ \Illuminate\Support\Str::limit(strtok($row->message, "\n") ?: $row->message, 240) }}
                    </div>
                </div>

                @if($canRetry)
                <form method="POST" action="{{ route('admin.compensation.payout-failures.retry', $row) }}"
                      data-confirm-title="Retry this distributor"
                      data-confirm="Rebuild {{ $row->distributor?->adn }}'s payout line in {{ $batch?->batch_type }} batch #{{ $batch?->id }}?"
                      data-confirm-impact="Impact: if it builds, the line is added to this batch for the same earning period and the batch totals change. If it fails again, the new error replaces this one. Fix the cause first.">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-amber-300 bg-amber-50 text-xs font-medium text-amber-800 hover:bg-amber-100">
                        <x-lucide-refresh-cw class="w-3.5 h-3.5" /> Retry
                    </button>
                </form>
                @endif
            </div>

            <details class="mt-2">
                <summary class="cursor-pointer text-brand-700 hover:underline select-none">Full error log</summary>
                <div class="mt-2 text-gray-500">
                    {{ $row->exception_class }} — PAN, Aadhaar, IFSC and long numbers are redacted before the error is stored.
                </div>
                <pre class="mt-1 max-h-[32rem] overflow-auto rounded-lg bg-gray-900 text-gray-100 p-3 text-[11px] leading-relaxed whitespace-pre-wrap break-all">{{ $row->error_detail }}</pre>
            </details>
        </div>
        @endforeach
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</x-ui.card>

@endsection
