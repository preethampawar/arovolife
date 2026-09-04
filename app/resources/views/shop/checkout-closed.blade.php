@extends('layouts.shop')
@section('title', 'Checkout unavailable')

@section('content')
<div class="max-w-lg mx-auto">
    <div class="bg-white rounded-2xl border border-gray-200 p-8 text-center">
        <div class="w-14 h-14 mx-auto mb-4 rounded-full bg-amber-50 flex items-center justify-center">
            <x-lucide-wrench class="w-8 h-8 text-amber-600" />
        </div>
        <h1 class="text-xl font-bold text-gray-900 mb-2">Checkout is temporarily unavailable</h1>
        <p class="text-sm text-gray-600">We are unable to take new orders at the moment. Your cart has been kept, and nothing has been charged.</p>
        <p class="text-sm text-gray-600 mt-2">Existing orders, returns and cancellations are unaffected — see <a href="{{ route('orders.index') }}" class="text-brand-700 hover:underline">My Orders</a>.</p>
        <a href="{{ route('shop.cart') }}" class="inline-block mt-6 px-6 py-2.5 rounded-full border border-gray-300 text-gray-800 font-semibold text-sm hover:bg-gray-50">Back to your cart</a>
    </div>
</div>
@endsection
