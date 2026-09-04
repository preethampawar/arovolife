@extends('admin.layouts.admin')
@section('title', 'Unsettled refunds')
@section('heading', 'Unsettled refunds')

@section('content')

@if(session('status'))<div class="mb-4 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800">{{ session('status') }}</div>@endif
@error('refund')<div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ $message }}</div>@enderror
@error('reference')<div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ $message }}</div>@enderror

<p class="text-sm text-gray-600 mb-5">Every refund owed and not yet in the buyer's hands. A refund is settled only when Razorpay confirms it processed, or when finance records a manual NEFT here. Two clocks: <strong>cancellation → receipt</strong> (ours to chase — alert at {{ \App\Modules\Payments\Support\RefundWorklist::ALERT_AFTER_DAYS }} days, Grievance Officer at {{ \App\Modules\Payments\Support\RefundWorklist::ESCALATE_AFTER_DAYS }}) and <strong>receipt → credited</strong> (the published {{ \App\Modules\Payments\Support\RefundWorklist::PROMISE_BUSINESS_DAYS }} working days).</p>

<div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-x-auto mb-8">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Order</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Customer</th>
                <th class="text-right px-4 py-3 text-xs font-medium text-gray-600 uppercase">Amount</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Reason</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">State</th>
                <th class="text-right px-4 py-3 text-xs font-medium text-gray-600 uppercase">Days</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Gateway</th>
                <th></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($refunds as $refund)
            @php $c = $classify($refund); @endphp
            <tr class="{{ $c['escalated'] ? 'bg-red-50' : ($c['overdue'] ? 'bg-amber-50' : 'hover:bg-gray-50') }}">
                <td class="px-4 py-3"><a href="{{ route('admin.commerce.orders.show', $refund->order) }}" class="text-brand-700 font-mono text-xs">{{ $refund->order->order_no }}</a></td>
                <td class="px-4 py-3 text-gray-700">{{ $refund->order->customer->display_name ?? '—' }}</td>
                <td class="px-4 py-3 text-right font-semibold">₹{{ \App\Modules\Shared\Support\IndianNumber::format($refund->amount_paise / 100, 2) }}</td>
                <td class="px-4 py-3 text-gray-700">{{ str_replace('_', ' ', $refund->reason_code) }}</td>
                <td class="px-4 py-3">
                    <span class="font-medium {{ $c['overdue'] ? 'text-red-700' : 'text-gray-800' }}">{{ $c['label'] }}</span>
                    @if($refund->error_code)<span class="block text-xs text-red-700">{{ $refund->error_code }} — {{ $refund->error_description }}</span>@endif
                </td>
                <td class="px-4 py-3 text-right {{ $c['overdue'] ? 'text-red-700 font-semibold' : 'text-gray-700' }}">{{ $c['days'] }}</td>
                <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ $refund->gateway_refund_id ?? '—' }}</td>
                <td class="px-4 py-3 text-right">
                    @can('finance.record')
                    @if($refund->status === 'failed' || ($c['state'] === 'queued'))
                    <form method="POST" action="{{ route('admin.payments.refunds.retry', $refund) }}" class="inline"
                          data-confirm="Re-queue this refund for the gateway?" data-confirm-title="Retry refund"
                          data-confirm-impact="Impact: the SAME refund is sent to Razorpay again (idempotent — it cannot create a duplicate). Money moves if the gateway accepts it.">
                        @csrf<button type="submit" class="text-sm text-brand-700 hover:underline">Retry</button>
                    </form>
                    <details class="inline-block ml-2 text-left">
                        <summary class="text-sm text-gray-700 cursor-pointer hover:underline">Settle by NEFT</summary>
                        <form method="POST" action="{{ route('admin.payments.refunds.settle', $refund) }}" class="mt-2 space-y-2 w-64"
                              data-confirm="Record a manual NEFT settlement?" data-confirm-title="Manual settlement"
                              data-confirm-impact="Impact: discharges the refund payable against the settlement bank account and marks the order refunded. Only do this after the transfer has actually been made. Audit-logged against your user.">
                            @csrf
                            <input type="text" name="reference" required minlength="6" maxlength="64" placeholder="NEFT / UTR reference" class="w-full rounded-lg border-gray-300 text-sm">
                            <input type="text" name="note" maxlength="500" placeholder="Note (optional)" class="w-full rounded-lg border-gray-300 text-sm">
                            <button type="submit" class="w-full py-1.5 rounded-lg bg-gray-800 text-white text-sm">Record settlement</button>
                        </form>
                    </details>
                    @elseif($c['state'] === 'held')
                    <span class="text-xs text-gray-600">Release from the return</span>
                    @endif
                    @endcan
                </td>
            </tr>
            @empty
            <tr><td colspan="8" class="px-4 py-8 text-center text-sm text-gray-600">No unsettled refunds.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<h2 class="font-semibold text-gray-900 mb-3">Refunds owed outside the gateway</h2>
