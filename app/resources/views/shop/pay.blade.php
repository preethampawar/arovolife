@extends('layouts.shop')
@section('title', 'Pay for your order')

@section('content')
<div class="max-w-lg mx-auto">
    <div class="bg-white rounded-2xl border border-gray-200 p-8">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 rounded-full bg-brand-50 flex items-center justify-center">
                <x-lucide-lock class="w-5 h-5 text-brand-700" />
            </div>
            <div>
                <h1 class="text-xl font-bold text-gray-900">Complete your payment</h1>
                <p class="text-sm text-gray-600">Order <span class="font-mono text-gray-900">{{ $order->order_no }}</span></p>
            </div>
        </div>

        @if (session('payment_error'))
            <div class="mb-5 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="alert">
                {{ session('payment_error') }}
            </div>
        @endif

        <div class="flex justify-between items-baseline mb-6 pb-6 border-b border-gray-200">
            <span class="text-gray-700">Amount to pay</span>
            <span class="text-2xl font-bold text-gray-900">₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->total_paise / 100, 2) }}</span>
        </div>

        {{-- Idle state: open the Razorpay Checkout modal. --}}
        <div id="pay-idle" @if($confirming) hidden @endif>
            <button type="button" id="pay-button"
                class="block w-full text-center py-3 rounded-full bg-brand-700 hover:bg-brand-800 text-white font-semibold text-sm transition-colors">
                Pay ₹{{ \App\Modules\Shared\Support\IndianNumber::format($order->total_paise / 100, 2) }} securely
            </button>
            <p class="text-xs text-gray-600 mt-3 text-center">
                Card, UPI or netbanking. Payments are processed by Razorpay; your card details never reach arovolife.
            </p>
            <p class="text-xs text-gray-500 mt-2 text-center">
                This order is held for you for <span id="pay-countdown">{{ intdiv($timeoutSeconds, 60) }}</span> more minutes.
            </p>
        </div>

        {{-- Confirming state: the modal closed with a payment id but the order is not yet marked paid. --}}
        <div id="pay-confirming" @unless($confirming) hidden @endunless class="text-center" aria-live="polite">
            <div class="w-10 h-10 mx-auto mb-3 rounded-full border-2 border-brand-700 border-t-transparent animate-spin"></div>
            <p class="font-semibold text-gray-900">Confirming your payment…</p>
            <p class="text-sm text-gray-600 mt-1">This usually takes a few seconds. Please keep this page open.</p>
            <p id="pay-confirming-slow" hidden class="text-sm text-gray-600 mt-4">
                It is taking longer than usual. Your payment is safe — the order will be confirmed automatically once the bank responds,
                and you can check it any time under <a href="{{ route('orders.index') }}" class="text-brand-700 hover:underline">My Orders</a>.
            </p>
        </div>

        {{-- Not-completed state: failed attempt or dismissed modal. --}}
        <div id="pay-retry" hidden>
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 mb-4" role="alert">
                <span id="pay-retry-message">The payment was not completed. You have not been charged.</span>
            </div>
            <button type="button" id="pay-retry-button"
                class="block w-full text-center py-3 rounded-full bg-brand-700 hover:bg-brand-800 text-white font-semibold text-sm transition-colors">
                Try again
            </button>
            <a href="{{ route('shop.index') }}" class="block text-center text-sm text-gray-600 hover:underline mt-3">Back to the shop</a>
        </div>

        <form id="pay-callback" method="POST" action="{{ route('shop.pay.callback', $order->order_no) }}" hidden>
            @csrf
            <input type="hidden" name="razorpay_order_id" value="">
            <input type="hidden" name="razorpay_payment_id" value="">
            <input type="hidden" name="razorpay_signature" value="">
        </form>
    </div>
</div>

<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(function () {
    const idle = document.getElementById('pay-idle');
    const confirming = document.getElementById('pay-confirming');
    const slow = document.getElementById('pay-confirming-slow');
    const retry = document.getElementById('pay-retry');
    const retryMessage = document.getElementById('pay-retry-message');
    const form = document.getElementById('pay-callback');
    const csrf = form.querySelector('input[name=_token]').value;
    const statusUrl = @json(route('shop.pay.status', $order->order_no));
    const failureUrl = @json(route('shop.pay.failure', $order->order_no));
    const timeoutSeconds = {{ (int) $timeoutSeconds }};
    const pollSeconds = {{ (int) $pollSeconds }};

    function show(state) {
        idle.hidden = state !== 'idle';
        confirming.hidden = state !== 'confirming';
        retry.hidden = state !== 'retry';
    }

    function record(kind, error) {
        return fetch(failureUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: JSON.stringify({ kind: kind, error: error || null }),
            keepalive: true,
        }).catch(function () {});
    }

    let pollTimer = null;
    let pollStarted = null;
    function poll() {
        pollStarted = pollStarted || Date.now();
        fetch(statusUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.status === 'paid' && data.redirect) { window.location.replace(data.redirect); return; }
                if (data.status === 'closed') { window.location.reload(); return; }
                if (Date.now() - pollStarted > pollSeconds * 1000) { slow.hidden = false; }
                pollTimer = setTimeout(poll, 3000);
            })
            .catch(function () { pollTimer = setTimeout(poll, 5000); });
    }

    function openCheckout() {
        show('idle');
        const rzp = new Razorpay({
            key: @json($keyId),
            amount: {{ (int) $order->total_paise }},
            currency: 'INR',
            name: 'arovolife',
            description: @json('Order '.$order->order_no),
            order_id: @json($intent->gateway_order_id),
            timeout: timeoutSeconds,
            prefill: {
                name: @json($order->ship_name),
                contact: @json($order->ship_phone_e164),
            },
            notes: { arovolife_order_no: @json($order->order_no) },
            theme: { color: '#0f766e' },
            retry: { enabled: false },
            modal: {
                ondismiss: function () {
                    record('dismissed');
                    retryMessage.textContent = 'The payment window was closed before the payment finished. You have not been charged.';
                    show('retry');
                },
            },
            handler: function (response) {
                show('confirming');
                form.querySelector('input[name=razorpay_order_id]').value = response.razorpay_order_id || '';
                form.querySelector('input[name=razorpay_payment_id]').value = response.razorpay_payment_id || '';
                form.querySelector('input[name=razorpay_signature]').value = response.razorpay_signature || '';
                form.submit();
            },
        });
        rzp.on('payment.failed', function (response) {
            record('failed', response.error);
            retryMessage.textContent = 'That payment did not go through' + (response.error && response.error.description ? ' — ' + response.error.description : '') + '. You have not been charged.';
            show('retry');
        });
        rzp.open();
    }

    document.getElementById('pay-button').addEventListener('click', openCheckout);
    document.getElementById('pay-retry-button').addEventListener('click', openCheckout);

    if (!confirming.hidden) { poll(); }
})();
</script>
@endsection
