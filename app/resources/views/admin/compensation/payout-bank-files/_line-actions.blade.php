{{--
    Manual controls that finish one payout line. Shared by the weekly and
    monthly batch pages. Expects: $batch, $line, $routeBase (e.g.
    'admin.compensation.weekly-payouts'), $isRazorpay, $canActOnLines, $rupees.
--}}
@php
    $adn = $line->distributor->adn ?? $line->distributor_id;
    $amount = $rupees($line->net_transferred_paise);
    $hasPayoutId = $line->razorpay_payout_id !== null && $line->razorpay_payout_id !== '';
    $summaryCls = 'list-none cursor-pointer inline-flex items-center gap-1 px-2 py-1 rounded border text-[11px] font-medium transition-colors';
    $panelCls = 'mt-1 w-56 whitespace-normal rounded-lg border border-gray-200 bg-white p-2 text-left';
    $inputCls = 'block w-full rounded border border-gray-300 px-2 py-1 text-xs';
@endphp

@if(! $canActOnLines)
    <span class="text-gray-400">—</span>
@elseif($line->status === 'pending' && $isRazorpay)
    @if($hasPayoutId)
    <form method="POST" action="{{ route($routeBase.'.line-items.check-gateway', [$batch, $line]) }}"
          data-confirm-title="Check with Razorpay"
          data-confirm="Ask Razorpay where the {{ $amount }} transfer to ADN {{ $adn }} stands?"
          data-confirm-impact="Impact: if Razorpay reports it paid or failed, the line is updated to match. If it is still in progress, nothing changes. No money moves.">
        @csrf
        <button type="submit" class="inline-flex items-center gap-1 px-2 py-1 rounded border border-blue-300 bg-blue-50 text-[11px] font-medium text-blue-800 hover:bg-blue-100 transition-colors">
            <x-lucide-search class="w-3 h-3" /> Check with Razorpay
        </button>
    </form>
    @else
    <span class="text-[11px] text-gray-500">Being sent to Razorpay</span>
    @endif
@elseif($line->status === 'pending')
    <div class="flex flex-wrap justify-center gap-1">
        <details>
            <summary class="{{ $summaryCls }} border-green-300 bg-green-50 text-green-800 hover:bg-green-100">
                <x-lucide-check class="w-3 h-3" /> Mark paid
            </summary>
            <form method="POST" action="{{ route($routeBase.'.line-items.mark-paid', [$batch, $line]) }}" class="{{ $panelCls }}"
                  data-confirm-title="Mark this payment paid"
                  data-confirm="Record that the bank paid {{ $amount }} to ADN {{ $adn }}?"
                  data-confirm-impact="Impact: the line is recorded as paid with this UTR and will not be in any later bank file. Do this only when the bank has confirmed the transfer.">
                @csrf
                <label class="block text-[11px] font-medium text-gray-700 mb-1">
                    Bank reference (UTR) <x-help-tip text="The Unique Transaction Reference the bank gave for this transfer. Letters and digits only. One UTR can settle only one line." />
                </label>
                <input type="text" name="utr" required maxlength="64" pattern="[A-Za-z0-9]+" class="{{ $inputCls }}" autocomplete="off">
                <x-ui.button class="mt-2 w-full justify-center">Mark paid</x-ui.button>
            </form>
        </details>
        <details>
            <summary class="{{ $summaryCls }} border-red-300 bg-red-50 text-red-800 hover:bg-red-100">
                <x-lucide-x class="w-3 h-3" /> Mark failed
            </summary>
            <form method="POST" action="{{ route($routeBase.'.line-items.mark-failed', [$batch, $line]) }}" class="{{ $panelCls }}"
                  data-confirm-title="Mark this payment failed"
                  data-confirm="Record that the bank did not pay {{ $amount }} to ADN {{ $adn }}?"
                  data-confirm-impact="Impact: the line is recorded as failed and leaves the bank file. The distributor is still owed — use Send again once the cause is fixed. No money moves.">
                @csrf
                <label class="block text-[11px] font-medium text-gray-700 mb-1">
                    Reason <x-help-tip text="The reason the bank gave, so the next person can see why the payment failed." />
                </label>
                <input type="text" name="reason" required maxlength="500" class="{{ $inputCls }}">
                <x-ui.button class="mt-2 w-full justify-center">Mark failed</x-ui.button>
            </form>
        </details>
    </div>
