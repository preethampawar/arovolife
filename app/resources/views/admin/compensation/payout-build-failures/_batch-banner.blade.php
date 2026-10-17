{{-- Distributors this batch skipped because their line could not be built. --}}
@if($skipped['count'] > 0)
@php
    $skippedGross = '₹'.\App\Modules\Shared\Support\IndianNumber::format($skipped['gross_paise'] / 100, 2);
    $nextBatch = $batch->batch_type === 'monthly' ? 'next month’s batch' : 'next week’s batch';
@endphp
<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
    <strong>{{ $skipped['count'] }} distributor(s) could not be built into this batch</strong>
    ({{ $skippedGross }} of income). Everyone else is in it. Nothing was taken from their wallets.
    @if($batch->approved_at === null)
        Retry them once the cause is fixed and they are paid in this batch. If the batch is approved without them,
        {{ $nextBatch }} pays them.
    @else
        This batch was approved without them, so {{ $nextBatch }} pays them.
    @endif
    @can('finance.record')
    <a href="{{ route('admin.compensation.payout-failures.index', ['batch' => $batch->id]) }}" class="underline font-medium">See the errors and retry</a>
    @endcan
</div>
@endif
