@extends('admin.layouts.admin')
@section('title', 'Payments')
@section('heading', 'Payments')

@section('content')

<p class="text-sm text-gray-600 mb-4">Every online payment attempt, what the gateway said about it, and where it stands. Nothing here is marked paid by hand: <em>Sync</em> asks Razorpay and confirms only from its answer.</p>

@if(session('status'))<div class="mb-4 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800">{{ session('status') }}</div>@endif
@error('invoice')<div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ $message }}</div>@enderror

@if($attention > 0)
<a href="{{ route('admin.payments.refunds') }}" class="block mb-5 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 hover:bg-amber-100">
    <strong>{{ $attention }}</strong> refund{{ $attention === 1 ? '' : 's' }} need{{ $attention === 1 ? 's' : '' }} attention — failed at the gateway, held more than {{ \App\Modules\Payments\Support\RefundWorklist::ALERT_AFTER_DAYS }} days without the return being received, or owed on an order with no gateway payment to refund against. Open the unsettled refunds worklist →
</a>
@else
<a href="{{ route('admin.payments.refunds') }}" class="inline-block mb-5 text-sm text-brand-700 hover:underline">Unsettled refunds worklist →</a>
@endif

@if($invoiceGapCount > 0)
<div class="mb-6 rounded-2xl border border-red-300 bg-red-50 p-4 text-sm text-red-900">
    <p class="font-semibold mb-2"><strong>{{ $invoiceGapCount }}</strong> paid order{{ $invoiceGapCount === 1 ? '' : 's' }} without a GST invoice</p>
    <p class="text-xs text-red-800 mb-3">The payment was confirmed but the invoice failed to generate. A tax invoice must be issued for every supply (CGST §31); issue it here — the next consecutive number is allocated, never a duplicate.</p>
    <table class="w-full text-sm bg-white rounded-lg border border-red-200">
        <thead><tr class="text-left text-xs text-gray-600 uppercase border-b border-red-200"><th class="px-3 py-2">Order</th><th class="px-3 py-2">Customer</th><th class="px-3 py-2">Paid</th><th class="px-3 py-2 text-right">Total</th><th></th></tr></thead>
        <tbody class="divide-y divide-red-100">
        @foreach($invoiceGaps as $gapOrder)
            <tr>
                <td class="px-3 py-2"><a href="{{ route('admin.commerce.orders.show', $gapOrder) }}" class="text-brand-700 font-mono text-xs">{{ $gapOrder->order_no }}</a></td>
                <td class="px-3 py-2 text-gray-700">{{ $gapOrder->customer->display_name ?? '—' }}</td>
                <td class="px-3 py-2 text-xs text-gray-600">{{ $gapOrder->paid_at?->format('d M Y H:i') }}</td>
                <td class="px-3 py-2 text-right">₹{{ \App\Modules\Shared\Support\IndianNumber::format($gapOrder->total_paise / 100, 2) }}</td>
                <td class="px-3 py-2 text-right">
                    @can('finance.record')
                    <form method="POST" action="{{ route('admin.payments.invoices.generate', $gapOrder) }}" class="inline"
                          data-confirm="Issue the GST invoice for this order now?" data-confirm-title="Issue invoice"
                          data-confirm-impact="Impact: allocates the next consecutive invoice number and dates the invoice today. Audit-logged against your user.">
                        @csrf<button type="submit" class="text-sm text-brand-700 hover:underline">Issue invoice</button>
                    </form>
                    @endcan
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

@php
    $tabs = ['' => 'All', 'created' => 'Awaiting payment', 'captured' => 'Captured', 'failed' => 'Failed', 'cancelled' => 'Cancelled / expired'];
@endphp
<form method="GET" class="flex flex-wrap items-center gap-2 mb-5">
    @foreach($tabs as $val => $label)
    <a href="{{ route('admin.payments.index', array_filter(['status' => $val, 'gateway' => $filters['gateway'], 'q' => $filters['q']])) }}"
       class="px-3 py-1.5 rounded-full text-sm font-medium border {{ $filters['status'] === $val ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-gray-700 border-gray-300 hover:border-brand-400' }}">
        {{ $label }}@if($val !== '' && isset($statusCounts[$val]))<span class="ml-1 opacity-75">({{ $statusCounts[$val] }})</span>@endif
    </a>
    @endforeach
    <input type="hidden" name="status" value="{{ $filters['status'] }}">
    <select name="gateway" class="rounded-lg border-gray-300 text-sm">
        <option value="">Any gateway</option>
        <option value="razorpay" @selected($filters['gateway'] === 'razorpay')>Razorpay</option>
        <option value="stub" @selected($filters['gateway'] === 'stub')>Stub (dev)</option>
    </select>
    <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Order no, order_… or pay_…" class="rounded-lg border-gray-300 text-sm w-60">
    <button type="submit" class="px-3 py-1.5 rounded-lg bg-white border border-gray-300 text-sm hover:bg-gray-50">Filter</button>
</form>

<div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Order</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Customer</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Gateway</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Gateway ids</th>
                <th class="text-right px-4 py-3 text-xs font-medium text-gray-600 uppercase">Amount</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Status</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Method</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Created</th>
                <th></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($intents as $intent)
            @php
                $badge = match($intent->status) {
                    'captured' => 'bg-green-50 text-green-700 border-green-200',
                    'created', 'authorised' => 'bg-amber-50 text-amber-700 border-amber-200',
                    'failed' => 'bg-red-50 text-red-700 border-red-200',
                    default => 'bg-gray-50 text-gray-600 border-gray-200',
                };
            @endphp
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3">
                    <a href="{{ route('admin.commerce.orders.show', $intent->order) }}" class="text-brand-700 hover:text-brand-800 font-mono text-xs">{{ $intent->order->order_no }}</a>
                </td>
                <td class="px-4 py-3 text-gray-700">{{ $intent->order->customer->display_name ?? '—' }}</td>
                <td class="px-4 py-3 text-gray-700">{{ ucfirst($intent->gateway) }}@if($intent->mode) <span class="text-xs text-gray-500">({{ $intent->mode }})</span>@endif</td>
                <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ $intent->gateway_order_id ?? '—' }}<br>{{ $intent->gateway_payment_id ?? '' }}</td>
                <td class="px-4 py-3 text-right font-semibold">₹{{ \App\Modules\Shared\Support\IndianNumber::format($intent->amount_paise / 100, 2) }}</td>
                <td class="px-4 py-3"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border {{ $badge }}">{{ ucfirst($intent->status) }}</span>
                    @if($intent->cancel_reason)<span class="block text-xs text-gray-500 mt-0.5">{{ str_replace('_', ' ', $intent->cancel_reason) }}</span>@endif
                    @if($intent->error_code)<span class="block text-xs text-red-600 mt-0.5">{{ $intent->error_code }}</span>@endif
                </td>
                <td class="px-4 py-3 text-gray-700">{{ $intent->method ?? '—' }}</td>
                <td class="px-4 py-3 text-gray-600 text-xs">{{ $intent->created_at->format('d M Y H:i') }}</td>
                <td class="px-4 py-3 text-right"><a href="{{ route('admin.payments.show', $intent) }}" class="text-sm text-brand-700 hover:text-brand-800 font-medium">Timeline →</a></td>
            </tr>
            @empty
            <tr><td colspan="9" class="px-4 py-8 text-center text-sm text-gray-600">No payment intents match.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $intents->links() }}</div>

@endsection
