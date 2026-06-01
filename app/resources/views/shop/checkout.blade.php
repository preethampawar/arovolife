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

<form method="POST" action="{{ route('shop.checkout.place') }}" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    @csrf

    <div class="lg:col-span-2 space-y-5">
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <h2 class="font-semibold text-gray-900 mb-4">Your Details</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Full Name *</label>
                    <input name="buyer_name" type="text" required value="{{ old('buyer_name') }}"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Email *</label>
                    <input name="buyer_email" type="email" required value="{{ old('buyer_email') }}"
                        placeholder="you@example.com"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Mobile *</label>
                    <div class="flex">
                        <span class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-gray-300 bg-gray-50 text-gray-500 text-sm">+91</span>
                        <input name="buyer_phone" type="tel" required value="{{ old('buyer_phone') }}"
                            maxlength="10" pattern="[6-9][0-9]{9}" placeholder="9876543210"
                            class="flex-1 rounded-r-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <h2 class="font-semibold text-gray-900 mb-4">Shipping Address</h2>
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

        @php $couponDiscount = $couponDiscount ?? 0; $finalTotal = max(0, $cart->totalPaise() - $couponDiscount); @endphp
        <div class="space-y-2 text-sm mb-4 pb-4 border-b border-gray-200">
            <div class="flex justify-between"><span class="text-gray-600">Subtotal</span><span class="font-medium">₹{{ number_format(($cart->subtotalPaise() - $cart->gstPaise()) / 100, 2) }}</span></div>
            <div class="flex justify-between"><span class="text-gray-600">GST</span><span class="font-medium">₹{{ number_format($cart->gstPaise() / 100, 2) }}</span></div>
            <div class="flex justify-between"><span class="text-gray-600">Shipping</span><span class="font-medium text-green-700">Free</span></div>
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
</script>

@endsection
