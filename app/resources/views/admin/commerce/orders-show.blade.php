@extends('admin.layouts.admin')
@section('title', $order->order_no)
@section('heading', 'Order ' . $order->order_no)

@section('content')

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        {{-- Items --}}
        <x-ui.card padding="p-6">
            <h3 class="font-semibold text-gray-900 mb-4">Items</h3>
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200">
                        <th class="text-left py-2 text-xs font-medium text-gray-600 uppercase w-12">S.No.</th>
                        <th class="text-left py-2 text-xs font-medium text-gray-600 uppercase">Product</th>
                        <th class="text-right py-2 text-xs font-medium text-gray-600 uppercase">Qty</th>
                        <th class="text-right py-2 text-xs font-medium text-gray-600 uppercase">Price</th>
                        <th class="text-right py-2 text-xs font-medium text-gray-600 uppercase">BV</th>
                        <th class="text-right py-2 text-xs font-medium text-gray-600 uppercase">GST</th>
                        <th class="text-right py-2 text-xs font-medium text-gray-600 uppercase">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($order->items as $it)
                    <tr>
                        <td class="py-2 text-gray-500 tabular-nums">{{ $loop->iteration }}</td>
                        <td class="py-2">
                            <p class="text-gray-900 font-medium">{{ $it->product_name_snapshot }}</p>
                            <p class="text-xs text-gray-600 font-mono">{{ $it->variant_sku_snapshot }} · HSN {{ $it->hsn_code_snapshot }}</p>
                        </td>
                        <td class="py-2 text-right">{{ $it->qty }}</td>
                        <td class="py-2 text-right">₹{{ \App\Modules\Shared\Support\IndianNumber::format($it->unit_price_paise / 100, 2) }}</td>
                        <td class="py-2 text-right text-brand-700">{{ \App\Modules\Shared\Support\IndianNumber::format($it->lineBvPaise() / 100, 0) }} BV</td>
                        <td class="py-2 text-right text-xs text-gray-600">₹{{ \App\Modules\Shared\Support\IndianNumber::format($it->gst_paise / 100, 2) }} ({{ $it->gst_rate_bp / 100 }}%)</td>
                        <td class="py-2 text-right font-semibold">₹{{ \App\Modules\Shared\Support\IndianNumber::format($it->line_total_paise / 100, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            <div class="mt-4 pt-4 border-t border-gray-200 flex flex-col items-end text-sm space-y-1">
                {{-- BV at the TOP of the totals, mirroring the cart / order summary. --}}
                <div class="flex gap-8 text-brand-700 pb-2 mb-2 border-b border-gray-200"><span class="font-semibold">Total BV</span><span class="w-32 text-right font-bold">{{ \App\Modules\Shared\Support\IndianNumber::format($order->bvTotalPaise() / 100, 0) }} BV</span></div>
                <div class="flex gap-8"><span class="text-gray-600">Subtotal (taxable)</span><span class="w-32 text-right">₹{{ \App\Modules\Shared\Support\IndianNumber::format(($order->subtotal_paise - $order->gst_paise) / 100, 2) }}</span></div>
                <div class="flex gap-8"><span class="text-gray-600">GST</span><span class="w-32 text-right">₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->gst_paise / 100, 2) }}</span></div>
                @if($order->discount_paise > 0)
                <div class="flex gap-8 text-green-700"><span>Discount</span><span class="w-32 text-right">−₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->discount_paise / 100, 2) }}</span></div>
                @endif
                @if($repurchaseWalletDebit)
                <div class="flex gap-8 text-green-700"><span>Repurchase wallet used</span><span class="w-32 text-right">−₹{{ \App\Modules\Shared\Support\IndianNumber::format(abs($repurchaseWalletDebit->amount_paise) / 100, 2) }}</span></div>
                @endif
                @if($order->isCollection())
                <div class="flex gap-8"><span class="text-gray-600">Collection</span><span class="w-32 text-right">@if($order->collection_fee_paise > 0)₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->collection_fee_paise / 100, 2) }}@else<span class="text-green-700">No charge</span>@endif</span></div>
                @else
                <div class="flex gap-8"><span class="text-gray-600">Shipping</span><span class="w-32 text-right">@if($order->shipping_paise > 0)₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->shipping_paise / 100, 2) }}@else<span class="text-green-700">Free</span>@endif</span></div>
                @endif
                <div class="flex gap-8 font-semibold pt-2 border-t border-gray-100 mt-2"><span>Total</span><span class="w-32 text-right">{{ $order->displayTotal() }}</span></div>
            </div>
        </x-ui.card>

        {{-- Actions --}}
        <x-ui.card padding="p-6">
            <h3 class="font-semibold text-gray-900 mb-3">Fulfilment Actions</h3>
            @error('cancel')<p class="mb-3 text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="flex flex-wrap items-end gap-3">
                @if($order->status === 'paid' && $order->packed_at === null)
                @can('commerce.order.manage')
                {{-- Pack: pick FEFO batches from a warehouse (inventory plan H7). --}}
                <form method="POST" action="{{ route('admin.commerce.orders.pack', $order) }}"
                    class="flex flex-wrap items-end gap-3 w-full pb-3 mb-1 border-b border-gray-100"
                    data-confirm="Pack this order?"
                    data-confirm-title="Confirm packing"
                    data-confirm-impact="Impact: takes the items out of stock from the earliest-expiring batches in the chosen warehouse and sets the order to READY TO SHIP. Cancelling afterwards puts the same units back.">@csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Pack from warehouse</label>
                        <select name="warehouse_code" class="rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                            @foreach($packWarehouses as $wh)
                            <option value="{{ $wh->code }}" @selected($wh->code === $defaultWarehouseCode)>{{ $wh->name }} ({{ $wh->code }})</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="px-4 py-2 rounded-lg border border-brand-300 text-brand-800 hover:bg-brand-50 text-sm font-medium">Pack order</button>
                </form>
                @endcan
                @endif

                @if(in_array($order->status, ['paid', 'ready_to_ship'], true))
                @can('commerce.order.manage')
                {{-- Ship: pick the route, then confirm. Everything goes through
                     DispatchService, so the courier leg is recorded either way. --}}
                <div class="w-full space-y-3">
                    @error('ship')<p class="text-sm text-red-600">{{ $message }}</p>@enderror

                    @if($pendingBooking)
                    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        @if($pendingBooking->gateway_shipment_id)
                        This parcel is already booked with {{ ucfirst($pendingBooking->gateway) }} (shipment <span class="font-mono">{{ $pendingBooking->gateway_shipment_id }}</span>).
                        To send it another way, cancel that booking in the {{ ucfirst($pendingBooking->gateway) }} panel first. Dispatching through {{ ucfirst($pendingBooking->gateway) }} again resumes the same booking.
                        @else
                        An earlier {{ ucfirst($pendingBooking->gateway) }} booking request got no reply, so it may exist anyway.
                        Search the {{ ucfirst($pendingBooking->gateway) }} panel for order <span class="font-mono">{{ $order->order_no }}</span> before dispatching again.
                        @endif
                    </div>
                    @endif

                    @if($parcelGaps !== [])
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        <p class="font-medium">Shiprocket needs a weight and packed size for every product. Missing:</p>
                        <ul class="mt-1 list-disc list-inside">
                            @foreach($parcelGaps as $gap)
                            <li>
                                @if($gap->productId)
                                <a href="{{ route('admin.catalog.products.edit', $gap->productId) }}" class="underline">{{ $gap->productName }}</a>
                                @else
                                {{ $gap->productName }}
                                @endif
                                <span class="font-mono text-xs">{{ $gap->sku }}</span> — {{ implode(', ', $gap->missing) }}
                            </li>
                            @endforeach
                        </ul>
                        <p class="mt-1">Fill them in on the product, or dispatch manually.</p>
                    </div>
                    @endif

                    <form method="POST" action="{{ route('admin.commerce.orders.ship', $order) }}"
                        class="flex flex-wrap items-end gap-3"
                        data-confirm="Mark this order as shipped?"
                        data-confirm-title="Confirm shipment"
                        data-confirm-impact="{{ ($order->isCollection() ? 'Impact: sets the order to SHIPPED and consigns the parcel to the Arete centre the buyer chose — not to the buyer. It also recognises revenue in the ledger.' : 'Impact: sets the order to SHIPPED, recognises revenue in the ledger, and emails the customer their shipping details.') . (count($dispatchRoutes) > 1 ? ' Choosing Shiprocket books a real courier pickup.' : '') . ' Not easily reversible — check the details first.' }}">@csrf
                        @if(count($dispatchRoutes) > 1)
                        <fieldset>
                            <legend class="block text-xs font-medium text-gray-600 mb-1">Route <x-help-tip text="Manual: you hand the parcel to a courier and type its name and AWB. Shiprocket: the system books the courier, and the AWB and label come back from Shiprocket." /></legend>
                            <div class="flex gap-4 text-sm">
                                <label class="inline-flex items-center gap-1.5">
                                    <input type="radio" name="route" value="manual" @checked(old('route', $preferredRoute) === 'manual' || $parcelGaps !== [])> Manual
                                </label>
                                <label class="inline-flex items-center gap-1.5 {{ $parcelGaps !== [] ? 'text-gray-400' : '' }}">
                                    <input type="radio" name="route" value="shiprocket" @checked(old('route', $preferredRoute) === 'shiprocket' && $parcelGaps === []) @disabled($parcelGaps !== [])> Shiprocket
                                </label>
                            </div>
                        </fieldset>
                        @else
                        <input type="hidden" name="route" value="manual">
                        @endif
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Courier / carrier{{ count($dispatchRoutes) > 1 ? ' (manual only)' : '' }} <x-help-tip text="The delivery company handling this shipment; shown to the customer in their shipping email. Required for a manual dispatch." /></label>
                            <input name="ship_carrier" type="text" maxlength="{{ \App\Modules\Fulfilment\Services\ManualCourier::CARRIER_MAX }}" value="{{ old('ship_carrier') }}" placeholder="e.g. Delhivery, BlueDart"
                                class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Tracking number{{ count($dispatchRoutes) > 1 ? ' (manual only)' : '' }} <x-help-tip text="The courier's tracking number so the customer can follow the shipment." /></label>
                            <input name="ship_tracking_no" type="text" maxlength="{{ \App\Modules\Fulfilment\Services\ManualCourier::AWB_MAX }}" value="{{ old('ship_tracking_no') }}" placeholder="e.g. 1234567890"
                                class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                        </div>
                        @if($pendingBooking)
                        <label class="w-full inline-flex items-start gap-2 text-sm text-gray-800">
                            <input type="checkbox" name="confirm_remote_cancelled" value="1" class="mt-0.5">
                            <span>
                                @if($pendingBooking->gateway_shipment_id)
                                Sending manually: I have cancelled {{ ucfirst($pendingBooking->gateway) }} shipment {{ $pendingBooking->gateway_shipment_id }} in the {{ ucfirst($pendingBooking->gateway) }} panel.
                                @else
                                I have checked the {{ ucfirst($pendingBooking->gateway) }} panel: order {{ $order->order_no }} is not there, or I have cancelled it.
                                @endif
                            </span>
                        </label>
                        @endif
                        <x-ui.button >Mark as Shipped</x-ui.button>
                    </form>
                </div>
                @endcan
                @endif

                @if($order->status === 'shipped' && $order->isCollection())
                {{-- A collection parcel reaches the centre first. The buyer has
                     not received anything yet, so cooling-off must not open. --}}
                <form method="POST" action="{{ route('admin.commerce.orders.awaiting-collection', $order) }}"
                    data-confirm="Record this parcel as arrived at the centre?"
                    data-confirm-title="Confirm arrival at centre"
                    data-confirm-impact="Impact: sets the order to READY TO COLLECT and lets the buyer be told it has arrived. It does NOT open the cooling-off window — that starts when the buyer actually collects.">@csrf
                    <button class="px-4 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium">Arrived at centre</button>
                </form>
                @endif

                @if(($order->status === 'shipped' && ! $order->isCollection()) || $order->status === 'awaiting_collection')
                <form method="POST" action="{{ route('admin.commerce.orders.deliver', $order) }}"
                    data-confirm="Mark this order as delivered?"
                    data-confirm-title="Confirm delivery"
                    data-confirm-impact="Impact: sets the order to DELIVERED and OPENS the statutory 30-day cooling-off window (the customer may return for a full refund until it closes). This is not easily reversible.">@csrf
                    @if($order->status === 'awaiting_collection')
                    <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="off" placeholder="Buyer's code"
                        title="The six-digit collection code the buyer received by email" class="w-32 rounded-lg border-gray-300 font-mono text-sm">
                    @endif
                    <button class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium">{{ $order->isCollection() ? 'Record collection (opens cooling-off)' : 'Mark as Delivered (opens cooling-off)' }}</button>
                </form>
                @endif

                {{-- Cancel: only before shipment (placed / paid / packed). A
                     pending offline order is finance's to confirm or reject. --}}
                @if(in_array($order->status, ['placed', 'paid', 'ready_to_ship'], true) && ! $order->isAwaitingOfflineConfirmation())
                <form method="POST" action="{{ route('admin.commerce.orders.cancel', $order) }}"
                    data-confirm="Cancel this order?"
                    data-confirm-title="Cancel order"
                    data-confirm-impact="Impact: sets the order to CANCELLED and releases the reserved stock back to inventory. Allowed only before the order ships. Any refund of money already collected is handled separately (Phase 3). This cannot be undone.">@csrf
                    <button class="px-4 py-2 rounded-lg border border-red-300 text-red-700 hover:bg-red-50 text-sm font-medium">Cancel Order</button>
                </form>
                @endif

                @if($order->isAwaitingOfflineConfirmation())
                <p class="text-sm text-gray-600">Waiting for finance to confirm the offline payment. The order can be packed and shipped once it is paid.</p>
                @endif

                @if(! in_array($order->status, ['placed', 'paid', 'ready_to_ship', 'shipped'], true))
                <p class="text-sm text-gray-600">No fulfilment actions available in status <strong>{{ $order->status }}</strong>.</p>
                @endif
            </div>
        </x-ui.card>

        {{-- Pick list + shipment (inventory plan H7) --}}
        <x-ui.card padding="p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2 mb-4">
                <h3 class="font-semibold text-gray-900">Pick list</h3>
                <p class="text-xs text-gray-600">
                    Warehouse: <span class="font-mono text-gray-900">{{ $order->warehouse_code ?? '—' }}</span>
                    @if($order->packed_at) · Packed {{ $order->packed_at->format('d M Y H:i') }}@endif
                </p>
            </div>
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200">
                        <th class="text-left py-2 text-xs font-medium text-gray-600 uppercase">SKU</th>
                        <th class="text-left py-2 text-xs font-medium text-gray-600 uppercase">Product</th>
                        <th class="text-right py-2 text-xs font-medium text-gray-600 uppercase">Qty</th>
                        <th class="text-left py-2 pl-4 text-xs font-medium text-gray-600 uppercase">Batch</th>
                        <th class="text-left py-2 text-xs font-medium text-gray-600 uppercase">Expiry</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($pickList as $line)
                    <tr>
                        <td class="py-2 font-mono text-xs text-gray-700">{{ $line['sku'] }}</td>
                        <td class="py-2 text-gray-900">{{ $line['name'] }}</td>
                        <td class="py-2 text-right tabular-nums">{{ $line['qty'] }}</td>
                        <td class="py-2 pl-4 font-mono text-xs">{{ $line['batch_no'] ?? '—' }}</td>
                        <td class="py-2 text-xs">{{ $line['expiry'] ?? '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            <div class="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-600">
                @if($shipment)
                Shipment #{{ $shipment->id }} · <span class="capitalize">{{ str_replace('_', ' ', $shipment->status) }}</span>
                · {{ $shipment->carrier_code }}@if($shipment->awb_no) · AWB <span class="font-mono">{{ $shipment->awb_no }}</span>@endif
                @if($shipment->gateway !== 'manual')
                · {{ ucfirst($shipment->gateway) }}@if($shipment->gateway_shipment_id) shipment <span class="font-mono">{{ $shipment->gateway_shipment_id }}</span>@endif
                @if($shipment->courier_status) · courier says <span class="font-medium">{{ $shipment->courier_status }}</span>@endif
                @if($shipment->label_url && str_starts_with($shipment->label_url, 'https://')) · <a href="{{ $shipment->label_url }}" target="_blank" rel="noopener noreferrer" class="text-brand-700 underline">Shipping label</a>@endif
                @endif
                @else
                No shipment recorded for this order.
                @endif
            </div>
        </x-ui.card>
    </div>

    <div class="space-y-6">
        <x-ui.card padding="p-5">
            <p class="text-xs uppercase tracking-wider text-gray-600 mb-3">Timeline</p>
            @include('shop.orders._timeline', ['order' => $order])
        </x-ui.card>

        <x-ui.card padding="p-5">
            <p class="text-xs uppercase tracking-wider text-gray-600 mb-2">Status</p>
            <p class="text-lg font-semibold text-gray-900 capitalize">{{ str_replace('_', ' ', $order->status) }}</p>
            <div class="mt-4 space-y-1 text-xs text-gray-600">
                @if($order->placed_at)<div>Placed {{ $order->placed_at->format('d M Y H:i') }}</div>@endif
                @if($order->paid_at)<div>Paid {{ $order->paid_at->format('d M Y H:i') }}</div>@endif
                @if($order->shipped_at)<div>Shipped {{ $order->shipped_at->format('d M Y H:i') }}</div>@endif
                @if($order->ship_carrier || $order->ship_tracking_no)<div class="text-gray-700">Courier: {{ $order->ship_carrier ?: '—' }}@if($order->ship_tracking_no) · Tracking: <span class="font-mono">{{ $order->ship_tracking_no }}</span>@endif</div>@endif
                @if($order->cancelled_at)<div class="text-red-700 font-medium">Cancelled {{ $order->cancelled_at->format('d M Y H:i') }}</div>@endif
                @if($order->delivered_at)<div class="text-green-700 font-medium">Delivered {{ $order->delivered_at->format('d M Y H:i') }}</div>@endif
            </div>
        </x-ui.card>

        @if($order->isOffline() && $order->offlinePayment)
        @include('admin.commerce.offline-orders._payment-card', ['order' => $order, 'offlinePayment' => $order->offlinePayment, 'offlineOrdersOn' => $offlineOrdersOn])
        @else
        <x-ui.card padding="p-5">
            <p class="text-xs uppercase tracking-wider text-gray-600 mb-2">Payment</p>
            <p class="text-sm font-medium text-gray-900">Online</p>
            @if($paymentIntent)
            <p class="text-xs text-gray-600 mt-2">
                {{ ucfirst($paymentIntent->gateway) }} · <span class="capitalize">{{ str_replace('_', ' ', $paymentIntent->status) }}</span>
            </p>
            <a href="{{ route('admin.payments.show', $paymentIntent) }}"
               class="inline-flex items-center gap-1 mt-2 text-sm font-medium text-brand-700 hover:text-brand-800">
                <x-lucide-credit-card class="w-4 h-4" />
                View payment #{{ $paymentIntent->id }}
            </a>
            @else
            <p class="text-xs text-gray-600 mt-2">No gateway payment recorded for this order.</p>
            @endif
        </x-ui.card>
        @endif

        {{-- Invoice. Support needs to see the document the buyer was issued,
             and to re-issue it when generation failed at checkout (QA F101). --}}
        <x-ui.card padding="p-5">
            <p class="text-xs uppercase tracking-wider text-gray-600 mb-2">Invoice</p>
            @if($invoice)
            <p class="text-sm font-mono text-gray-900">{{ $invoice->invoice_no }}</p>
            <p class="text-xs text-gray-600 mt-1">
                Issued {{ $invoice->issued_at?->format('d M Y H:i') ?? '—' }} ·
                ₹{{ \App\Modules\Shared\Support\IndianNumber::format($invoice->total_paise / 100, 2) }}
            </p>
            @else
            <p class="text-sm italic text-gray-600">No invoice issued yet.</p>
            @endif

            @can('finance.record')
            @if($order->paid_at)
            <form method="POST" action="{{ route('admin.payments.invoices.generate', $order) }}" class="mt-3"
                  data-confirm="{{ $invoice ? 'Issue a replacement invoice for this order?' : 'Issue the invoice for this order?' }}"
                  data-confirm-title="Confirm invoice"
                  data-confirm-impact="Impact: consumes the next invoice number and records the document against this order. It is logged against your user and cannot be undone.">
                @csrf
                <button type="submit" class="w-full py-2 rounded-lg border border-gray-300 hover:bg-gray-50 text-sm font-medium text-gray-800">
                    {{ $invoice ? 'Re-issue invoice' : 'Generate invoice' }}
                </button>
            </form>
            @else
            <p class="text-xs text-gray-600 mt-2">An invoice is issued only once the order is paid.</p>
            @endif
            @endcan
        </x-ui.card>

        @if($order->coolingOff)
        <x-ui.card padding="p-5">
            <p class="text-xs uppercase tracking-wider text-gray-600 mb-2">Cooling-Off</p>
            <p class="text-sm"><strong class="text-gray-900">{{ $order->coolingOff->daysRemaining() }} days</strong> remaining</p>
            <p class="text-xs text-gray-600 mt-1">Closes {{ $order->coolingOff->ends_at->format('d M Y') }}</p>
            <p class="text-xs text-gray-600 mt-1">Status: <span class="font-mono">{{ $order->coolingOff->status }}</span></p>
        </x-ui.card>
        @endif

        <x-ui.card padding="p-5">
            <p class="text-xs uppercase tracking-wider text-gray-600 mb-2">Customer</p>
            <p class="text-sm font-medium text-gray-900">{{ $order->customer->display_name ?? '—' }}</p>
            <p class="text-xs text-gray-600 break-all">{{ $order->customer->email_enc ?? '—' }}</p>
        </x-ui.card>

        <x-ui.card padding="p-5">
            <p class="text-xs uppercase tracking-wider text-gray-600 mb-2">Attribution</p>
            @if($order->attributed_distributor_id)
            <p class="text-sm font-mono text-brand-700">{{ $order->distributor->adn ?? '#' . $order->attributed_distributor_id }}</p>
            <p class="text-xs text-gray-600 mt-1">Source: {{ $order->attribution_source }}</p>
            @else
            <p class="text-sm italic text-gray-600">House sale (no referrer)</p>
            @endif
            @if($order->self_consumption)
            <p class="text-xs text-amber-700 mt-1">Self-consumption (BV only)</p>
            @endif
        </x-ui.card>

        <x-ui.card padding="p-5">
            @if($order->isCollection())
                {{-- R-47: the centre the buyer chose was written nowhere any
                     member of staff could see it. A collection order holds no
                     delivery address by design, so this panel is the only place
                     the destination appears. --}}
                <p class="text-xs uppercase tracking-wider text-gray-600 mb-2">Collection</p>
                @if($order->areteCenter)
                    <p class="text-sm text-gray-700">
                        <span class="font-medium text-gray-900">{{ $order->areteCenter->name }}</span><br>
                        {{ $order->areteCenter->displayAddress() }}
                        @if($order->areteCenter->contact_number)<br>{{ $order->areteCenter->contact_number }}@endif
                    </p>
                    <p class="text-xs text-gray-600 mt-3">Consign the parcel to this centre, not to the buyer.</p>
                @else
                    <p class="text-sm text-red-700">
                        This order was placed for collection, but the centre no longer exists.
                    </p>
                    <p class="text-xs text-gray-600 mt-2">
                        There is no delivery address to fall back on. Agree a centre or an address with the buyer before dispatching.
                    </p>
                @endif
                <p class="text-xs uppercase tracking-wider text-gray-600 mt-4 mb-1">Collected by</p>
                <p class="text-sm text-gray-700">{{ $order->ship_name }}<br>{{ $order->ship_phone_e164 }}</p>
            @else
                <p class="text-xs uppercase tracking-wider text-gray-600 mb-2">Shipping</p>
                <p class="text-sm text-gray-700">
                    {{ $order->ship_name }}<br>
                    {{ $order->ship_phone_e164 }}<br>
                    {{ $order->ship_line1 }}@if($order->ship_line2), {{ $order->ship_line2 }}@endif<br>
                    {{ $order->ship_city }}, {{ $order->ship_state }} {{ $order->ship_pincode }}
                </p>
            @endif
        </x-ui.card>
    </div>
</div>

@endsection
