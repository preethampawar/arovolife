@extends('layouts.app')
@section('title', 'Return order '.$order->order_no)

@section('content')
<div class="max-w-2xl mx-auto px-4 py-8">
    <a href="{{ route('orders.show', $order->order_no) }}" class="text-sm text-brand-600 hover:text-brand-700">← Back to order</a>

    <h1 class="text-2xl font-bold text-gray-900 mt-3 mb-2">Return order <span class="font-mono text-brand-600">{{ $order->order_no }}</span></h1>

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
                    <option value="cooling_off" {{ old('reason') === 'cooling_off' ? 'selected' : '' }}>
                        Cooling-off cancellation (within 30 days of delivery — full refund, immediate)
                    </option>
                    <option value="damage" {{ old('reason') === 'damage' ? 'selected' : '' }}>
                        Damaged on arrival (within 10 days of delivery)
                    </option>
                    <option value="dissatisfaction" {{ old('reason') === 'dissatisfaction' ? 'selected' : '' }}>
                        Dissatisfied with product (within 30 days of delivery)
                    </option>
                    <option value="general_buyback" {{ old('reason') === 'general_buyback' ? 'selected' : '' }}>
                        General buyback (unused/saleable goods only)
                    </option>
                    <option value="termination_buyback" {{ old('reason') === 'termination_buyback' ? 'selected' : '' }}>
                        Termination / account closure buyback
                    </option>
                </select>
                @error('reason')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
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
                    <li><strong>Cooling-off:</strong> refund is initiated immediately, credited to your original payment method within 7 working days.</li>
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