<p class="text-xs text-gray-600 mb-3">Approved refunds on orders with no gateway payment to refund against — cash on delivery, or a payment recorded outside the platform. The obligation is in the ledger; the only discharge is the NEFT finance makes, recorded here with its UTR. The same 7-working-day promise applies.</p>
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-x-auto mb-8">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Order</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Customer</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Paid by</th>
                <th class="text-right px-4 py-3 text-xs font-medium text-gray-600 uppercase">Owed</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Approved</th>
                <th class="text-right px-4 py-3 text-xs font-medium text-gray-600 uppercase">Days</th>
                <th></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($manualRefunds as $manualOrder)
            @php $owedPaise = $owed($manualOrder); $manualDays = (int) ($manualOrder->refund_approved_at ?? $manualOrder->updated_at)->diffInWeekdays(now()); @endphp
            <tr class="{{ $manualDays >= \App\Modules\Payments\Support\RefundWorklist::PROMISE_BUSINESS_DAYS ? 'bg-amber-50' : 'hover:bg-gray-50' }}">
                <td class="px-4 py-3"><a href="{{ route('admin.commerce.orders.show', $manualOrder) }}" class="text-brand-700 font-mono text-xs">{{ $manualOrder->order_no }}</a></td>
                <td class="px-4 py-3 text-gray-700">{{ $manualOrder->customer->display_name ?? '—' }}</td>
                <td class="px-4 py-3 text-gray-700">{{ str_replace('_', ' ', $manualOrder->payment_method) }}</td>
                <td class="px-4 py-3 text-right font-semibold">₹{{ \App\Modules\Shared\Support\IndianNumber::format($owedPaise / 100, 2) }}</td>
                <td class="px-4 py-3 text-gray-600 text-xs">{{ $manualOrder->refund_approved_at?->format('d M Y') ?? '—' }}</td>
                <td class="px-4 py-3 text-right {{ $manualDays >= \App\Modules\Payments\Support\RefundWorklist::PROMISE_BUSINESS_DAYS ? 'text-red-700 font-semibold' : 'text-gray-700' }}">{{ $manualDays }}</td>
                <td class="px-4 py-3 text-right">
                    @can('finance.record')
                    <details class="inline-block text-left">
                        <summary class="text-sm text-gray-700 cursor-pointer hover:underline">{{ $owedPaise > 0 ? 'Settle by NEFT' : 'Close as refunded' }}</summary>
                        <form method="POST" action="{{ route('admin.payments.orders.settle', $manualOrder) }}" class="mt-2 space-y-2 w-64"
                              data-confirm="{{ $owedPaise > 0 ? 'Record a manual NEFT settlement?' : 'Close this refund with nothing owed in cash?' }}" data-confirm-title="Manual settlement"
                              data-confirm-impact="Impact: {{ $owedPaise > 0 ? 'discharges the refund payable against the settlement bank account and' : 'no cash was owed (settled in points or credit);' }} marks the order refunded. Only do this after any transfer has actually been made. Audit-logged against your user.">
                            @csrf
                            <input type="text" name="reference" required minlength="6" maxlength="64" placeholder="{{ $owedPaise > 0 ? 'NEFT / UTR reference' : 'Reference or reason' }}" class="w-full rounded-lg border-gray-300 text-sm">
                            <input type="text" name="note" maxlength="500" placeholder="Note (optional)" class="w-full rounded-lg border-gray-300 text-sm">
                            <button type="submit" class="w-full py-1.5 rounded-lg bg-gray-800 text-white text-sm">Record settlement</button>
                        </form>
                    </details>
                    @endcan
                </td>
            </tr>
            @empty
            <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-gray-600">None owed outside the gateway.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<h2 class="font-semibold text-gray-900 mb-3">Cooling-off returns awaiting receipt</h2>
<p class="text-xs text-gray-600 mb-3">The buyer has cancelled; the goods are not yet marked received. Points, repurchase credit and any cash refund are all held until they are. Mark receipt from the return itself (Admin → Returns).</p>
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">RMA</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Order</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Customer</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Cancelled</th>
                <th class="text-right px-4 py-3 text-xs font-medium text-gray-600 uppercase">Days</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Clock</th>
                <th></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($awaitingReceipt as $rq)
            @php $days = (int) $rq->entitlements_held_at->diffInDays(now()); @endphp
            <tr class="{{ $rq->hold_escalated_at ? 'bg-red-50' : ($rq->hold_alert_sent_at ? 'bg-amber-50' : 'hover:bg-gray-50') }}">
                <td class="px-4 py-3 font-mono text-xs">{{ $rq->rma_no }}</td>
                <td class="px-4 py-3"><a href="{{ route('admin.commerce.orders.show', $rq->order) }}" class="text-brand-700 font-mono text-xs">{{ $rq->order->order_no }}</a></td>
                <td class="px-4 py-3 text-gray-700">{{ $rq->order->customer->display_name ?? '—' }}</td>
                <td class="px-4 py-3 text-gray-600 text-xs">{{ $rq->entitlements_held_at->format('d M Y') }}</td>
                <td class="px-4 py-3 text-right {{ $days >= \App\Modules\Payments\Support\RefundWorklist::ALERT_AFTER_DAYS ? 'text-red-700 font-semibold' : 'text-gray-700' }}">{{ $days }}</td>
                <td class="px-4 py-3 text-xs">@if($rq->hold_escalated_at)<span class="text-red-700">Escalated to Grievance Officer</span>@elseif($rq->hold_alert_sent_at)<span class="text-amber-700">Alerted</span>@else<span class="text-gray-600">Running</span>@endif</td>
                <td class="px-4 py-3 text-right"><a href="{{ route('admin.returns.show', $rq) }}" class="text-sm text-brand-700 hover:underline">Open return →</a></td>
            </tr>
            @empty
            <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-gray-600">None waiting.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@endsection
