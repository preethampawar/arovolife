@extends('admin.layouts.admin')
@section('title', 'Payments')
@section('heading', 'Payments')

@section('content')

<p class="text-sm text-gray-600 mb-4">Every online payment attempt, what the gateway said about it, and where it stands. Nothing here is marked paid by hand: <em>Sync</em> asks Razorpay and confirms only from its answer.</p>

@if(session('status'))<div class="mb-4 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800">{{ session('status') }}</div>@endif
@error('invoice')<div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ $message }}</div>@enderror

@if($attention > 0)
<a href="{{ route('admin.payments.refunds') }}" class="block mb-5 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 hover:bg-amber-100">
    <strong>{{ $attention }}</strong> refund{{ $attention === 1 ? '' : 's' }} need{{ $attention === 1 ? 's' : '' }} attention — failed at the gateway, held more than {{ \App\Modules\Payments\Support\RefundWorklist::ALERT_AFTER_DAYS }} days without the return being received, or owed on an order with no gateway payment to refund against. Open the unsettled refunds worklist {{ svg('lucide-chevron-right', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }}
</a>
@else
<a href="{{ route('admin.payments.refunds') }}" class="inline-block mb-5 text-sm text-brand-700 hover:underline">Unsettled refunds worklist {{ svg('lucide-chevron-right', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }}</a>
@endif

@if($pendingOfflineCount > 0)
@can('finance.record')
<a href="{{ route('admin.commerce.orders.index', ['status' => 'placed', 'payment_method' => 'offline', 'placed_from' => '', 'placed_to' => '']) }}"
   class="block mb-5 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 hover:bg-amber-100">
    <strong>{{ $pendingOfflineCount }}</strong> offline payment{{ $pendingOfflineCount === 1 ? '' : 's' }} awaiting your confirmation — money taken at the office or the bank, recorded by staff, not yet on the books. Open them {{ svg('lucide-chevron-right', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }}
</a>
@endcan
@endif

@if($invoiceGapCount > 0)
<div class="mb-6 rounded-2xl border border-red-300 bg-red-50 p-4 text-sm text-red-900">
    <p class="font-semibold mb-2"><strong>{{ $invoiceGapCount }}</strong> paid order{{ $invoiceGapCount === 1 ? '' : 's' }} without a GST invoice</p>
    <p class="text-xs text-red-800 mb-3">The payment was confirmed but the invoice failed to generate. A tax invoice must be issued for every supply (CGST §31); issue it here — the next consecutive number is allocated, never a duplicate.</p>
    <div class="overflow-x-auto">
    <table class="w-full text-sm bg-white rounded-lg border border-red-200">
        <thead><tr class="text-left text-xs text-gray-600 uppercase border-b border-red-200"><th class="px-3 py-2 w-12">S.No.</th><th class="px-3 py-2">Order</th><th class="px-3 py-2">Customer</th><th class="px-3 py-2">Paid</th><th class="px-3 py-2 text-right">Total</th><th></th></tr></thead>
        <tbody class="divide-y divide-red-100">
        @foreach($invoiceGaps as $gapOrder)
            <tr>
                <td class="px-3 py-2 text-gray-500 tabular-nums">{{ $loop->iteration }}</td>
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
</div>
@endif

{{-- Status facets: the same `status` key the toolbar's select drives, so a
     chip, the dropdown and the Clear link all stay in agreement. --}}
@php
    $statusFacets = [
        '' => 'All',
        \App\Modules\Payments\Models\PaymentIntent::STATUS_CREATED => 'Awaiting payment',
        \App\Modules\Payments\Models\PaymentIntent::STATUS_CAPTURED => 'Captured',
        \App\Modules\Payments\Models\PaymentIntent::STATUS_FAILED => 'Failed',
        \App\Modules\Payments\Models\PaymentIntent::STATUS_CANCELLED => 'Cancelled / expired',
    ];
    $activeStatus = $filters->value('status') ?? '';
@endphp
@if($defaultedToMonth)
<p class="mb-4 text-xs text-gray-600">
    Showing payments created this month.
    <a href="{{ request()->fullUrlWithQuery(['created_from' => '', 'created_to' => '', 'page' => null]) }}"
       class="font-medium text-brand-700 hover:text-brand-800 hover:underline">Show all dates</a>
</p>
@endif

<div class="mb-4 flex flex-wrap items-center gap-2">
    @foreach($statusFacets as $value => $label)
        <a href="{{ request()->fullUrlWithQuery(['status' => $value === '' ? null : $value, 'page' => null]) }}"
           class="px-3 py-1.5 rounded-full text-sm font-medium border transition-colors {{ $activeStatus === (string) $value ? 'bg-brand-700 text-white border-brand-700' : 'bg-white text-gray-700 border-gray-300 hover:border-brand-400' }}">
            {{ $label }}@if($value !== '' && isset($statusCounts[$value]))<span class="ml-1 opacity-75">({{ $statusCounts[$value] }})</span>@endif
        </a>
    @endforeach
</div>

<x-filter-bar :filters="$filters" />

{{-- Where the money in the filtered set stands. Captured, awaiting and failed
     are the whole of what was attempted, split by what the gateway has
     actually said; refunds are money going back out, so they are counted
     beside that total and never netted off it. --}}
<x-ui.card flush class="mb-6">
    <x-ui.stat-row :columns="5">
        <x-ui.stat flush :label-lines="2" label="Payments"
                   :value="\App\Modules\Shared\Support\IndianNumber::format($summary['payments'])"
                   :hint="$defaultedToMonth ? 'Attempts created this month' : 'Attempts matching these filters'" />
        <x-ui.stat flush :label-lines="2" label="Captured"
                   :value="\App\Modules\Shared\Support\IndianNumber::rupees($summary['captured_paise'])"
                   hint="Confirmed by the gateway" />
        <x-ui.stat flush :label-lines="2" label="Awaiting payment"
                   :value="\App\Modules\Shared\Support\IndianNumber::rupees($summary['awaiting_paise'])"
                   hint="Created or authorised, not captured" />
        <x-ui.stat flush :label-lines="2" label="Failed / expired"
                   :value="\App\Modules\Shared\Support\IndianNumber::rupees($summary['failed_paise'])"
                   hint="Money that never arrived" />
        <x-ui.stat flush :label-lines="2" label="Refunded"
                   :value="\App\Modules\Shared\Support\IndianNumber::rupees($summary['refunded_paise'])"
                   hint="Processed refunds on these payments" />
    </x-ui.stat-row>
</x-ui.card>

<x-ui.card flush>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase w-12">S.No.</th>
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
                <td class="px-4 py-3 text-gray-500 tabular-nums">{{ $intents->firstItem() + $loop->index }}</td>
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
                <td class="px-4 py-3 text-right"><a href="{{ route('admin.payments.show', $intent) }}" class="text-sm text-brand-700 hover:text-brand-800 font-medium">Timeline {{ svg('lucide-chevron-right', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }}</a></td>
            </tr>
            @empty
            <x-ui.empty-state colspan="10" title="No payment intents match." />
            @endforelse
        </tbody>
    </table>
    </div>
</x-ui.card>
<div class="mt-4">{{ $intents->links() }}</div>

@endsection
