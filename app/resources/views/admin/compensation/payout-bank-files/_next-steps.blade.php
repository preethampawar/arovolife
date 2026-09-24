{{--
    "What to do next" for an approved payout batch, from its live line counts.
    Expects: $batch, $isRazorpay, $countOf (status => count), $bankFiles.
--}}
@php
    $pendingCount = $countOf('pending');
    $failedCount = $countOf('failed');
    $alreadySent = (int) ($bankFiles['pending_already_sent'] ?? 0);
    $steps = [];

    if ($failedCount > 0) {
        $steps[] = $failedCount.' payment(s) failed. Fix the cause first — usually the distributor\'s bank details — then click Send again on each line, or Send all failed again.'
            .($isRazorpay ? '' : ' They go back into the next bank file you download.');
    }

    if ($pendingCount > 0 && $isRazorpay) {
        $steps[] = $pendingCount.' payment(s) are with Razorpay. Each one is marked paid when Razorpay confirms it. If one has waited too long, use Check with Razorpay on its line.';
    } elseif ($pendingCount > 0) {
        $steps[] = $pendingCount.' payment(s) are waiting for the bank. Download the bank file (it holds only the unpaid lines), upload it to the bank, then import the bank\'s response file below. If the bank tells you about a payment another way, use Mark paid or Mark failed on its line.';

        if ($alreadySent > 0) {
            $steps[] = $alreadySent.' of them are already in a bank file you downloaded. Upload a new file only if the bank did not process that one, or those distributors are paid twice.';
        }
    }
@endphp

@if($batch->approved_at !== null)
<div class="mb-4 rounded-lg border {{ $steps === [] ? 'border-green-200 bg-green-50 text-green-800' : 'border-blue-200 bg-blue-50 text-blue-900' }} px-4 py-3 text-sm">
    <p class="font-semibold flex items-center gap-1">
        @if($steps === [])
            <x-lucide-circle-check class="w-4 h-4" /> Every payment in this batch is complete.
        @else
            <x-lucide-list-checks class="w-4 h-4" /> What to do next
        @endif
    </p>
    @if($steps !== [])
    <ul class="mt-1 list-disc pl-5 space-y-0.5">
        @foreach($steps as $step)
        <li>{{ $step }}</li>
        @endforeach
    </ul>
    @endif
</div>
@endif
