@extends('admin.layouts.admin')
@section('title', 'Return '.$return->rma_no)
@section('heading', 'Return '.$return->rma_no)

@section('content')
@php
    $order = $return->order;
    $inspection = $return->inspection;
    $decision = $return->buybackDecision;
    $reasonLabel = match($return->reason) {
        'cooling_off'         => 'Cooling-off cancellation',
        'damage'              => 'Damage',
        'dissatisfaction'     => 'Dissatisfaction',
        'general_buyback'     => 'General buyback',
        'termination_buyback' => 'Termination buyback',
        default               => $return->reason,
    };
    $statusBadge = match($return->status) {
        'opened'   => 'bg-amber-50 text-amber-700 border-amber-200',
        'approved' => 'bg-green-50 text-green-700 border-green-200',
        'rejected' => 'bg-red-50 text-red-700 border-red-200',
        default    => 'bg-gray-50 text-gray-600 border-gray-200',
    };
@endphp

<div class="mb-4">
    <a href="{{ route('admin.returns.index') }}" class="text-sm text-gray-600 hover:text-gray-900">← Back to returns</a>
</div>

@foreach(['inspect','approve','reject'] as $field)
    @error($field)<div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ $message }}</div>@enderror
@endforeach

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Left: return + order info --}}
    <div class="lg:col-span-2 space-y-6">

        {{-- Return summary --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
            <div class="flex justify-between items-start mb-4">
                <div>
                    <h3 class="font-semibold text-gray-900">Return request</h3>
                    <p class="text-sm text-gray-600 font-mono mt-0.5">{{ $return->rma_no }}</p>
                </div>
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border {{ $statusBadge }}">
                    {{ ucfirst($return->status) }}
                </span>
            </div>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-xs text-gray-600 uppercase font-medium">Order</dt>
                    <dd><a href="{{ route('admin.commerce.orders.show', $order) }}" class="text-brand-700 hover:text-brand-800 font-mono">{{ $order->order_no }}</a></dd></div>
                <div><dt class="text-xs text-gray-600 uppercase font-medium">Customer</dt>
                    <dd class="text-gray-900">{{ $return->openedByCustomer?->display_name ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-600 uppercase font-medium">Reason</dt>
                    <dd class="text-gray-900">{{ $reasonLabel }}</dd></div>
                <div><dt class="text-xs text-gray-600 uppercase font-medium">Opened</dt>
                    <dd class="text-gray-900">{{ $return->created_at->format('d M Y H:i') }}</dd></div>
                @if($return->notes)
                <div class="col-span-2"><dt class="text-xs text-gray-600 uppercase font-medium">Customer notes</dt>
                    <dd class="text-gray-700 mt-0.5">{{ $return->notes }}</dd></div>
                @endif
            </dl>
        </div>

        {{-- Order items --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
            <h3 class="font-semibold text-gray-900 mb-3">Order items ({{ $order->order_no }})</h3>
            <table class="w-full text-sm">
                <thead><tr class="border-b border-gray-100">
                    <th class="text-left py-2 text-xs font-medium text-gray-600 uppercase">Product</th>
                    <th class="text-right py-2 text-xs font-medium text-gray-600 uppercase">Qty</th>
                    <th class="text-right py-2 text-xs font-medium text-gray-600 uppercase">Total</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($order->items as $it)
                    <tr>
                        <td class="py-2">
                            <p class="text-gray-900 font-medium">{{ $it->product_name_snapshot }}</p>
                            <p class="text-xs text-gray-600 font-mono">{{ $it->variant_sku_snapshot }}</p>
                        </td>
                        <td class="py-2 text-right">{{ $it->qty }}</td>
                        <td class="py-2 text-right font-semibold">₹{{ \App\Modules\Shared\Support\IndianNumber::format($it->line_total_paise / 100, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-3 pt-3 border-t border-gray-100 flex flex-col items-end text-sm space-y-1">
                <div class="flex gap-8"><span class="text-gray-600">Subtotal (incl. GST)</span><span class="w-28 text-right">₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->subtotal_paise / 100, 2) }}</span></div>
                @if($order->discount_paise > 0)
                <div class="flex gap-8 text-green-700"><span>Discount</span><span class="w-28 text-right">−₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->discount_paise / 100, 2) }}</span></div>
                @endif
                <div class="flex gap-8"><span class="text-gray-600">Shipping</span><span class="w-28 text-right">@if($order->shipping_paise > 0)₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->shipping_paise / 100, 2) }}@else Free @endif</span></div>
                <div class="flex gap-8 font-semibold pt-1 border-t border-gray-100 mt-1"><span>Total paid</span><span class="w-28 text-right">{{ $order->displayTotal() }}</span></div>
            </div>
        </div>

        {{-- Inspection record (admin-reviewed reasons only) --}}
        @if(! $return->isCoolingOff())
        <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
            <h3 class="font-semibold text-gray-900 mb-4">Inspection</h3>
            @if($inspection)
            <dl class="grid grid-cols-2 gap-3 text-sm mb-3">
                <div><dt class="text-xs text-gray-600 uppercase font-medium">Condition</dt>
                    <dd class="font-medium text-gray-900">{{ ucfirst(str_replace('_', ' ', $inspection->condition)) }}</dd></div>
                <div><dt class="text-xs text-gray-600 uppercase font-medium">Inspected at</dt>
                    <dd class="text-gray-900">{{ $inspection->received_at->format('d M Y H:i') }}</dd></div>
                @if($inspection->notes)<div class="col-span-2"><dt class="text-xs text-gray-600 uppercase font-medium">Notes</dt><dd class="text-gray-700">{{ $inspection->notes }}</dd></div>@endif
            </dl>
            @else
            @if(in_array($return->status, ['opened'], true) && $order->status === 'refund_requested')
            <form method="POST" action="{{ route('admin.returns.inspect', $return) }}"
                data-confirm="Record inspection result?"
                data-confirm-title="Record inspection"
                data-confirm-impact="This records the physical condition of the returned goods and computes the refund amount. The order will move to 'refund_inspection'. You can approve or reject after this step.">
                @csrf
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Physical condition <span class="text-red-500">*</span> <x-help-tip text="The condition of the returned goods on inspection; this determines the refund amount under the T&C §8 buyback matrix." /></label>
                    <select name="condition" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                        <option value="">Select condition…</option>
                        <option value="saleable">Saleable (can be resold as new/like-new)</option>
                        <option value="non_saleable">Non-saleable (opened/used, cannot be resold)</option>
                        <option value="damaged">Damaged (broken/defective)</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Inspector notes (optional) <x-help-tip text="Optional notes about the inspection; saved with the inspection record." /></label>
                    <textarea name="notes" rows="2" maxlength="1000" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"></textarea>
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium">Record inspection</button>
            </form>
            @else
            <p class="text-sm text-gray-600">No inspection recorded yet.</p>
            @endif
            @endif
        </div>
        @endif

    </div>

    {{-- Right: refund computation + actions --}}
    <div class="space-y-6">

        {{-- Refund computation --}}
        @if($decision)
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <h3 class="font-semibold text-gray-900 mb-3">Computed refund (T&amp;C §8 matrix)</h3>
            <dl class="text-sm space-y-1.5">
                <div class="flex justify-between"><dt class="text-gray-600">Base (ex-GST)</dt><dd>₹{{ \App\Modules\Shared\Support\IndianNumber::format($decision->refund_base_paise / 100, 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-600">GST refund</dt><dd>₹{{ \App\Modules\Shared\Support\IndianNumber::format($decision->gst_adjustment_paise / 100, 2) }}</dd></div>
                @if($decision->admin_deduction_paise > 0)
                <div class="flex justify-between text-red-700"><dt>Admin deduction</dt><dd>−₹{{ \App\Modules\Shared\Support\IndianNumber::format($decision->admin_deduction_paise / 100, 2) }}</dd></div>
                @endif
                <div class="flex justify-between font-semibold border-t border-gray-100 pt-1.5 mt-1"><dt>Net refund</dt><dd>₹{{ \App\Modules\Shared\Support\IndianNumber::format($decision->net_refund_paise / 100, 2) }}</dd></div>
            </dl>
            <p class="text-xs text-gray-600 mt-3">Matrix version {{ $decision->decision_matrix_version }} · ADR-0009 §8</p>
        </div>
        @elseif($return->isCoolingOff())
        <div class="bg-blue-50 rounded-2xl border border-blue-200 p-5">
            <p class="text-sm text-blue-800 font-medium">Full refund</p>
            <p class="text-xl font-bold text-blue-900 mt-1">₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->total_paise / 100, 2) }} <span class="text-sm font-medium">in cash</span></p>
            <p class="text-xs text-blue-700 mt-1">Cooling-off: everything the buyer paid comes back in the form it was paid — cash to the original payment method, points as points, repurchase credit as credit (hard rule #5; R-60).</p>
        </div>
        @endif

        {{-- Approve / Reject actions (non-cooling-off, after inspection) --}}
        @if(! $return->isCoolingOff() && $return->status === 'opened' && $order->status === 'refund_inspection' && $decision)
        @can('finance.record')
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm space-y-3">
            <h3 class="font-semibold text-gray-900">Decision</h3>
            <form method="POST" action="{{ route('admin.returns.approve', $return) }}"
                data-confirm="Approve this refund?"
                data-confirm-title="Approve refund"
                data-confirm-impact="Impact: posts ledger reversal (Dr revenue.sales/GST Cr liability.refund_payable), reverses BV accrual, moves order to refund_approved. The refund is sent to Razorpay automatically and settled when the gateway confirms it. This cannot be undone from here.">
                @csrf
                <button type="submit" class="w-full py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-semibold">Approve refund ₹{{ \App\Modules\Shared\Support\IndianNumber::format($decision->net_refund_paise / 100, 2) }}</button>
            </form>
            <form method="POST" action="{{ route('admin.returns.reject', $return) }}"
                data-confirm="Reject this return request?"
                data-confirm-title="Reject return"
                data-confirm-impact="Impact: marks the return rejected and reverts the order to delivered status. The customer retains any remaining cooling-off days. No money moves.">
                @csrf
                <button type="submit" class="w-full py-2 rounded-lg bg-white hover:bg-red-50 border border-red-300 text-red-600 text-sm font-medium">Reject return</button>
            </form>
        </div>
        @else
        @if(! $return->isCoolingOff() && $return->status === 'opened' && $order->status === 'refund_inspection' && $decision)
        <p class="text-sm text-gray-600 bg-gray-50 rounded-2xl border border-gray-200 p-4">You need the <strong>finance.record</strong> permission to approve or reject returns.</p>
        @endif
        @endcan
        @endif

        @if(session('status'))<div class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800">{{ session('status') }}</div>@endif
        @error('receive')<div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ $message }}</div>@enderror

        {{-- Cooling-off: auto-processed; the refund waits for the goods --}}
        @if($return->isCoolingOff() && in_array($return->status, ['approved', 'refunded', 'rejected'], true))
        <div class="rounded-2xl border border-green-200 bg-green-50 p-5">
            <p class="text-sm font-semibold text-green-800">Cancellation recorded (cooling-off, non-discretionary).</p>
            <p class="text-xs text-green-700 mt-1">Ledger reversed. BV reversed. Cooling-off clock closed. Customer notified.</p>
        </div>

        @if($return->isAwaitingReceipt())
        <div class="bg-white rounded-2xl border border-amber-200 p-5 shadow-sm space-y-3">
            <h3 class="font-semibold text-gray-900">Return receipt</h3>
            <p class="text-xs text-gray-600">Held since {{ $return->entitlements_held_at->format('d M Y') }} ({{ (int) $return->entitlements_held_at->diffInDays(now()) }} days). The cash refund
                @if($return->entitlement_points_paise > 0 || $return->entitlement_credit_paise > 0), the redeemed points (₹{{ \App\Modules\Shared\Support\IndianNumber::format($return->entitlement_points_paise / 100, 2) }}) and the repurchase credit (₹{{ \App\Modules\Shared\Support\IndianNumber::format($return->entitlement_credit_paise / 100, 2) }})@endif
                go back to the buyer together the moment the return is marked received (T&amp;C §8).
                @if($return->hold_escalated_at)<span class="block mt-1 text-red-700 font-medium">Escalated to the Grievance Officer on {{ $return->hold_escalated_at->format('d M Y') }}.</span>@elseif($return->hold_alert_sent_at)<span class="block mt-1 text-amber-700 font-medium">Alerted on {{ $return->hold_alert_sent_at->format('d M Y') }} — please chase the return.</span>@endif
            </p>
            @can('returns.receive')
            <form method="POST" action="{{ route('admin.returns.receive', $return) }}"
                data-confirm="Mark this return as received?" data-confirm-title="Return received"
                data-confirm-impact="Impact: restores the buyer's points and repurchase credit and releases the cash refund to the gateway — all three together. Only do this with the goods in hand (or if our courier lost them). Audit-logged against your user.">
                @csrf
                <label class="block text-sm font-medium text-gray-700 mb-1">Outcome <x-help-tip text="Received: the goods are back. Lost by our courier: the reverse pickup was collected but never arrived — treated as received." /></label>
                <select name="outcome" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm mb-2">
                    <option value="received">Received — goods are back</option>
                    <option value="courier_lost">Lost by our courier — treat as received</option>
                </select>
                <input type="text" name="note" maxlength="500" placeholder="Note (optional)" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm mb-3">
                <button type="submit" class="w-full py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-semibold">Mark received &amp; release refund</button>
            </form>
            <form method="POST" action="{{ route('admin.returns.not-returned', $return) }}" class="pt-3 border-t border-gray-100"
                data-confirm="Close this return as not returned?" data-confirm-title="Not returned"
                data-confirm-impact="Impact: the refund is forfeited, the points and repurchase credit stay withheld, and the order goes back to delivered. This is the buyer's money — only after the buyer has been told and given a reasonable chance to send the goods. Audit-logged against your user.">
                @csrf
                <input type="text" name="reason" required minlength="10" maxlength="500" placeholder="Reason (required, min 10 characters)" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm mb-2">
                <button type="submit" class="w-full py-2 rounded-lg bg-white hover:bg-red-50 border border-red-300 text-red-600 text-sm font-medium">Close as not returned</button>
            </form>
            @else
            <p class="text-sm text-gray-600 bg-gray-50 rounded-lg p-3">You need the <strong>returns.receive</strong> permission (operations) to mark a return received.</p>
            @endcan
        </div>
        @elseif($return->receipt_outcome !== null)
        <div class="rounded-2xl border {{ $return->receipt_outcome === 'not_returned' ? 'border-red-200 bg-red-50' : 'border-blue-200 bg-blue-50' }} p-5 text-sm">
            <p class="font-semibold {{ $return->receipt_outcome === 'not_returned' ? 'text-red-800' : 'text-blue-800' }}">{{ match($return->receipt_outcome) { 'received' => 'Return received', 'courier_lost' => 'Lost by our courier — treated as received', default => 'Closed as not returned' } }}</p>
            <p class="text-xs mt-1 {{ $return->receipt_outcome === 'not_returned' ? 'text-red-700' : 'text-blue-700' }}">{{ $return->received_at?->format('d M Y H:i') ?? $return->updated_at->format('d M Y H:i') }}@if($return->receipt_note) · {{ $return->receipt_note }}@endif</p>
        </div>
        @endif
        @endif

    </div>

</div>

@endsection
