@extends('layouts.app')
@section('title', 'Return order '.$order->order_no)

@section('content')
<div>
    <a href="{{ route('orders.show', $order->order_no) }}" class="text-sm text-brand-700 hover:text-brand-800">← Back to order</a>

    <h1 class="text-2xl font-bold text-gray-900 mt-3 mb-2">Return order <span class="font-mono text-brand-700">{{ $order->order_no }}</span></h1>

    {{-- Context note --}}
    <p class="text-sm text-gray-600 mb-6">
        Use this form to request a return or refund. Cooling-off cancellations (within 30 days of delivery) are processed immediately. Other reasons go to our review team.
    </p>

    @if($errors->any())
    <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
        @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
    </div>
    @endif

    {{-- Cooling-off banner --}}
    @if($coolingOff && $coolingOff->status === 'open' && $coolingOff->ends_at->isFuture())
    <div class="mb-5 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm">
        <p class="font-semibold text-blue-800">Your 30-day cooling-off window is open.</p>
        <p class="text-blue-700 mt-1">
            You have {{ $coolingOff->daysRemaining() }} day{{ $coolingOff->daysRemaining() === 1 ? '' : 's' }} remaining (window closes {{ $coolingOff->ends_at->format('d M Y') }}).
            Selecting <strong>Cooling-off cancellation</strong> below will process your refund immediately — no further steps required.
        </p>
    </div>
    @endif

    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <form method="POST" action="{{ route('orders.return.store', $order->order_no) }}"
              data-confirm="Submit this return request?"
              data-confirm-title="Confirm return request"
              data-confirm-impact="Once submitted, this return request is logged and (for cooling-off reasons) your refund is processed immediately. Make sure the reason is correct before confirming.">
            @csrf

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 mb-1">Return reason <span class="text-red-500">*</span></label>
                <select name="reason" required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="">Select a reason…</option>
                    @foreach($reasons as $r)
                    <option value="{{ $r['reason'] }}" {{ old('reason') === $r['reason'] ? 'selected' : '' }}>
                        {{ $r['label'] }}@if($r['window_days'] !== null) (within {{ $r['window_days'] }} days of delivery)@else (unused, saleable goods only)@endif
                    </option>
                    @endforeach
                </select>
                @error('reason')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            {{-- Buy-back disclosure (DSR 2021). The refund is NOT the same for
                 every reason: GST comes back only on some of them, and shipping
                 only on a cooling-off cancellation. The buyer must be able to
                 read what a reason is worth before picking it. Figures come
                 from the same T&C §8 matrix the refund engine uses. --}}
            <div class="mb-6 rounded-lg border border-gray-200 overflow-hidden">
                <p class="px-4 py-2.5 bg-gray-50 border-b border-gray-200 text-sm font-medium text-gray-800">
                    What each reason refunds on this order
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 bg-white">
                                <th class="text-left px-4 py-2 text-xs font-medium text-gray-600 uppercase">Reason</th>
                                <th class="text-left px-4 py-2 text-xs font-medium text-gray-600 uppercase">Window</th>
                                <th class="text-left px-4 py-2 text-xs font-medium text-gray-600 uppercase">Deductions</th>
                                <th class="text-right px-4 py-2 text-xs font-medium text-gray-600 uppercase">You receive</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($reasons as $r)
                            <tr>
                                <td class="px-4 py-2.5 text-gray-900">{{ $r['label'] }}</td>
                                <td class="px-4 py-2.5 text-gray-600">{{ $r['window_days'] !== null ? $r['window_days'].' days from delivery' : 'No time limit' }}</td>
                                <td class="px-4 py-2.5 text-gray-600">
                                    <ul class="space-y-0.5">
                                        @if($r['refunds_gst'])
                                        <li>GST of ₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->gst_paise / 100, 2) }} is refunded.</li>
                                        @else
                                        <li>GST of ₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->gst_paise / 100, 2) }} is <strong>not</strong> refunded — the amount is issued as a buyback voucher, not a credit note.</li>
                                        @endif
                                        @if($r['refunds_shipping'])
                                        <li>Shipping of ₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->shipping_paise / 100, 2) }} is refunded.</li>
                                        @elseif($order->shipping_paise > 0)
                                        <li>Shipping of ₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->shipping_paise / 100, 2) }} is <strong>not</strong> refunded.</li>
                                        @endif
                                        @if($r['saleable_only'])
                                        <li>Unused, saleable goods only — nothing is refunded if the goods cannot be resold.</li>
                                        @elseif($r['non_saleable_refund_paise'] !== null && $r['non_saleable_refund_paise'] !== $r['refund_paise'])
                                        <li>If the goods are not saleable on inspection you receive ₹{{ \App\Modules\Shared\Support\IndianNumber::format($r['non_saleable_refund_paise'] / 100, 2) }} instead.</li>
                                        @endif
                                    </ul>
                                </td>
                                <td class="px-4 py-2.5 text-right font-semibold text-gray-900 whitespace-nowrap">₹{{ \App\Modules\Shared\Support\IndianNumber::format($r['refund_paise'] / 100, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="px-4 py-2.5 bg-gray-50 border-t border-gray-200 text-xs text-gray-600">
                    Amounts are for this order in full, on goods returned in the condition stated. Any promo discount is not refunded as cash; redeemed points and repurchase-wallet credit are returned in the same form they were used.
                </p>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">Additional notes (optional)</label>
                <textarea name="notes" rows="3" maxlength="1000"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                    placeholder="Describe the issue, attach photos if needed (our team may contact you for more information).">{{ old('notes') }}</textarea>
                @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            {{-- What happens next --}}
            <div class="mb-6 rounded-lg bg-gray-50 border border-gray-200 p-4 text-sm text-gray-600">
                <p class="font-medium text-gray-800 mb-1">What happens next?</p>
                <ul class="space-y-1 list-disc list-inside">
                    <li><strong>Cooling-off:</strong> your cancellation takes effect immediately. Once we receive the returned product, the refund is credited to your original payment method within 7 working days.</li>
                    <li><strong>All other reasons:</strong> our team reviews the request and may contact you to arrange collection of the product. Refund decision is communicated by email within 5 working days.</li>
                </ul>
            </div>

            <button type="submit"
                class="w-full py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold">
                Submit return request
            </button>
        </form>
    </div>
</div>
@endsection
