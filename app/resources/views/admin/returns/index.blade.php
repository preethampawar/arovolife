@extends('admin.layouts.admin')
@section('title', 'Returns & Refunds')
@section('heading', 'Returns & Refunds')

@section('content')

{{-- Status filter tabs --}}
@php
    $tabs = [
        '' => 'All',
        'opened' => 'Pending review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];
    $current = request('status', '');
@endphp
<div class="flex gap-2 flex-wrap mb-5">
    @foreach($tabs as $val => $label)
    <a href="{{ route('admin.returns.index', $val ? ['status' => $val] : []) }}"
       class="px-3 py-1.5 rounded-full text-sm font-medium border
              {{ $current === $val
                  ? 'bg-brand-600 text-white border-brand-600'
                  : 'bg-white text-gray-700 border-gray-300 hover:border-brand-400' }}">
        {{ $label }}
        @if(isset($statusCounts[$val]))<span class="ml-1 opacity-75">({{ $statusCounts[$val] }})</span>@endif
    </a>
    @endforeach
</div>

@if(session('status'))
<div class="mb-5 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-700">{{ session('status') }}</div>
@endif

<div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">RMA</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Order</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Customer</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Reason</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Status</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Opened</th>
                <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase">Net Refund</th>
                <th></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($returns as $rtn)
            @php
                $statusBadge = match($rtn->status) {
                    'opened'   => 'bg-amber-50 text-amber-700 border-amber-200',
                    'approved' => 'bg-green-50 text-green-700 border-green-200',
                    'rejected' => 'bg-red-50 text-red-700 border-red-200',
                    default    => 'bg-gray-50 text-gray-600 border-gray-200',
                };
                $reasonLabel = match($rtn->reason) {
                    'cooling_off'          => 'Cooling-off',
                    'damage'               => 'Damage',
                    'dissatisfaction'      => 'Dissatisfaction',
                    'general_buyback'      => 'General buyback',
                    'termination_buyback'  => 'Termination buyback',
                    default                => $rtn->reason,
                };
            @endphp
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-mono text-xs">{{ $rtn->rma_no }}</td>
                <td class="px-4 py-3">
                    <a href="{{ route('admin.commerce.orders.show', $rtn->order) }}"
                       class="text-brand-600 hover:text-brand-700 font-mono text-xs">{{ $rtn->order->order_no }}</a>
                </td>
                <td class="px-4 py-3 text-gray-700">{{ $rtn->order->customer->display_name ?? '—' }}</td>
                <td class="px-4 py-3 text-gray-700">{{ $reasonLabel }}</td>
                <td class="px-4 py-3">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border {{ $statusBadge }}">
                        {{ ucfirst($rtn->status) }}
                    </span>
                </td>
                <td class="px-4 py-3 text-gray-500 text-xs">{{ $rtn->created_at->format('d M Y H:i') }}</td>
                <td class="px-4 py-3 text-right font-semibold">
                    @if($rtn->buybackDecision)
                    ₹{{ number_format($rtn->buybackDecision->net_refund_paise / 100, 2) }}
                    @else
                    <span class="text-gray-400">—</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-right">
                    <a href="{{ route('admin.returns.show', $rtn) }}"
                       class="text-sm text-brand-600 hover:text-brand-700 font-medium">Review →</a>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-500">No return requests found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($returns->hasPages())
<div class="mt-4">{{ $returns->links() }}</div>
@endif

@endsection
