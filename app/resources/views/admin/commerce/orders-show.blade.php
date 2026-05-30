@extends('admin.layouts.admin')
@section('title', $order->order_no)
@section('heading', 'Order ' . $order->order_no)

@section('content')

<div class="mb-4">
    <a href="{{ route('admin.commerce.orders.index') }}" class="text-sm text-gray-600 hover:text-gray-900">← Back to orders</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        {{-- Items --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
            <h3 class="font-semibold text-gray-900 mb-4">Items</h3>
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200">
                        <th class="text-left py-2 text-xs font-medium text-gray-500 uppercase">Product</th>
                        <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Qty</th>
                        <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Price</th>
                        <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">GST</th>
                        <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($order->items as $it)
                    <tr>
                        <td class="py-2">
                            <p class="text-gray-900 font-medium">{{ $it->product_name_snapshot }}</p>
                            <p class="text-xs text-gray-500 font-mono">{{ $it->variant_sku_snapshot }} · HSN {{ $it->hsn_code_snapshot }}</p>
                        </td>
                        <td class="py-2 text-right">{{ $it->qty }}</td>
                        <td class="py-2 text-right">₹{{ number_format($it->unit_price_paise / 100, 2) }}</td>
                        <td class="py-2 text-right text-xs text-gray-500">₹{{ number_format($it->gst_paise / 100, 2) }} ({{ $it->gst_rate_bp / 100 }}%)</td>
                        <td class="py-2 text-right font-semibold">₹{{ number_format($it->line_total_paise / 100, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-4 pt-4 border-t border-gray-200 flex flex-col items-end text-sm space-y-1">
                <div class="flex gap-8"><span class="text-gray-600">Subtotal (taxable)</span><span class="w-32 text-right">₹{{ number_format(($order->subtotal_paise - $order->gst_paise) / 100, 2) }}</span></div>
                <div class="flex gap-8"><span class="text-gray-600">GST</span><span class="w-32 text-right">₹{{ number_format($order->gst_paise / 100, 2) }}</span></div>
                <div class="flex gap-8 font-semibold pt-2 border-t border-gray-100 mt-2"><span>Total</span><span class="w-32 text-right">{{ $order->displayTotal() }}</span></div>
            </div>
        </div>

        {{-- Actions --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
            <h3 class="font-semibold text-gray-900 mb-3">Fulfilment Actions</h3>
            <div class="flex flex-wrap gap-3">
                @if($order->status === 'paid')
                <form method="POST" action="{{ route('admin.commerce.orders.ship', $order) }}"
                    data-confirm="Mark this order as shipped?"
                    data-confirm-title="Confirm shipment"
                    data-confirm-impact="Records the order as shipped and updates its fulfilment state. This is not easily reversible.">@csrf
                    <button class="px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-medium">Mark as Shipped</button>
                </form>
                @endif
                @if($order->status === 'shipped')
                <form method="POST" action="{{ route('admin.commerce.orders.deliver', $order) }}"
                    data-confirm="Mark this order as delivered?"
                    data-confirm-title="Confirm delivery"
                    data-confirm-impact="Records the order as delivered and opens the cooling-off window. This is not easily reversible.">@csrf
                    <button class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium">Mark as Delivered (opens cooling-off)</button>
                </form>
                @endif
                @if(!in_array($order->status, ['paid', 'shipped']))
                <p class="text-sm text-gray-500">No fulfilment actions available in status <strong>{{ $order->status }}</strong>.</p>
                @endif
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <p class="text-xs uppercase tracking-wider text-gray-500 mb-2">Status</p>
            <p class="text-lg font-semibold text-gray-900 capitalize">{{ str_replace('_', ' ', $order->status) }}</p>
            <div class="mt-4 space-y-1 text-xs text-gray-500">
                @if($order->placed_at)<div>Placed {{ $order->placed_at->format('d M Y H:i') }}</div>@endif
                @if($order->paid_at)<div>Paid {{ $order->paid_at->format('d M Y H:i') }}</div>@endif
                @if($order->shipped_at)<div>Shipped {{ $order->shipped_at->format('d M Y H:i') }}</div>@endif
                @if($order->delivered_at)<div class="text-green-700 font-medium">Delivered {{ $order->delivered_at->format('d M Y H:i') }}</div>@endif
            </div>
        </div>

        @if($order->coolingOff)
        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <p class="text-xs uppercase tracking-wider text-gray-500 mb-2">Cooling-Off</p>
            <p class="text-sm"><strong class="text-gray-900">{{ $order->coolingOff->daysRemaining() }} days</strong> remaining</p>
            <p class="text-xs text-gray-500 mt-1">Closes {{ $order->coolingOff->ends_at->format('d M Y') }}</p>
            <p class="text-xs text-gray-500 mt-1">Status: <span class="font-mono">{{ $order->coolingOff->status }}</span></p>
        </div>
        @endif

        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <p class="text-xs uppercase tracking-wider text-gray-500 mb-2">Customer</p>
            <p class="text-sm font-medium text-gray-900">{{ $order->customer->display_name ?? '—' }}</p>
            <p class="text-xs text-gray-500 break-all">{{ $order->customer->email_enc ?? '—' }}</p>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <p class="text-xs uppercase tracking-wider text-gray-500 mb-2">Attribution</p>
            @if($order->attributed_distributor_id)
            <p class="text-sm font-mono text-brand-600">{{ $order->distributor->adn ?? '#' . $order->attributed_distributor_id }}</p>
            <p class="text-xs text-gray-500 mt-1">Source: {{ $order->attribution_source }}</p>
            @else
            <p class="text-sm italic text-gray-400">House sale (no referrer)</p>
            @endif
            @if($order->self_consumption)
            <p class="text-xs text-amber-700 mt-1">Self-consumption (BV only)</p>
            @endif
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
            <p class="text-xs uppercase tracking-wider text-gray-500 mb-2">Shipping</p>
            <p class="text-sm text-gray-700">
                {{ $order->ship_name }}<br>
                {{ $order->ship_phone_e164 }}<br>
                {{ $order->ship_line1 }}@if($order->ship_line2), {{ $order->ship_line2 }}@endif<br>
                {{ $order->ship_city }}, {{ $order->ship_state }} {{ $order->ship_pincode }}
            </p>
        </div>
    </div>
</div>

@endsection
