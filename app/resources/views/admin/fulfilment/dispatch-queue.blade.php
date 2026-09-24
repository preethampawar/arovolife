@extends('admin.layouts.admin')
@section('title', 'Dispatch queue')
@section('heading', 'Dispatch queue')

@section('content')

<p class="mb-4 text-sm text-gray-600">
    Paid orders waiting to leave the warehouse, oldest first. Open an order to pack it and choose how it is sent.
    @if($shiprocketOffered)
    "Shiprocket-ready" means every product on the order has a weight and a packed size.
    @endif
</p>

<x-ui.card flush>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase tracking-wider w-12">S.No</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase tracking-wider">Order #</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase tracking-wider">Placed</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase tracking-wider">Delivery</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-600 uppercase tracking-wider">Items</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase tracking-wider">Status</th>
                    @if($shiprocketOffered)
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase tracking-wider">Shiprocket-ready</th>
                    @endif
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($orders as $o)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-600 tabular-nums">{{ ($orders->firstItem() ?? 1) + $loop->index }}</td>
                    <td class="px-4 py-3 font-mono text-brand-700 font-medium">
                        <a href="{{ route('admin.commerce.orders.show', $o) }}" class="hover:text-brand-800 hover:underline">{{ $o->order_no }}</a>
                        @if(isset($pendingBookings[$o->id]))
                        <x-ui.badge tone="danger" class="ml-1 align-middle" title="A courier booking exists or got no reply — open the order before dispatching.">{{ ucfirst($pendingBookings[$o->id]) }} booking</x-ui.badge>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-600 whitespace-nowrap">{{ $o->placed_at?->timezone('Asia/Kolkata')->format('d M Y, H:i') }}</td>
                    <td class="px-4 py-3 text-gray-700">
                        @if($o->isCollection())
                        Collect at {{ $o->areteCenter?->name ?? 'a centre that no longer exists' }}
                        @else
                        Ship to {{ $o->ship_city }}{{ $o->ship_pincode ? ' '.$o->ship_pincode : '' }}
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ $o->items_count }}</td>
                    <td class="px-4 py-3">@include('partials.order-status-badge', ['status' => $o->status])</td>
                    @if($shiprocketOffered)
                    <td class="px-4 py-3 text-xs">
                        @if(($gapCounts[$o->id] ?? 0) === 0)
                        <span class="text-green-700">Ready</span>
                        @else
                        <span class="text-amber-700">{{ $gapCounts[$o->id] }} {{ Str::plural('product', $gapCounts[$o->id]) }} missing size or weight</span>
                        @endif
                    </td>
                    @endif
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('admin.commerce.orders.show', $o) }}" class="text-brand-700 hover:underline text-xs font-medium">Open</a>
                    </td>
                </tr>
                @empty
                <x-ui.empty-state :colspan="$shiprocketOffered ? 8 : 7" title="Nothing waiting to be dispatched." />
                @endforelse
            </tbody>
        </table>
    </div>
    @if($orders->hasPages())
    <div class="px-4 py-4 border-t border-gray-200">{{ $orders->links() }}</div>
    @endif
</x-ui.card>

@endsection
