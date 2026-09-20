@php
    use App\Modules\Shared\Support\IndianNumber;

    // `inventory.manage` decides the cell count, and the count decides the
    // grid: four cells sit four-up, seven sit seven-up on a wide screen rather
    // than leaving three empty columns on a second line.
    $manages = auth()->user()?->can('inventory.manage') ?? false;
@endphp
<x-ui.card flush :title="$panelTitle">
    <x-slot:actions><x-ui.panel-actions :generated-at="$generated_at" :title="$panelTitle" /></x-slot:actions>

    <x-ui.stat-row :columns="$manages ? 7 : 4">
        <x-ui.stat flush :label-lines="2" label="Stock value"
                   :value="IndianNumber::rupees($stock_value_paise)"
                   :href="route('admin.inventory.reports.valuation')" />
        <x-ui.stat flush :label-lines="2" label="Low stock"
                   :value="IndianNumber::format($low_stock)"
                   :href="route('admin.inventory.reports.low-stock')" />
        <x-ui.stat flush :label-lines="2" :label="'Expiring ≤'.$expiry_days.'d'"
                   :value="IndianNumber::format($expiring)"
                   :href="route('admin.inventory.reports.batch-expiry')" />
        <x-ui.stat flush :label-lines="2" label="Expired"
                   :value="IndianNumber::format($expired)"
                   :href="route('admin.inventory.reports.batch-expiry')" />
        {{-- The panel is gated on `inventory.view`, but warehouses, purchase
             orders and transfers all live behind `inventory.manage`, which
             admin-finance does not hold. Without this gate finance would read
             three counts it cannot otherwise reach, on three cells that 403
             when clicked. The figures are computed either way — the payload is
             cached once for every viewer of the panel — so the gate belongs
             here, where the HTML is emitted. --}}
        @if($manages)
            <x-ui.stat flush :label-lines="2" label="Active warehouses"
                       :value="IndianNumber::format($warehouses)"
                       :href="route('admin.inventory.warehouses.index')" />
            <x-ui.stat flush :label-lines="2" label="Open purchase orders"
                       :value="IndianNumber::format($open_purchase_orders)"
                       :href="route('admin.inventory.purchase-orders.index')" />
            <x-ui.stat flush :label-lines="2" label="Transfers in transit"
                       :value="IndianNumber::format($transfers_in_transit)"
                       :href="route('admin.inventory.transfers.index')" />
        @endif
    </x-ui.stat-row>
</x-ui.card>
