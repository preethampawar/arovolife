@extends('layouts.shop')
@section('title', 'Checkout')

@section('content')

<h1 class="text-2xl font-bold mb-6">Checkout</h1>

@if($errors->any())
<div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
    <ul class="text-sm text-red-700 space-y-1 list-disc list-inside">
        @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
    </ul>
</div>
@endif

@if($refAdn)
<div class="mb-4 rounded-lg border border-brand-200 bg-brand-50 p-3 text-sm text-brand-700">
    <strong>Referred by:</strong> {{ $refAdn }}
</div>
@endif

<form method="POST" action="{{ route('shop.checkout.place') }}" class="grid grid-cols-1 lg:grid-cols-3 gap-6"
    data-confirm="Place this order?"
    data-confirm-title="Confirm your order"
    data-confirm-impact="Impact: this places your order with arovolife. For online payment you'll be taken to pay now; for Cash on Delivery you'll pay when it arrives. You can cancel from My Orders any time before it ships, and every order is protected by the 30-day cooling-off return window after delivery.">
    @csrf

    <div class="lg:col-span-2 space-y-5">
        @if($buyerDistributor)
        @php $isReferral = ($buyerDistributor['mode'] ?? 'self') === 'referral'; @endphp
        {{-- Read-only distributor identity. `self` = a logged-in distributor
             buying for themselves (full identity + autofill). `referral` = a
             guest who opened a shared "Easy Purchase" cart — only the sharing
             distributor's ADN + name, informational, not editable. --}}
        <div class="bg-brand-50 rounded-2xl border border-brand-200 p-6"
             data-distributor
             @unless($isReferral)
             data-dist-name="{{ $buyerDistributor['name'] }}"
             data-dist-email="{{ $buyerDistributor['email'] }}"
             data-dist-phone="{{ $buyerDistributor['phone_local'] }}"
             @endunless>
            <div class="flex items-center justify-between gap-3 mb-4">
                <h2 class="font-semibold text-gray-900">Distributor details</h2>
                <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-brand-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd" /></svg>
                    {{ $isReferral ? 'Purchasing through' : 'From your account' }}
                </span>
            </div>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <div class="flex justify-between sm:block">
                    <dt class="text-gray-500">Distributor (ADN)</dt>
                    <dd class="font-mono font-semibold text-gray-900">{{ $buyerDistributor['adn'] }}</dd>
                </div>
                <div class="flex justify-between sm:block">
                    <dt class="text-gray-500">Name</dt>
                    <dd class="font-medium text-gray-900">{{ $buyerDistributor['name'] }}</dd>
                </div>
                @unless($isReferral)
                <div class="flex justify-between sm:block">
                    <dt class="text-gray-500">Email</dt>
                    <dd class="font-medium text-gray-900 break-all">{{ $buyerDistributor['email'] }}</dd>
                </div>
                <div class="flex justify-between sm:block">
                    <dt class="text-gray-500">Mobile</dt>
                    <dd class="font-medium text-gray-900">{{ $buyerDistributor['phone_e164'] }}</dd>
                </div>
                @endunless
            </dl>
            @if($isReferral)
            <p class="mt-3 text-xs text-gray-600">You're buying through this distributor. Enter your own details below for delivery — the order is placed with arovolife and credited to this distributor.</p>
            @endif
        </div>
        @endif

        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
                <h2 class="font-semibold text-gray-900">Customer Details</h2>
                @if($buyerDistributor && ($buyerDistributor['mode'] ?? 'self') === 'self')
                <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                    <input type="checkbox" name="same_as_distributor" id="sameAsDistributor" value="1"
                        {{ old('same_as_distributor') ? 'checked' : '' }}
                        class="rounded text-brand-600 border-gray-300 focus:ring-brand-500">
                    Same as distributor
                </label>
                @endif
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Full Name *</label>
                    <input name="buyer_name" id="buyerName" type="text" required value="{{ old('buyer_name') }}"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent read-only:bg-gray-100 read-only:text-gray-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Email *</label>
                    <input name="buyer_email" id="buyerEmail" type="email" required value="{{ old('buyer_email') }}"
                        placeholder="you@example.com"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent read-only:bg-gray-100 read-only:text-gray-500">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Mobile *</label>
                    <div class="flex">
                        <span class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-gray-300 bg-gray-50 text-gray-500 text-sm">+91</span>
                        <input name="buyer_phone" id="buyerPhone" type="tel" required value="{{ old('buyer_phone') }}"
                            maxlength="10" pattern="[6-9][0-9]{9}" placeholder="9876543210"
                            class="flex-1 rounded-r-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent read-only:bg-gray-100 read-only:text-gray-500">
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <h2 class="font-semibold text-gray-900 mb-4">Shipping Address</h2>

            @auth
            @if(($savedAddresses ?? collect())->isNotEmpty())
            {{-- Saved-address picker: selecting one fills the fields below. --}}
            <div class="mb-5" data-saved-addresses>
                <p class="text-sm font-medium text-gray-700 mb-2">Use a saved address</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @foreach($savedAddresses as $sa)
                    <label class="flex items-start gap-2 rounded-lg border border-gray-200 p-3 cursor-pointer hover:border-brand-400 has-[:checked]:border-brand-500 has-[:checked]:ring-1 has-[:checked]:ring-brand-300">
                        <input type="radio" name="__saved_address" value="{{ $sa->id }}" class="mt-1 text-brand-600 focus:ring-brand-500"
                            data-name="{{ $sa->name }}" data-phone="{{ preg_replace('/^\+91/', '', $sa->phone_e164) }}"
                            data-line1="{{ $sa->line1 }}" data-line2="{{ $sa->line2 }}" data-city="{{ $sa->city }}"
                            data-state="{{ $sa->state }}" data-pincode="{{ $sa->pincode }}"
                            {{ $sa->is_default ? 'checked' : '' }}>
                        <span class="min-w-0">
                            <span class="flex items-center gap-1.5 text-sm font-medium text-gray-900">
                                @if($sa->label)<span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-gray-100 text-gray-700">{{ $sa->label }}</span>@endif
                                {{ $sa->name }}
                            </span>
                            <span class="block text-xs text-gray-600 mt-0.5">{{ $sa->oneLine() }}</span>
                        </span>
                    </label>
                    @endforeach
                    <label class="flex items-center gap-2 rounded-lg border border-dashed border-gray-300 p-3 cursor-pointer hover:border-brand-400 has-[:checked]:border-brand-500 has-[:checked]:ring-1 has-[:checked]:ring-brand-300">
                        <input type="radio" name="__saved_address" value="" class="text-brand-600 focus:ring-brand-500">
                        <span class="text-sm font-medium text-gray-900">＋ Use a new address</span>
                    </label>
                </div>
            </div>
            @endif
            @endauth

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Address Line 1 *</label>
                    <input name="ship_line1" type="text" required value="{{ old('ship_line1') }}"
                        placeholder="House/Flat no., building, street"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Address Line 2</label>
                    <input name="ship_line2" type="text" value="{{ old('ship_line2') }}"
                        placeholder="Landmark, area"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">City *</label>
                    <input name="ship_city" type="text" required value="{{ old('ship_city') }}"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">State *</label>
                    <input name="ship_state" type="text" required value="{{ old('ship_state') }}"
                        placeholder="e.g. Karnataka"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Pincode *</label>
                    <input name="ship_pincode" type="text" required value="{{ old('ship_pincode') }}"
                        pattern="\d{6}" maxlength="6" inputmode="numeric"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                </div>
            </div>

            @auth
            {{-- Save this delivery address to the book for next time. --}}
            <div class="mt-4 pt-4 border-t border-gray-100">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="save_address" value="1" checked
                        class="rounded text-brand-600 border-gray-300 focus:ring-brand-500" data-save-address-toggle>
                    Save this delivery address for next time
                </label>
                <div class="mt-2" data-save-address-label>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Label (optional)</label>
                    <input name="address_label" type="text" list="checkout-addr-labels" maxlength="40" placeholder="Home, Work, Office…"
                        value="{{ old('address_label') }}"
                        class="w-full sm:w-64 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <datalist id="checkout-addr-labels">@foreach(($presetLabels ?? []) as $p)<option value="{{ $p }}"></option>@endforeach</datalist>
                </div>
            </div>
            @endauth
        </div>

        {{-- Billing address --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
                <h2 class="font-semibold text-gray-900">Billing Address</h2>
                <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                    <input type="checkbox" name="billing_same" id="billingSame" value="1" {{ old('billing_same', '1') ? 'checked' : '' }}
                        class="rounded text-brand-600 border-gray-300 focus:ring-brand-500">
                    Same as shipping
                </label>
            </div>
            <div id="billingFields" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Address Line 1</label>
                    <input name="bill_line1" type="text" value="{{ old('bill_line1') }}"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Address Line 2</label>
                    <input name="bill_line2" type="text" value="{{ old('bill_line2') }}"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">City</label>
                    <input name="bill_city" type="text" value="{{ old('bill_city') }}"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">State</label>
                    <input name="bill_state" type="text" value="{{ old('bill_state') }}"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Pincode</label>
                    <input name="bill_pincode" type="text" value="{{ old('bill_pincode') }}" pattern="\d{6}" maxlength="6" inputmode="numeric"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                </div>
            </div>
        </div>

        {{-- Payment method --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <h2 class="font-semibold text-gray-900 mb-4">Payment Method</h2>
            <div class="space-y-3">
                @if($onlineEnabled)
                <label class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 cursor-pointer transition-colors has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                    <input type="radio" name="payment_method" value="online" @checked(old('payment_method', $onlineEnabled ? 'online' : 'cod') === 'online') class="text-brand-600 focus:ring-brand-500">
                    <span class="text-sm"><strong class="text-gray-900">Pay online</strong> <span class="text-gray-500">— card / UPI / netbanking</span></span>
                </label>
                @endif
                @if($codEnabled)
                <label class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 cursor-pointer transition-colors has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                    <input type="radio" name="payment_method" value="cod" @checked(old('payment_method', $onlineEnabled ? 'online' : 'cod') === 'cod') class="text-brand-600 focus:ring-brand-500">
                    <span class="text-sm"><strong class="text-gray-900">Cash on Delivery</strong> <span class="text-gray-500">— pay when your order arrives</span></span>
                </label>
                @endif
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <h2 class="font-semibold text-gray-900 mb-4">Consent</h2>
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="accept_terms" value="1" required
                    class="mt-0.5 rounded text-brand-600 border-gray-300 focus:ring-brand-500">
                <span class="text-sm text-gray-700">
                    I accept the <a href="{{ route('content.show', 'terms') }}" target="_blank" class="text-brand-600 hover:underline">Terms of Sale</a>
                    and <a href="{{ route('content.show', 'privacy') }}" target="_blank" class="text-brand-600 hover:underline">Privacy Policy</a>,
                    and understand I have a 30-day cooling-off window per DSR 2021.
                </span>
            </label>
            <label class="flex items-start gap-3 cursor-pointer mt-3">
                <input type="checkbox" name="marketing_opt_in" value="1"
                    class="mt-0.5 rounded text-brand-600 border-gray-300 focus:ring-brand-500">
                <span class="text-sm text-gray-600">Email me about new products and offers (optional).</span>
            </label>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 p-6 h-fit sticky top-20">
        <h2 class="font-semibold text-gray-900 mb-4">Order Summary</h2>

        <div class="space-y-2 mb-4 pb-4 border-b border-gray-200">
            @foreach($cart->items as $item)
            <div class="flex justify-between text-sm">
                <span class="text-gray-700">{{ $item->variant->product->name }} × {{ $item->qty }}</span>
                <span class="font-medium">₹{{ number_format($item->lineTotalPaise() / 100, 2) }}</span>
            </div>
            @endforeach
        </div>

        @php
            $couponDiscount = $couponDiscount ?? 0;
            $shippingPaise = $shippingPaise ?? 0;
            $finalTotal = max(0, $cart->totalPaise() - $couponDiscount) + $shippingPaise;
        @endphp
        @auth
            @php $bvTotal = auth()->user()->distributor ? $cart->bvTotalPaise() : 0; @endphp
            @if($bvTotal > 0)
            {{-- BV at the TOP of the payment summary (distributor-only). A factual
                 point total for the compensation plan, never an earnings figure
                 (hard rule #3). --}}
            <div class="flex justify-between text-sm mb-4 pb-4 border-b border-gray-200 text-brand-700">
                <span class="font-semibold">Total BV</span>
                <span class="font-bold" title="Business Volume — points used in the compensation plan">{{ number_format($bvTotal / 100, 0) }} BV</span>
            </div>
            @endif
        @endauth
        <div class="space-y-2 text-sm mb-4 pb-4 border-b border-gray-200">
            <div class="flex justify-between"><span class="text-gray-600">Subtotal</span><span class="font-medium">₹{{ number_format(($cart->subtotalPaise() - $cart->gstPaise()) / 100, 2) }}</span></div>
            <div class="flex justify-between"><span class="text-gray-600">GST</span><span class="font-medium">₹{{ number_format($cart->gstPaise() / 100, 2) }}</span></div>
            <div class="flex justify-between"><span class="text-gray-600">Shipping</span>
                @if($shippingPaise > 0)<span class="font-medium">₹{{ number_format($shippingPaise / 100, 2) }}</span>
                @else<span class="font-medium text-green-700">Free</span>@endif
            </div>
            @if($couponDiscount > 0)
            <div class="flex justify-between text-green-700"><span>Discount ({{ $cart->coupon->code }})</span><span class="font-medium">−₹{{ number_format($couponDiscount / 100, 2) }}</span></div>
            @endif
        </div>

        <div class="flex justify-between mb-5">
            <span class="font-semibold text-gray-900">Total</span>
            <span class="font-bold text-lg text-gray-900">₹{{ number_format($finalTotal / 100, 2) }}</span>
        </div>

        <button type="submit"
           class="block w-full text-center py-3 rounded-full bg-brand-500 hover:bg-brand-600 text-white font-semibold text-sm transition-colors">
            Place Order
        </button>
        <p class="text-xs text-gray-500 mt-3 text-center">Test gateway — no real money moves.</p>
    </div>
</form>

<script>
    (function () {
        const same = document.getElementById('billingSame');
        const fields = document.getElementById('billingFields');
        if (!same || !fields) return;
        const sync = () => { fields.style.display = same.checked ? 'none' : ''; };
        same.addEventListener('change', sync);
        sync();
    })();

    // Saved-address picker → fill the shipping + contact fields.
    (function () {
        const radios = document.querySelectorAll('input[name="__saved_address"]');
        if (!radios.length) return;
        const set = (name, val) => { const el = document.querySelector('[name="' + name + '"]'); if (el) el.value = val || ''; };

        function fill(radio) {
            if (!radio.value) return; // "Use a new address" — leave fields as-is
            const d = radio.dataset;
            set('buyer_name', d.name);
            set('buyer_phone', d.phone);
            set('ship_line1', d.line1);
            set('ship_line2', d.line2);
            set('ship_city', d.city);
            set('ship_state', d.state);
            set('ship_pincode', d.pincode);
        }

        radios.forEach((r) => r.addEventListener('change', () => fill(r)));
        const checked = document.querySelector('input[name="__saved_address"]:checked');
        if (checked) fill(checked); // prefill from the default on load
    })();

    // Show the label field only when "save this address" is ticked.
    (function () {
        const toggle = document.querySelector('[data-save-address-toggle]');
        const label = document.querySelector('[data-save-address-label]');
        if (!toggle || !label) return;
        const sync = () => { label.style.display = toggle.checked ? '' : 'none'; };
        toggle.addEventListener('change', sync);
        sync();
    })();

    // The same-as-distributor toggle copies the logged-in distributor's
    // identity into the customer fields and locks them; unticking restores
    // editing.
    (function () {
        const toggle = document.getElementById('sameAsDistributor');
        const panel = document.querySelector('[data-distributor]');
        if (!toggle || !panel) return;

        const name = document.getElementById('buyerName');
        const email = document.getElementById('buyerEmail');
        const phone = document.getElementById('buyerPhone');
        const d = panel.dataset;

        const apply = () => {
            if (toggle.checked) {
                name.value = d.distName || '';
                email.value = d.distEmail || '';
                phone.value = d.distPhone || '';
                [name, email, phone].forEach((el) => el.setAttribute('readonly', 'readonly'));
            } else {
                [name, email, phone].forEach((el) => el.removeAttribute('readonly'));
            }
        };

        toggle.addEventListener('change', apply);
        apply(); // honour the restored checkbox state after a validation error
    })();
</script>

@endsection
