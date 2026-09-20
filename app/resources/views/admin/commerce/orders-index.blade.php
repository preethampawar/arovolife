@extends('admin.layouts.admin')
@section('title', 'Orders')
@section('heading', 'Orders')

@section('content')

@php
    use App\Modules\Commerce\Support\Bv;
    use App\Modules\Shared\Support\IndianNumber;
@endphp

@if($defaultedToToday)
<p class="mb-4 text-xs text-gray-600">
    Showing orders placed today.
    <a href="{{ request()->fullUrlWithQuery(['placed_from' => '', 'placed_to' => '', 'page' => null]) }}"
       class="font-medium text-brand-700 hover:text-brand-800 hover:underline">Show all dates</a>
</p>
@endif

<div class="flex items-center gap-3 mb-6 flex-wrap">
    <a href="{{ request()->fullUrlWithQuery(['status' => null, 'page' => null]) }}"
       class="px-3 py-1 rounded-full text-xs font-medium border {{ !request()->query('status') ? 'bg-brand-700 text-white border-brand-500' : 'bg-white text-gray-700 border-gray-200 hover:border-brand-500' }}">
        All
    </a>
    @foreach(\App\Modules\Commerce\Support\OrderStatusBadge::FILTERABLE as $s)
    <a href="{{ request()->fullUrlWithQuery(['status' => $s, 'page' => null]) }}"
       class="px-3 py-1 rounded-full text-xs font-medium border {{ request()->query('status') === $s ? 'bg-brand-700 text-white border-brand-500' : \App\Modules\Commerce\Support\OrderStatusBadge::classes($s) }}">
        {{ \App\Modules\Commerce\Support\OrderStatusBadge::label($s) }} @if(isset($statusCounts[$s])) ({{ $statusCounts[$s] }}) @endif
    </a>
    @endforeach
</div>

<x-filter-bar :filters="$filters" />

{{-- The figures cover the whole filtered set, not the page of 25: every tile
     is built from the same clauses as the rows, so the status chip and the
     date window move the numbers with them. --}}
<x-ui.card flush class="mb-6">
    <x-ui.stat-row :columns="5">
        <x-ui.stat flush :label-lines="2" label="Orders"
                   :value="IndianNumber::format($summary['orders'])"
                   :hint="$defaultedToToday ? 'Placed today' : 'Matching these filters'" />
        <x-ui.stat flush :label-lines="2" label="Order value"
                   :value="IndianNumber::rupees($summary['total_paise'])"
                   hint="Payable in money, after wallet" />
        <x-ui.stat flush :label-lines="2" label="GST"
                   :value="IndianNumber::rupees($summary['gst_paise'])"
                   hint="Included in the value beside it" />
        <x-ui.stat flush :label-lines="2" label="Repurchase wallet"
                   :value="IndianNumber::rupees($summary['repurchase_wallet_paise'])"
                   hint="Settled with credit, not money" />
        <x-ui.stat flush :label-lines="2" label="BV"
                   :value="Bv::format($summary['bv_paise'])"
                   hint="Business Volume on these orders" />
    </x-ui.stat-row>
</x-ui.card>

<x-ui.card flush>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase tracking-wider w-12">S.No</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase tracking-wider">Order #</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase tracking-wider">Customer</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase tracking-wider">Attribution</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase tracking-wider">Total</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-600 uppercase tracking-wider">Repurchase Wallet</th>
                    <th class="text-right px-4 py-3 text-xs font-medium text-gray-600 uppercase tracking-wider">BV</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase tracking-wider">Status</th>
                    <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase tracking-wider">Placed</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($orders as $o)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-600 tabular-nums">{{ ($orders->firstItem() ?? 1) + $loop->index }}</td>
                    <td class="px-4 py-3 font-mono text-brand-700 font-medium">
                        <a href="{{ route('admin.commerce.orders.show', $o) }}" class="hover:text-brand-800 hover:underline">{{ $o->order_no }}</a>
                    </td>
                    <td class="px-4 py-3 text-gray-700">
                        @if($o->customer?->distributor_id)
                            <a href="{{ route('admin.distributors.show', $o->customer->distributor_id) }}" class="text-brand-700 hover:text-brand-800 hover:underline">{{ $o->customer->display_name }}</a>
                        @else
                            {{ $o->customer->display_name ?? '—' }}
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-600">
                        @if($o->attributed_distributor_id)
                            <a href="{{ route('admin.distributors.show', $o->attributed_distributor_id) }}" class="font-mono text-brand-700 hover:text-brand-800 hover:underline">{{ $o->distributor->adn ?? '#' . $o->attributed_distributor_id }}</a>
                        @else
                            <span class="italic text-gray-600">house</span>
                        @endif
                        <span class="block text-gray-600">{{ $o->attribution_source }}</span>
                    </td>
                    <td class="px-4 py-3 font-semibold">{{ $o->displayTotal() }}</td>
                    <td class="px-4 py-3 text-right text-gray-700 whitespace-nowrap">
                        @if($repurchaseWalletByOrder->get($o->id))
                            ₹{{ \App\Modules\Shared\Support\IndianNumber::format($repurchaseWalletByOrder->get($o->id) / 100, 2) }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right text-brand-700 whitespace-nowrap" title="Total Business Volume for this order">
                        {{ \App\Modules\Shared\Support\IndianNumber::format($o->bvTotalPaise() / 100, 0) }} BV
                    </td>
                    <td class="px-4 py-3">@include('partials.order-status-badge', ['status' => $o->status])</td>
                    <td class="px-4 py-3 text-xs text-gray-600">
                        {{ $o->placed_at?->format('d M Y H:i') ?? '—' }}
                    </td>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.commerce.orders.show', $o) }}" class="text-xs text-brand-700 hover:text-brand-800">View {{ svg('lucide-chevron-right', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }}</a>
                    </td>
                </tr>
                @empty
                <x-ui.empty-state colspan="10"
                                  :title="$defaultedToToday ? 'No orders placed today.' : 'No orders match these filters.'" />
                @endforelse
            </tbody>
        </table>
    </div>
    @if($orders->hasPages())
    <div class="px-4 py-4 border-t border-gray-200">{{ $orders->links() }}</div>
    @endif
</x-ui.card>

@endsection