@elseif($line->status === 'failed' && $line->net_transferred_paise > 0)
    <div class="flex flex-wrap justify-center gap-1">
        <form method="POST" action="{{ route($routeBase.'.line-items.retry', [$batch, $line]) }}"
              data-confirm-title="Send this payment again"
              data-confirm="Send {{ $amount }} to ADN {{ $adn }} again?"
              data-confirm-impact="{{ $isRazorpay
                  ? 'Impact: Razorpay is asked to confirm the earlier transfer failed, then a new real bank transfer is sent. Fix the cause first — a wrong bank account fails again.'
                  : 'Impact: the line goes back to waiting and is included in the next bank file you download. Fix the cause first — a wrong bank account fails again.' }}">
            @csrf
            <button type="submit" class="inline-flex items-center gap-1 px-2 py-1 rounded border border-amber-300 bg-amber-50 text-[11px] font-medium text-amber-800 hover:bg-amber-100 transition-colors">
                <x-lucide-refresh-cw class="w-3 h-3" /> Send again
            </button>
        </form>
        @unless($isRazorpay)
        <details>
            <summary class="{{ $summaryCls }} border-green-300 bg-green-50 text-green-800 hover:bg-green-100">
                <x-lucide-check class="w-3 h-3" /> Mark paid
            </summary>
            <form method="POST" action="{{ route($routeBase.'.line-items.mark-paid', [$batch, $line]) }}" class="{{ $panelCls }}"
                  data-confirm-title="Mark this payment paid"
                  data-confirm="Record that the bank did pay {{ $amount }} to ADN {{ $adn }} after all?"
                  data-confirm-impact="Impact: the line is recorded as paid with this UTR. Use this only when the bank confirms the transfer went through despite the earlier failure.">
                @csrf
                <label class="block text-[11px] font-medium text-gray-700 mb-1">
                    Bank reference (UTR) <x-help-tip text="The Unique Transaction Reference the bank gave for this transfer. Letters and digits only. One UTR can settle only one line." />
                </label>
                <input type="text" name="utr" required maxlength="64" pattern="[A-Za-z0-9]+" class="{{ $inputCls }}" autocomplete="off">
                <x-ui.button class="mt-2 w-full justify-center">Mark paid</x-ui.button>
            </form>
        </details>
        @endunless
    </div>
@elseif($line->status === 'transferred' && ! $isRazorpay)
    <details class="inline-block">
        <summary class="{{ $summaryCls }} border-gray-300 bg-white text-gray-700 hover:bg-gray-50">
            <x-lucide-undo-2 class="w-3 h-3" /> Mark returned
        </summary>
        <form method="POST" action="{{ route($routeBase.'.line-items.mark-returned', [$batch, $line]) }}" class="{{ $panelCls }}"
              data-confirm-title="Mark this payment returned"
              data-confirm="Record that the bank sent back the {{ $amount }} paid to ADN {{ $adn }}?"
              data-confirm-impact="Impact: the line becomes failed and its UTR is cleared (it stays in the audit log). The distributor is owed again — use Send again once the cause is fixed. No money moves.">
            @csrf
            <label class="block text-[11px] font-medium text-gray-700 mb-1">
                Reason <x-help-tip text="Why the bank returned the money, for example 'Account closed'." />
            </label>
            <input type="text" name="reason" required maxlength="500" class="{{ $inputCls }}">
            <x-ui.button class="mt-2 w-full justify-center">Mark returned</x-ui.button>
        </form>
    </details>
@else
    <span class="text-gray-400">—</span>
@endif
