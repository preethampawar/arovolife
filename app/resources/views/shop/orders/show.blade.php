@extends('layouts.app')
@section('title', 'Order '.$order->order_no)

@section('content')
@php
    $showBv = $showBv ?? false; // BV is distributor-only (hard rule #3)
    $bv = $order->personalBvStatus();
    $bvBadge = match ($bv['state']) {
        'accumulated' => 'bg-green-50 text-green-700 border-green-200',
        'pending' => 'bg-amber-50 text-amber-700 border-amber-200',
        'reversed' => 'bg-red-50 text-red-700 border-red-200',
        default => 'bg-gray-50 text-gray-600 border-gray-200',
    };
@endphp

<div>
    <a href="{{ route('orders.index') }}" class="text-sm text-brand-700 hover:text-brand-800">← Back to my orders</a>

    <div class="flex items-center justify-between mt-3 mb-6 gap-3 flex-wrap">
        <h1 class="text-2xl font-bold text-gray-900">Order <span class="font-mono text-brand-700">{{ $order->order_no }}</span></h1>
        <div class="flex items-center gap-3">
            <a href="{{ route('orders.invoice', $order->order_no) }}"
               class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-700 hover:text-brand-800">
                <x-lucide-file-text class="w-4 h-4" />
                Order summary
            </a>
            @include('partials.order-status-badge', ['status' => $order->status])
        </div>
    </div>

    @if(session('status'))
    <div class="mb-5 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif
    @error('cancel')<div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ $message }}</div>@enderror

    {{-- Items --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
        <h2 class="font-semibold text-gray-900 mb-4">Items</h2>
        <div class="space-y-3">
            @foreach($order->items as $item)
            <div class="flex justify-between text-sm">
                <span>
                    <strong class="text-gray-900">{{ $item->product_name_snapshot }}</strong> × {{ $item->qty }}
                    @if($showBv && $item->lineBvPaise() > 0)
                    <span class="ml-1 text-xs text-brand-700">({{ \App\Modules\Shared\Support\IndianNumber::format($item->lineBvPaise() / 100, 0) }} BV)</span>
                    @endif
                </span>
                <span class="font-medium">₹{{ \App\Modules\Shared\Support\IndianNumber::format($item->line_total_paise / 100, 2) }}</span>
            </div>
            @endforeach
        </div>
        <div class="mt-4 pt-4 border-t border-gray-200 space-y-1">
            @if($showBv && $order->bvTotalPaise() > 0)
            {{-- BV at the TOP of the totals, mirroring the cart's Order Summary. --}}
            <div class="flex justify-between text-brand-700 pb-2 mb-2 border-b border-gray-100">
                <span class="font-semibold">Total BV</span>
                <span class="font-bold" title="Business Volume — points used in the compensation plan">{{ \App\Modules\Shared\Support\IndianNumber::format($order->bvTotalPaise() / 100, 0) }} BV</span>
            </div>
            @endif
            <div class="flex justify-between text-sm"><span class="text-gray-600">Subtotal</span><span>₹{{ \App\Modules\Shared\Support\IndianNumber::format(($order->subtotal_paise - $order->gst_paise) / 100, 2) }}</span></div>
            <div class="flex justify-between text-sm"><span class="text-gray-600">GST</span><span>₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->gst_paise / 100, 2) }}</span></div>
            @if($order->discount_paise > 0)
            <div class="flex justify-between text-sm text-green-700"><span>Discount</span><span>−₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->discount_paise / 100, 2) }}</span></div>
            @endif
            @if($order->isCollection())
            <div class="flex justify-between text-sm"><span class="text-gray-600">Collection</span>
                @if($order->collection_fee_paise > 0)<span>₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->collection_fee_paise / 100, 2) }}</span>@else<span class="text-green-700">No charge</span>@endif
            </div>
            @else
            <div class="flex justify-between text-sm"><span class="text-gray-600">Shipping</span>
                @if($order->shipping_paise > 0)<span>₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->shipping_paise / 100, 2) }}</span>@else<span class="text-green-700">Free</span>@endif
            </div>
            @endif
            <div class="flex justify-between font-semibold pt-2 border-t border-gray-100 mt-2"><span>Total</span><span>{{ $order->displayTotal() }}</span></div>
        </div>
    </div>

    {{-- BV accumulation status (distributor-only) --}}
    @if($showBv && $bv['state'] !== 'none')
    <div class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
        <h2 class="font-semibold text-gray-900 mb-2">Business Volume</h2>
        <div class="flex items-center gap-3">
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border {{ $bvBadge }}">{{ $bv['label'] }}</span>
            <span class="text-sm text-gray-600">{{ \App\Modules\Shared\Support\IndianNumber::format($order->bvTotalPaise() / 100, 0) }} BV from this order</span>
        </div>
        @if($bv['state'] === 'pending')
        <p class="text-xs text-gray-600 mt-2">BV is counted toward your personal volume once payment is received. It is reversed if the order is refunded.</p>
        @endif
    </div>
    @endif

    {{-- Order timeline (derived from the order's own timestamps) --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6 mb-6">
        <h2 class="font-semibold text-gray-900 mb-3">Order progress</h2>
        @include('shop.orders._timeline', ['order' => $order])
    </div>

    {{-- Where this order is going --}}
    <div class="bg-white rounded-2xl border border-gray-200 p-6">
        @if($order->isCollection())
            {{-- R-47: this used to read "Shipping to" above the centre's postal
                 address, beside the buyer's own name — an address they never
                 gave, labelled as a delivery that would not happen. --}}
            <h2 class="font-semibold text-gray-900 mb-3">Collect from</h2>
            @if($order->areteCenter)
                <p class="text-sm text-gray-700">
                    <span class="font-medium text-gray-900">{{ $order->areteCenter->name }}</span><br>
                    {{ $order->areteCenter->displayAddress() }}
                    @if($order->areteCenter->contact_number)<br>{{ $order->areteCenter->contact_number }}@endif
                </p>
                @if($order->status === \App\Modules\Commerce\Models\Order::STATUS_AWAITING_COLLECTION)
                <p class="mt-3 text-sm font-medium text-purple-700">Your order has arrived and is ready to collect.</p>
                @elseif(in_array($order->status, [\App\Modules\Commerce\Models\Order::STATUS_DELIVERED, \App\Modules\Commerce\Models\Order::STATUS_CONFIRMED], true))
                <p class="mt-3 text-sm text-gray-600">Collected. Thank you.</p>
                @else
                <p class="mt-3 text-sm text-gray-600">We will let you know as soon as it arrives at the centre.</p>
                @endif
            @else
                <p class="text-sm text-gray-700">
                    The centre you chose is no longer available. Please contact us and we will arrange another way to get your order to you.
                </p>
            @endif
        @else
            <h2 class="font-semibold text-gray-900 mb-3">Shipping to</h2>
            <p class="text-sm text-gray-700">
                {{ $order->ship_name }}<br>
                {{ $order->ship_phone_e164 }}<br>
                {{ $order->ship_line1 }}@if($order->ship_line2), {{ $order->ship_line2 }}@endif<br>
                {{ $order->ship_city }}, {{ $order->ship_state }} {{ $order->ship_pincode }}
            </p>
        @endif
        @if($order->ship_carrier || $order->ship_tracking_no)
        <div class="mt-3 pt-3 border-t border-gray-100 text-sm text-gray-700">
            <span class="font-medium text-gray-900">Tracking:</span>
            {{ $order->ship_carrier ?: 'Courier' }}@if($order->ship_tracking_no) — <span class="font-mono">{{ $order->ship_tracking_no }}</span>@endif
        </div>
        @endif
    </div>

    {{-- Cancel (only before the order ships) --}}
    @if(in_array($order->status, ['placed', 'paid'], true))
    <div class="mt-6 text-right">
        <form method="POST" action="{{ route('orders.cancel', $order->order_no) }}" class="inline"
            data-confirm="Cancel this order?"
            data-confirm-title="Cancel your order"
            data-confirm-impact="Impact: this cancels your order before it ships. The items are released and the order can't be reinstated — you'd need to place a new order. Any payment already made is refunded by our team separately.">
            @csrf
            <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-700">Cancel this order</button>
        </form>
    </div>
    @endif

    {{-- Return / refund (after delivery, within applicable windows) --}}
    @if(in_array($order->status, ['delivered', 'confirmed'], true))
    <div class="mt-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <h2 class="font-semibold text-gray-900 mb-1">Returns &amp; Refunds</h2>
            @if($order->coolingOff && $order->coolingOff->status === 'open' && $order->coolingOff->ends_at->isFuture())
            <p class="text-sm text-blue-700 mb-3">
                Your <strong>30-day cooling-off window</strong> is open — {{ $order->coolingOff->daysRemaining() }} day{{ $order->coolingOff->daysRemaining() === 1 ? '' : 's' }} remaining. You can return this order for a refund.
            </p>
            @else
            <p class="text-sm text-gray-600 mb-3">You may be eligible to return this order under our buyback / refund policy (damage, dissatisfaction, general buyback).</p>
            @endif
            <a href="{{ route('orders.return.create', $order->order_no) }}"
               class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-700 hover:text-brand-800">
                <x-lucide-undo-2 class="w-4 h-4" />
                Return or refund this order →
            </a>
        </div>
    </div>
    @endif

    {{-- Refund status for a paid order cancelled before it shipped, or a
         return closed without refund (the order goes back to delivered) --}}
    @if($refundStatus !== null && ($order->status === 'cancelled' || ($order->status === 'delivered' && $refundStatus['tone'] === 'closed')))
    @php
        $refundBox = match ($refundStatus['tone']) {
            'done' => ['border-green-200 bg-green-50', 'text-green-900', 'text-green-800'],
            'closed' => ['border-gray-200 bg-gray-50', 'text-gray-900', 'text-gray-700'],
            default => ['border-amber-200 bg-amber-50', 'text-amber-900', 'text-amber-800'],
        };
    @endphp
    <div class="mt-6 rounded-2xl border p-5 {{ $refundBox[0] }}">
        <h2 class="font-semibold mb-1 {{ $refundBox[1] }}">Refund status</h2>
        <p class="text-sm font-medium {{ $refundBox[2] }}">{{ $refundStatus['text'] }}</p>
    </div>
    @endif

    {{-- Refund status (after a return has been opened or processed) --}}
    @if(in_array($order->status, ['refund_requested', 'refund_inspection', 'refund_approved', 'refunded'], true))
    <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5">
        <h2 class="font-semibold text-amber-900 mb-1">Return / Refund Status</h2>
        @if($order->status === 'refund_requested')
        <p class="text-sm text-amber-800">Your return request has been received and is awaiting review by our team. We'll update you within 5 working days.</p>
        @elseif($order->status === 'refund_inspection')
        <p class="text-sm text-amber-800">Your return is being inspected. A decision will be communicated shortly.</p>
        @elseif($refundStatus !== null && in_array($order->status, ['refund_approved', 'refunded'], true))
        <p class="text-sm font-medium {{ $refundStatus['tone'] === 'done' ? 'text-green-800' : ($refundStatus['tone'] === 'closed' ? 'text-gray-700' : 'text-amber-800') }}">{{ $refundStatus['text'] }}</p>
        @elseif($order->status === 'refund_approved')
        @php $awaitingReceipt = $order->returnRequests()->whereNotNull('entitlements_held_at')->whereNull('received_at')->whereNull('receipt_outcome')->exists(); @endphp
        @if($awaitingReceipt)
        <p class="text-sm text-green-800 font-medium">Refund approved. Once we receive the returned product, the amount is credited to your original payment method within 7 working days, and any points or repurchase credit used on this order are returned to you at the same time.</p>
        @else
        <p class="text-sm text-green-800 font-medium">Refund sent to your original payment method — usually credited within 7 working days.</p>
        @endif
        @elseif($order->status === 'refunded')
        <p class="text-sm text-green-800 font-medium">Refund complete.</p>
        @endif
    </div>
    @endif
</div>
@endsection
