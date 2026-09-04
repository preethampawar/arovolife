@extends('layouts.shop')
@section('title', 'Payment unavailable')

@section('content')
<div class="max-w-lg mx-auto">
    <div class="bg-white rounded-2xl border border-gray-200 p-8 text-center">
        <div class="w-14 h-14 mx-auto mb-4 rounded-full bg-amber-50 flex items-center justify-center">
            <x-lucide-clock class="w-8 h-8 text-amber-600" />
        </div>
        @if ($reason === 'expired')
            <h1 class="text-xl font-bold text-gray-900 mb-2">This payment window has closed</h1>
            <p class="text-sm text-gray-600">Order <span class="font-mono text-gray-900">{{ $order->order_no }}</span> was held for a limited time and the payment was not completed. Nothing has been charged.</p>
            <p class="text-sm text-gray-600 mt-2">The items will be released back to the shop shortly. You can place a new order any time.</p>
        @elseif ($reason === 'closed')
            <h1 class="text-xl font-bold text-gray-900 mb-2">This order is no longer awaiting payment</h1>
            <p class="text-sm text-gray-600">Order <span class="font-mono text-gray-900">{{ $order->order_no }}</span> is {{ str_replace('_', ' ', $order->status) }}. If you believe a payment was deducted, it will be matched to the order or returned automatically.</p>
        @else
            <h1 class="text-xl font-bold text-gray-900 mb-2">Payment is unavailable right now</h1>
            <p class="text-sm text-gray-600">We could not start the payment for order <span class="font-mono text-gray-900">{{ $order->order_no }}</span>. Nothing has been charged. Please try again in a few minutes.</p>
        @endif
        <div class="mt-6 flex flex-col sm:flex-row gap-3 justify-center">
            @if ($reason === 'gateway')
                <a href="{{ route('shop.pay', $order->order_no) }}" class="inline-block px-6 py-2.5 rounded-full bg-brand-700 hover:bg-brand-800 text-white font-semibold text-sm">Try again</a>
            @endif
            <a href="{{ route('shop.index') }}" class="inline-block px-6 py-2.5 rounded-full border border-gray-300 text-gray-800 font-semibold text-sm hover:bg-gray-50">Back to the shop</a>
        </div>
    </div>
</div>
@endsection
