<x-ui.card flush :title="$panelTitle">
    <x-slot:actions>
        <span class="text-[11px] tabular-nums text-gray-500">as of {{ $generated_at->format('H:i') }}</span>
        <button type="button" data-panel-refresh
                class="inline-flex items-center rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700"
                aria-label="Refresh {{ $panelTitle }}">
            {{ svg('lucide-refresh-cw', 'w-3.5 h-3.5') }}
        </button>
    </x-slot:actions>

    <div class="p-5 space-y-5">
        <a href="{{ route('admin.inventory.reports.valuation') }}"
           class="block rounded-xl border border-gray-200 p-4 transition-colors hover:bg-gray-50">
            <p class="text-[13px] font-medium text-gray-600">Stock value</p>
            <p class="mt-2.5 text-[22px] font-semibold leading-none tracking-tight tabular-nums text-gray-900">
                {{ \App\Modules\Shared\Support\IndianNumber::rupees($stock_value_paise) }}
            </p>
        </a>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <x-ui.stat :label-lines="2" label="Low stock"
                       :value="\App\Modules\Shared\Support\IndianNumber::format($low_stock)"
                       :href="route('admin.inventory.reports.low-stock')" />
            <x-ui.stat :label-lines="2" :label="'Expiring ≤'.$expiry_days.'d'"
                       :value="\App\Modules\Shared\Support\IndianNumber::format($expiring)"
                       :href="route('admin.inventory.reports.batch-expiry')" />
            <x-ui.stat :label-lines="2" label="Expired"
                       :value="\App\Modules\Shared\Support\IndianNumber::format($expired)"
                       :href="route('admin.inventory.reports.batch-expiry')" />
            {{-- The panel is gated on `inventory.view`, but warehouses, purchase
                 orders and transfers all live behind `inventory.manage`, which
                 admin-finance does not hold. Without this gate finance would read
                 three counts it cannot otherwise reach, on three tiles that 403
                 when clicked. The figures are computed either way — the payload is
                 cached once for every viewer of the panel — so the gate belongs
                 here, where the HTML is emitted. --}}
            @can('inventory.manage')
                <x-ui.stat :label-lines="2" label="Active warehouses"
                           :value="\App\Modules\Shared\Support\IndianNumber::format($warehouses)"
                           :href="route('admin.inventory.warehouses.index')" />
                <x-ui.stat :label-lines="2" label="Open purchase orders"
                           :value="\App\Modules\Shared\Support\IndianNumber::format($open_purchase_orders)"
                           :href="route('admin.inventory.purchase-orders.index')" />
                <x-ui.stat :label-lines="2" label="Transfers in transit"
                           :value="\App\Modules\Shared\Support\IndianNumber::format($transfers_in_transit)"
                           :href="route('admin.inventory.transfers.index')" />
            @endcan
        </div>
    </div>
</x-ui.card>
