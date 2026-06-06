{{-- Order status pill. Single source of truth: App\Modules\Commerce\Support\OrderStatusBadge.
     Usage: @include('partials.order-status-badge', ['status' => $order->status]) --}}
@php($orderStatus = $status ?? '')
<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border whitespace-nowrap {{ \App\Modules\Commerce\Support\OrderStatusBadge::classes($orderStatus) }}">{{ \App\Modules\Commerce\Support\OrderStatusBadge::label($orderStatus) }}</span>
