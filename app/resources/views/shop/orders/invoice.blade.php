<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order summary {{ $order->order_no }} — arovolife</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .inv-sheet, .inv-sheet * {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        @media print {
            @page { size: A4 portrait; margin: 16mm; }
            .no-print { display: none !important; }
            html, body { background: #ffffff !important; }
            body.wizard-stage { background: #ffffff !important; }
            .wizard-stage::before { display: none !important; }
            .inv-sheet { box-shadow: none !important; border-color: #e5e7eb !important; }
        }
    </style>
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    {{-- Toolbar (hidden when printing) --}}
    <div class="no-print sticky top-0 z-10 bg-white border-b border-gray-200">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('orders.show', $order->order_no) }}" class="text-sm text-gray-600 hover:text-gray-900 whitespace-nowrap">← Back to order</a>
                <h1 class="text-base sm:text-lg font-bold text-gray-900 truncate">Order summary</h1>
            </div>
            <button type="button" onclick="window.print()"
                class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-sm font-medium text-white transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
                Download / Print
            </button>
        </div>
        <p class="max-w-3xl mx-auto px-4 sm:px-6 pb-3 text-xs text-gray-500">
            The button opens your browser's print dialog — choose <span class="font-medium">Save as PDF</span> to download.
        </p>
    </div>

    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-8">
        <div class="inv-sheet bg-white rounded-2xl border border-gray-200 shadow-sm p-6 sm:p-8">

            {{-- Header --}}
            <div class="flex items-start justify-between gap-4 pb-5 mb-6 border-b border-gray-200">
                <div class="flex items-center gap-4 min-w-0">
                    <img src="{{ asset('assets/arovolife-logos/arovolife-blue-logo.png') }}" alt="arovolife" class="h-12 w-auto object-contain">
                    <div class="min-w-0">
                        <p class="text-lg sm:text-xl font-bold text-brand-600 leading-tight">Arovolife Private Limited</p>
                        <p class="text-xs text-gray-500">CIN U46909TS2026PTC210896</p>
                    </div>
                </div>
                <div class="text-right shrink-0">
                    <p class="text-sm font-bold text-gray-900 uppercase tracking-wider">Order Summary</p>
                    <p class="text-xs text-gray-600 mt-1 font-mono">{{ $order->order_no }}</p>
                    @if($order->placed_at)
                        <p class="text-xs text-gray-500 mt-0.5">{{ $order->placed_at->format('d M Y') }}</p>
                    @endif
                </div>
            </div>

            {{-- Bill to + (when attributed) the direct seller --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mb-6">
                <div>
                    <p class="text-[11px] uppercase tracking-wider font-semibold text-gray-500 mb-1.5">Billed to</p>
                    <p class="text-sm font-semibold text-gray-900">{{ $order->customer->display_name }}</p>
                    <p class="text-sm text-gray-700 leading-relaxed mt-1">
                        {{ $order->ship_name }}<br>
                        {{ $order->ship_phone_e164 }}<br>
                        {{ $order->ship_line1 }}@if($order->ship_line2), {{ $order->ship_line2 }}@endif<br>
                        {{ $order->ship_city }}, {{ $order->ship_state }} {{ $order->ship_pincode }}
                    </p>
                </div>
                @if($order->distributor)
                <div class="sm:text-right">
                    <p class="text-[11px] uppercase tracking-wider font-semibold text-gray-500 mb-1.5">Your arovolife distributor</p>
                    <p class="text-sm font-semibold text-gray-900">{{ $order->distributor->user?->full_name ?? '—' }}</p>
                    <p class="text-sm text-gray-700 mt-1">ADN <span class="font-mono text-brand-700">{{ $order->distributor->adn }}</span></p>
                </div>
                @endif
            </div>

            {{-- Line items --}}
            <table class="w-full text-sm border-t border-gray-200">
                <thead>
                    <tr class="text-[11px] uppercase tracking-wider text-gray-500">
                        <th class="text-left font-semibold py-2">Item</th>
                        <th class="text-left font-semibold py-2">HSN</th>
                        <th class="text-right font-semibold py-2">Qty</th>
                        <th class="text-right font-semibold py-2">Rate</th>
                        <th class="text-right font-semibold py-2">GST</th>
                        <th class="text-right font-semibold py-2">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($order->items as $item)
                    <tr>
                        <td class="py-2 pr-2 text-gray-900">
                            {{ $item->product_name_snapshot }}
                            <span class="block text-[11px] text-gray-400 font-mono">{{ $item->variant_sku_snapshot }}</span>
                        </td>
                        <td class="py-2 text-gray-600 font-mono text-xs">{{ $item->hsn_code_snapshot ?: '—' }}</td>
                        <td class="py-2 text-right text-gray-700">{{ $item->qty }}</td>
                        <td class="py-2 text-right text-gray-700">₹{{ number_format($item->unit_price_paise / 100, 2) }}</td>
                        <td class="py-2 text-right text-gray-600">{{ number_format($item->gst_rate_bp / 100, 0) }}%</td>
                        <td class="py-2 text-right font-medium text-gray-900">₹{{ number_format($item->line_total_paise / 100, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>

            {{-- Totals --}}
            <div class="flex justify-end mt-4">
                <div class="w-full sm:w-72 space-y-1">
                    <div class="flex justify-between text-sm"><span class="text-gray-600">Taxable value</span><span>₹{{ number_format($order->items->sum('taxable_value_paise') / 100, 2) }}</span></div>
                    <div class="flex justify-between text-sm"><span class="text-gray-600">GST</span><span>₹{{ number_format($order->gst_paise / 100, 2) }}</span></div>
                    @if($order->discount_paise > 0)
                    <div class="flex justify-between text-sm text-green-700"><span>Discount</span><span>−₹{{ number_format($order->discount_paise / 100, 2) }}</span></div>
                    @endif
                    <div class="flex justify-between text-sm"><span class="text-gray-600">Shipping</span>
                        @if($order->shipping_paise > 0)<span>₹{{ number_format($order->shipping_paise / 100, 2) }}</span>@else<span class="text-green-700">Free</span>@endif
                    </div>
                    <div class="flex justify-between font-bold text-gray-900 pt-2 border-t border-gray-200 mt-1">
                        <span>Total</span><span>{{ $order->displayTotal() }}</span>
                    </div>
                    <p class="text-[11px] text-gray-400 pt-1">Amounts are inclusive of GST. Payment: {{ strtoupper($order->payment_method) }}.</p>
                    <p class="text-[11px] text-gray-400">This is an order summary / payment receipt, not a GST tax invoice.</p>
                </div>
            </div>

            {{-- Contact footer (printable-page convention) --}}
            <div class="mt-8 pt-4 border-t border-gray-200 text-center">
                <p class="text-[11px] text-gray-500 leading-snug">
                    <span class="font-semibold text-gray-700">Arovolife Private Limited</span> · CIN U46909TS2026PTC210896
                </p>
                <p class="text-[11px] text-gray-600 mt-1 leading-snug">
                    <a href="tel:+918886662949" class="hover:text-brand-600">+91 88866 62949</a>
                    <span class="text-gray-400">|</span>
                    <a href="mailto:support@arovolife.com" class="hover:text-brand-600">support@arovolife.com</a>
                    <span class="text-gray-400">|</span>
                    <a href="https://www.arovolife.com" class="hover:text-brand-600">www.arovolife.com</a>
                </p>
            </div>

        </div>
    </div>

</body>
</html>
