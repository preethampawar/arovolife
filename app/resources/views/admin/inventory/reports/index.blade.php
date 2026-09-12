@extends('admin.layouts.admin')
@section('title', 'Inventory Reports')
@section('heading', 'Inventory reports')

@section('content')
@php
    $reports = [
        ['route' => 'admin.inventory.reports.stock-on-hand', 'label' => 'Stock on hand', 'hint' => 'On hand, reserved, available, value and status per variant × warehouse.'],
        ['route' => 'admin.inventory.reports.movements', 'label' => 'Stock movement ledger', 'hint' => 'Every movement with type, quantity, batch and reference.'],
        ['route' => 'admin.inventory.reports.batch-expiry', 'label' => 'Batch & expiry', 'hint' => 'Days to expiry, expired / ≤30 / ≤90 / OK buckets, value at risk.'],
        ['route' => 'admin.inventory.reports.low-stock', 'label' => 'Low stock', 'hint' => 'Levels at or under reorder, with a suggested reorder quantity.'],
        ['route' => 'admin.inventory.reports.valuation', 'label' => 'Stock valuation', 'hint' => 'Value per warehouse and category, FIFO by batch cost.'],
        ['route' => 'admin.inventory.reports.purchase-register', 'label' => 'Purchase register', 'hint' => 'Posted GRNs by supplier with taxable, GST and total (GSTR-2 helper).'],
        ['route' => 'admin.inventory.reports.transfer-register', 'label' => 'Transfer register', 'hint' => 'Transfers with status and in-transit quantity.'],
        ['route' => 'admin.inventory.reports.order-fulfilment', 'label' => 'Order fulfilment', 'hint' => 'Orders by stage with age — the order tracking report.'],
        ['route' => 'admin.inventory.reports.returns-restock', 'label' => 'Returns & restock', 'hint' => 'Return requests with inspection condition and restock status.'],
        ['route' => 'admin.inventory.reports.stock-in-out', 'label' => 'Stock in / out summary', 'hint' => 'Opening, movements by type and closing per variant × warehouse.'],
    ];
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    @foreach($reports as $report)
    <a href="{{ route($report['route']) }}"
       class="block bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow-md hover:border-gray-300 transition-all">
        <p class="text-sm font-semibold text-gray-900 mb-1">{{ $report['label'] }}</p>
        <p class="text-xs text-gray-600">{{ $report['hint'] }}</p>
    </a>
    @endforeach
</div>
@endsection
