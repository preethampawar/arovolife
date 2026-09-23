{{-- Offline payment card on the admin order page. Expects $order, $offlinePayment, $offlineOrdersOn. --}}
@php
    use App\Modules\Shared\Support\IndianNumber;

    $state = $offlinePayment->displayState();
    [$stateLabel, $stateTone] = match ($state) {
        'confirmed' => ['Confirmed', 'success'],
        'rejected' => ['Rejected', 'danger'],
        'cancelled' => ['Not confirmed — order cancelled', 'neutral'],
        default => ['Awaiting finance confirmation', 'warning'],
    };
    $row = 'flex justify-between gap-3 text-xs';
    $canDecide = $offlineOrdersOn && $state === 'pending' && $order->isAwaitingOfflineConfirmation();
@endphp

<x-ui.card padding="p-5">
    <div class="flex items-center justify-between gap-2 mb-3">
        <p class="text-xs uppercase tracking-wider text-gray-600">Payment</p>
        <x-ui.badge tone="warning">Offline</x-ui.badge>
    </div>
    <x-ui.badge :tone="$stateTone" dot>{{ $stateLabel }}</x-ui.badge>

    <dl class="mt-4 space-y-1.5">
        <div class="{{ $row }}"><dt class="text-gray-600">Channel</dt><dd class="text-gray-900 text-right">{{ $offlinePayment->channelLabel() }}</dd></div>
        <div class="{{ $row }}"><dt class="text-gray-600">Amount</dt><dd class="text-gray-900 font-medium tabular-nums">{{ IndianNumber::rupees($offlinePayment->amount_paise) }}</dd></div>
        <div class="{{ $row }}"><dt class="text-gray-600">Received on</dt><dd class="text-gray-900">{{ $offlinePayment->received_on->format('d M Y') }}</dd></div>
        <div class="{{ $row }}"><dt class="text-gray-600">Reference</dt><dd class="text-gray-900 font-mono break-all text-right">{{ $offlinePayment->reference_no ?? '—' }}</dd></div>
        @if($offlinePayment->payer_name)
        <div class="{{ $row }}"><dt class="text-gray-600">Paid by</dt><dd class="text-gray-900 text-right">{{ $offlinePayment->payer_name }}</dd></div>
        @endif
        <div class="{{ $row }}"><dt class="text-gray-600">Recorded by</dt><dd class="text-gray-900 text-right">{{ $offlinePayment->recordedBy->full_name ?? '—' }}<br><span class="text-gray-600">{{ $offlinePayment->created_at->format('d M Y H:i') }}</span></dd></div>
        @if($offlinePayment->confirmed_at)
        <div class="{{ $row }}"><dt class="text-gray-600">Confirmed by</dt><dd class="text-gray-900 text-right">{{ $offlinePayment->confirmedBy->full_name ?? '—' }}<br><span class="text-gray-600">{{ $offlinePayment->confirmed_at->format('d M Y H:i') }}</span></dd></div>
        @endif
        @if($offlinePayment->rejected_at)
        <div class="{{ $row }}"><dt class="text-gray-600">Rejected by</dt><dd class="text-gray-900 text-right">{{ $offlinePayment->rejectedBy->full_name ?? '—' }}<br><span class="text-gray-600">{{ $offlinePayment->rejected_at->format('d M Y H:i') }}</span></dd></div>
        @endif
    </dl>

    @if($offlinePayment->notes)
    <p class="mt-3 text-xs text-gray-700"><span class="text-gray-600">Notes:</span> {{ $offlinePayment->notes }}</p>
    @endif
    @if($offlinePayment->confirmation_note)
    <p class="mt-2 text-xs text-gray-700"><span class="text-gray-600">Confirmation note:</span> {{ $offlinePayment->confirmation_note }}</p>
    @endif
    @if($offlinePayment->rejection_reason)
    <p class="mt-2 text-xs text-red-700"><span class="text-gray-600">Rejection reason:</span> {{ $offlinePayment->rejection_reason }}</p>
    @endif

    @if($offlinePayment->hasProof() && $offlineOrdersOn && auth()->user()?->canAny(['finance.record', 'commerce.order.manage']))
    <a href="{{ route('admin.commerce.offline-orders.proof', $order) }}" target="_blank" rel="noopener"
       class="inline-flex items-center gap-1 mt-3 text-sm font-medium text-brand-700 hover:text-brand-800">
        <x-lucide-paperclip class="w-4 h-4" />
        View proof
    </a>
    @elseif($offlinePayment->proof_purged_at)
    <p class="mt-3 text-xs text-gray-600">Proof deleted {{ $offlinePayment->proof_purged_at->format('d M Y') }} (retention period ended).</p>
    @endif

    @error('offline')<p class="mt-3 text-sm text-red-700">{{ $message }}</p>@enderror

    @if($canDecide)
    @can('finance.record')
    <form method="POST" action="{{ route('admin.commerce.offline-orders.confirm', $order) }}" class="mt-4 pt-4 border-t border-gray-100 space-y-2"
          data-confirm="Confirm this payment?"
          data-confirm-title="Confirm offline payment"
          data-confirm-impact="Impact: marks the order PAID. BV is recorded for the buyer and passed up their Genos, the GST invoice is issued and the buyer is emailed — exactly as for a paid shop order. It cannot be undone; a mistake afterwards is a cancellation and refund.">
        @csrf
        <label class="flex items-start gap-2 text-xs text-gray-800">
            <input type="checkbox" name="verified" value="1" class="mt-0.5 rounded border-gray-300 text-brand-700 focus:ring-brand-500">
            <span>I have checked this money has been received ({{ IndianNumber::rupees($offlinePayment->amount_paise) }}, {{ $offlinePayment->channelLabel() }}).</span>
        </label>
        @error('verified')<p class="text-xs text-red-700">{{ $message }}</p>@enderror
        <label for="confirm-note" class="sr-only">Confirmation note</label>
        <input id="confirm-note" name="note" maxlength="500" placeholder="Note (optional), e.g. matched on 23 Sep statement"
               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
        <button type="submit" class="w-full py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium">Confirm payment</button>
    </form>

    <form method="POST" action="{{ route('admin.commerce.offline-orders.reject', $order) }}" class="mt-4 pt-4 border-t border-gray-100 space-y-2"
          data-confirm="Reject this payment and cancel the order?"
          data-confirm-title="Reject offline payment"
          data-confirm-impact="Impact: marks the payment REJECTED and CANCELS the order, releasing the stock. Nothing was counted, so nothing is reversed. Use this only when no money was received.">
        @csrf
        <p class="text-xs text-gray-600">If the money <strong>was</strong> received but the order must be undone, confirm the payment first and then cancel the order — the refund is then owed on the books and appears in Payments → Refunds.</p>
        <label class="flex items-start gap-2 text-xs text-gray-800">
            <input type="checkbox" name="money_not_received" value="1" class="mt-0.5 rounded border-gray-300 text-red-600 focus:ring-red-500">
            <span>No money was received for this order (the deposit did not arrive, or the entry was a mistake).</span>
        </label>
        @error('money_not_received')<p class="text-xs text-red-700">{{ $message }}</p>@enderror
        <label for="reject-reason" class="sr-only">Reason</label>
        <textarea id="reject-reason" name="reason" rows="2" maxlength="500" placeholder="Reason (required)"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-brand-500"></textarea>
        @error('reason')<p class="text-xs text-red-700">{{ $message }}</p>@enderror
        <button type="submit" class="w-full py-2 rounded-lg border border-red-300 text-red-700 hover:bg-red-50 text-sm font-medium">Reject payment</button>
    </form>
    @endcan
    @endif
</x-ui.card>
