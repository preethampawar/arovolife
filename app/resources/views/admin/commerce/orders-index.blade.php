@extends('admin.layouts.admin')
@section('title', 'Orders')
@section('heading', 'Orders')

@section('content')

<div class="flex items-center gap-3 mb-6 flex-wrap">
    <a href="{{ route('admin.commerce.orders.index') }}"
       class="px-3 py-1 rounded-full text-xs font-medium border {{ !request()->query('status') ? 'bg-brand-500 text-white border-brand-500' : 'bg-white text-gray-700 border-gray-200 hover:border-brand-500' }}">
        All
    </a>
    @foreach(\App\Modules\Commerce\Support\OrderStatusBadge::FILTERABLE as $s)
    <a href="{{ route('admin.commerce.orders.index', ['status' => $s]) }}"
       class="px-3 py-1 rounded-full text-xs font-medium border {{ request()->query('status') === $s ? 'bg-brand-500 text-white border-brand-500' : \App\Modules\Commerce\Support\OrderStatusBadge::classes($s) }}">
        {{ \App\Modules\Commerce\Support\OrderStatusBadge::label($s) }} @if(isset($statusCounts[$s])) ({{ $statusCounts[$s] }}) @endif
    </a>
    @endforeach
</div>

<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider w-12">S.No</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Order #</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Attribution</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">BV</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Placed</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($orders as $o)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-600 tabular-nums">{{ ($orders->firstItem() ?? 1) + $loop->index }}</td>
                    <td class="px-4 py-3 font-mono text-brand-600 font-medium">{{ $o->order_no }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $o->customer->display_name ?? '—' }}</td>
                    <td class="px-4 py-3 text-xs text-gray-500">
                        @if($o->attributed_distributor_id)
                            <span class="font-mono">{{ $o->distributor->adn ?? '#' . $o->attributed_distributor_id }}</span>
                        @else
                            <span class="italic text-gray-400">house</span>
                        @endif
                        <span class="block text-gray-400">{{ $o->attribution_source }}</span>
                    </td>
                    <td class="px-4 py-3 font-semibold">{{ $o->displayTotal() }}</td>
                    <td class="px-4 py-3 text-right text-brand-700 whitespace-nowrap" title="Total Business Volume for this order">
                        {{ number_format($o->bvTotalPaise() / 100, 0) }} BV
                    </td>
                    <td class="px-4 py-3">@include('partials.order-status-badge', ['status' => $o->status])</td>
                    <td class="px-4 py-3 text-xs text-gray-500">
                        {{ $o->placed_at?->format('d M Y H:i') ?? '—' }}
                    </td>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.commerce.orders.show', $o) }}" class="text-xs text-brand-600 hover:text-brand-700">View →</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" class="px-4 py-8 text-center text-sm text-gray-500">No orders yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($orders->hasPages())
    <div class="px-4 py-4 border-t border-gray-200">{{ $orders->links() }}</div>
    @endif
</div>

@endsection
