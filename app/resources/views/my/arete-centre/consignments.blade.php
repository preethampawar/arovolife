@extends('layouts.app')
@section('title', 'Parcels for my centre')

@section('content')

<div>
    <div class="flex items-center justify-between gap-3 mb-2">
        <h1 class="text-2xl font-bold">Parcels for my centre</h1>
        <a href="{{ route('my.adc.status') }}" class="text-sm font-medium text-brand-700 hover:underline">My centre</a>
    </div>
    <p class="text-sm text-gray-600 mb-6">
        When a parcel reaches your centre, confirm it here. The buyer is then sent a six-digit collection code.
        Hand the parcel over only when the buyer tells you that code. An uncollected parcel may wait {{ $maxDwellDays }} days; after that, arovolife will arrange its return.
    </p>

    @if(session('success'))
    <div class="rounded-lg border border-green-200 bg-green-50 p-4 mb-4 text-sm text-green-800">{{ session('success') }}</div>
    @endif
    @if($errors->has('consignment'))
    <div class="rounded-lg border border-red-200 bg-red-50 p-4 mb-4 text-sm text-red-800">{{ $errors->first('consignment') }}</div>
    @endif

    {{-- Waiting at the centre: the handover form --}}
    <section class="mb-8">
        <h2 class="text-lg font-semibold mb-3">Waiting for the buyer ({{ $groups['waiting']->count() }})</h2>
        @forelse($groups['waiting'] as $order)
        @php $waitingDays = $order->shipment?->at_centre_at ? (int) $order->shipment->at_centre_at->diffInDays(now()) : null; @endphp
        <div class="rounded-xl border border-gray-200 bg-white p-4 mb-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="text-sm">
                    <p class="font-semibold font-mono">{{ $order->order_no }}</p>
                    <p class="text-gray-700">For {{ \Illuminate\Support\Str::before(trim((string) $order->customer?->display_name), ' ') ?: 'the buyer' }} · {{ (int) $order->item_count }} {{ (int) $order->item_count === 1 ? 'item' : 'items' }}</p>
                    @if($showCentre)<p class="text-xs text-gray-500">{{ $order->areteCenter?->name }}</p>@endif
                    <p class="text-xs text-gray-500">
                        Arrived {{ $order->shipment?->at_centre_at?->format('d M Y') ?? '—' }}@if($waitingDays !== null) · waiting {{ $waitingDays }} {{ $waitingDays === 1 ? 'day' : 'days' }}@endif
                    </p>
                    @if($waitingDays !== null && $waitingDays > $maxDwellDays)
                    <p class="text-xs font-medium text-amber-700">Past the {{ $maxDwellDays }}-day limit. arovolife will contact you about returning it.</p>
                    @endif
                </div>
                <form method="POST" action="{{ route('my.adc.consignments.handover', $order->order_no) }}" class="flex items-end gap-2"
                      data-confirm="Hand over order {{ $order->order_no }}?"
                      data-confirm-title="Hand over the parcel"
                      data-confirm-impact="Impact: the parcel is recorded as collected by the buyer and their 30-day cooling-off period starts now. Give it only to the person who told you the code.">
                    @csrf
                    <label class="text-xs text-gray-600">
                        Buyer's collection code
                        <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="off"
                               class="mt-1 block w-32 rounded-lg border-gray-300 font-mono text-sm" title="The six-digit code the buyer received by email">
                    </label>
                    <button type="submit" class="rounded-lg bg-brand-700 hover:bg-brand-800 text-white font-medium px-4 py-2 text-sm">Hand over</button>
                </form>
            </div>
            @if($errors->has('code') && session('handover_order') === $order->order_no)
            <p class="mt-2 text-sm text-red-700">{{ $errors->first('code') }}</p>
            @endif
        </div>
        @empty
        <p class="text-sm text-gray-500">No parcels are waiting at your centre.</p>
        @endforelse
    </section>

    {{-- On the way: confirm arrival --}}
    <section class="mb-8">
        <h2 class="text-lg font-semibold mb-3">On the way to you ({{ $groups['on_the_way']->count() }})</h2>
        @forelse($groups['on_the_way'] as $order)
        <div class="rounded-xl border border-gray-200 bg-white p-4 mb-3 flex flex-wrap items-center justify-between gap-3">
            <div class="text-sm">
                <p class="font-semibold font-mono">{{ $order->order_no }}</p>
                <p class="text-gray-700">For {{ \Illuminate\Support\Str::before(trim((string) $order->customer?->display_name), ' ') ?: 'the buyer' }} · {{ (int) $order->item_count }} {{ (int) $order->item_count === 1 ? 'item' : 'items' }}</p>
                @if($showCentre)<p class="text-xs text-gray-500">{{ $order->areteCenter?->name }}</p>@endif
                <p class="text-xs text-gray-500">Sent {{ ($order->shipment?->consigned_at ?? $order->shipped_at)?->format('d M Y') ?? '—' }}</p>
            </div>
            <form method="POST" action="{{ route('my.adc.consignments.received', $order->order_no) }}"
                  data-confirm="Confirm order {{ $order->order_no }} has arrived?"
                  data-confirm-title="Parcel arrived"
                  data-confirm-impact="Impact: the buyer is emailed a collection code and told the parcel is ready at your centre. Confirm only once you are holding it.">
                @csrf
                <button type="submit" class="rounded-lg border border-brand-700 text-brand-700 hover:bg-brand-50 font-medium px-4 py-2 text-sm">It has arrived</button>
            </form>
        </div>
        @empty
        <p class="text-sm text-gray-500">Nothing is on the way to your centre.</p>
        @endforelse
    </section>

    {{-- Collected in the last 30 days --}}
    <section>
        <h2 class="text-lg font-semibold mb-3">Collected in the last 30 days ({{ $groups['collected']->count() }})</h2>
        @forelse($groups['collected'] as $order)
        <div class="flex items-center justify-between border-b border-gray-100 py-2 text-sm">
            <span class="font-mono">{{ $order->order_no }}</span>
            <span class="text-gray-500">Collected {{ $order->shipment?->collected_at?->format('d M Y') ?? '—' }}</span>
        </div>
        @empty
        <p class="text-sm text-gray-500">No parcels collected recently.</p>
        @endforelse
    </section>
</div>

@endsection
